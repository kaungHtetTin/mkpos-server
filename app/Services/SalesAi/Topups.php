<?php

namespace App\Services\SalesAi;

use App\Services\PaymentReceiptClaims;
use App\Services\RealtimeActivity;
use App\Services\OwnerPaymentValidation;
use App\Services\SystemPaymentReview;
use App\Support\AppTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Topups
{
    public function submit(object $owner, array $data, string $path, string $hash): object
    {
        $existing = DB::table('sales_ai_topups')->where('business_id', $owner->business_id)->where('request_key', $data['request_key'])->first();
        if ($existing) {
            $this->assertRetry($existing, $data, $hash);
            return $existing;
        }
        $method = DB::table('payment_methods')->where('id', $data['payment_method_id'])->where('is_active', true)->first();
        abort_unless($method, 422, 'Choose an active platform payment account.');
        app(PaymentReceiptClaims::class)->assertAvailable($method->bank, trim($data['transaction_reference'] ?? ''));
        $validator = app(OwnerPaymentValidation::class);
        $validation = $validator->check((int) $owner->id, (int) $owner->business_id, $validator->topupPayment((int) $data['amount']), $method, $path);
        app(Wallet::class)->ensure((int) $owner->business_id);

        return DB::transaction(function () use ($owner, $data, $path, $hash, $validator, $validation) {
            abort_unless(DB::table('businesses')->where('id', $owner->business_id)->lockForUpdate()->first(), 404, 'Business unavailable.');
            $account = DB::table('payment_methods')->where('id', $data['payment_method_id'])->lockForUpdate()->first();
            $existing = DB::table('sales_ai_topups')->where('business_id', $owner->business_id)->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $this->assertRetry($existing, $data, $hash);
                return $existing;
            }
            abort_unless($account && $account->is_active, 422, 'Choose an active platform payment account.');
            $report = $validator->assertTopup($validation, (int) $owner->id, (int) $owner->business_id, (int) $data['amount'], $account, $hash);
            $amount = (int) $data['amount'] * Pricing::SCALE;
            $reference = trim($report['observed']['transaction_reference']);
            $entered = trim($data['transaction_reference'] ?? '');
            if ($entered !== '' && app(SystemPaymentReview::class)->transactionKey($account->bank, $entered) !== app(SystemPaymentReview::class)->transactionKey($account->bank, $reference)) {
                throw ValidationException::withMessages(['transaction_reference' => 'Transaction ID does not match the receipt.']);
            }
            $message = trim($data['message'] ?? '');
            // Share the receipt claim lock with subscription submission and approval.
            DB::table('ai_balance_accounts')->where('id', 1)->lockForUpdate()->first();
            app(PaymentReceiptClaims::class)->assertAvailable($account->bank, $reference);
            if (DB::table('sales_ai_topups')->where('screenshot_hash', $hash)->whereIn('status', ['pending', 'approved'])->exists()) {
                throw ValidationException::withMessages(['payment_screenshot' => 'This payment proof is already submitted. Check your top-up history.']);
            }
            $id = DB::table('sales_ai_topups')->insertGetId(['business_id' => $owner->business_id, 'owner_id' => $owner->id, 'request_key' => $data['request_key'],
                ...\App\Services\BankProviders::columns('sales_ai_topups', $report['observed']['provider'] ?? $account->bank),
                'transaction_key' => app(SystemPaymentReview::class)->transactionKey($account->bank, $reference), 'amount_micros' => $amount, 'payment_method_id' => $account->id, 'payment_account' => json_encode(['bank' => $account->bank, 'account_no' => $account->account_no, 'account_name' => $account->account_name]),
                'screenshot_path' => $path, 'screenshot_hash' => $hash, 'validation_report' => json_encode($report, JSON_THROW_ON_ERROR), 'transaction_reference' => $reference ?: null, 'message' => $message, 'status' => 'pending', 'version' => 1, 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            $row = DB::table('sales_ai_topups')->where('id', $id)->first();
            app(RealtimeActivity::class)->salesAiTopup($row, 'requested');

            return $row;
        });
    }

    private function assertRetry(object $existing, array $data, string $hash): void
    {
        $entered = trim($data['transaction_reference'] ?? '');
        $bank = json_decode($existing->payment_account, true)['bank'];
        abort_unless((int) $existing->amount_micros === (int) $data['amount'] * Pricing::SCALE
            && (int) $existing->payment_method_id === (int) $data['payment_method_id'] && $existing->screenshot_hash === $hash
            && ($entered === '' || app(SystemPaymentReview::class)->transactionKey($bank, $entered) === app(SystemPaymentReview::class)->transactionKey($bank, $existing->transaction_reference ?? ''))
            && $existing->message === trim($data['message'] ?? ''), 409, 'This top-up key belongs to different payment details.');
    }

    public function review(int $id, object $admin, string $decision, array $data): object
    {
        $hint = DB::table('sales_ai_topups')->where('id', $id)->first();
        abort_unless($hint, 404, 'Top-up request unavailable.');

        return DB::transaction(function () use ($id, $admin, $decision, $data, $hint) {
            abort_unless(DB::table('businesses')->where('id', $hint->business_id)->lockForUpdate()->first(), 404, 'Business unavailable.');
            $row = DB::table('sales_ai_topups')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Top-up request unavailable.');
            $reference = trim($data['transaction_reference'] ?? '');
            $note = trim($data['admin_note'] ?? '');
            if ($row->status === $decision && ($decision !== 'approved' || $row->transaction_reference === $reference)) {
                return $row;
            }
            abort_unless($row->status === 'pending' && (int) $row->version === (int) $data['version'], 409, 'This top-up has already changed. Refresh before reviewing.');
            if ($decision === 'approved') {
                $account = json_decode($row->payment_account, true);
                $account['bank'] = $row->payment_provider ?? $account['bank'];
                $observed = json_decode($row->validation_report ?? '{}', true)['observed'] ?? [];
                if (! empty($observed['provider']) && ! empty($observed['transaction_reference'])) {
                    app(PaymentReceiptClaims::class)->assertAvailable($observed['provider'], $observed['transaction_reference'], 'sales_ai', $id);
                }
                $key = app(SystemPaymentReview::class)->transactionKey($account['bank'], $reference);
                app(PaymentReceiptClaims::class)->assertAvailable($account['bank'], $reference, 'sales_ai', $id);
                app(PaymentReceiptClaims::class)->claim($key, 'sales_ai', $id, $account['bank'], $reference);
                app(Wallet::class)->ensure((int) $row->business_id);
                app(Wallet::class)->credit((int) $row->business_id, (int) $row->amount_micros, 'topup:'.$id, 'top_up', 'Approved Sales AI top-up #'.$id, $id, (int) $admin->id);
                DB::table('sales_ai_topups')->where('id', $id)->update(['transaction_reference' => $reference, 'transaction_key' => $key, ...\App\Services\BankProviders::columns('sales_ai_topups', $account['bank'])]);
            }
            DB::table('sales_ai_topups')->where('id', $id)->update(['status' => $decision, 'admin_note' => $note, 'reviewed_by_admin_id' => $admin->id, 'reviewed_at' => now('UTC'), 'updated_at' => now('UTC'), 'version' => $row->version + 1]);

            $reviewed = DB::table('sales_ai_topups')->where('id', $id)->first();
            app(RealtimeActivity::class)->salesAiTopup($reviewed, $decision);

            return $reviewed;
        });
    }

    public function cancel(int $businessId, int $id, int $version): object
    {
        return DB::transaction(function () use ($businessId, $id, $version) {
            abort_unless(DB::table('businesses')->where('id', $businessId)->lockForUpdate()->first(), 404);
            $row = DB::table('sales_ai_topups')->where('business_id', $businessId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404, 'Top-up request unavailable.');
            abort_unless($row->status === 'pending' && (int) $row->version === $version, 409, 'This top-up has already changed. Refresh its details.');
            DB::table('sales_ai_topups')->where('id', $id)->update(['status' => 'cancelled', 'version' => $version + 1, 'updated_at' => now('UTC')]);
            $row = DB::table('sales_ai_topups')->where('id', $id)->first();
            app(RealtimeActivity::class)->salesAiTopup($row, 'cancelled');

            return $row;
        });
    }

    public function present(object $row): array
    {
        return ['id' => (int) $row->id, 'business_id' => (int) $row->business_id, 'business_name' => $row->business_name ?? null,
            'amount' => Pricing::amount((int) $row->amount_micros), 'currency' => 'MMK', 'status' => $row->status, 'payment_account' => json_decode($row->payment_account, true),
            'transaction_reference' => $row->transaction_reference, 'message' => $row->message, 'review_note' => $row->admin_note, 'reviewed_by_name' => $row->reviewed_by_name ?? null,
            'prevalidation' => ! empty($row->validation_report) ? json_decode($row->validation_report, true) : null,
            'version' => (int) $row->version, 'created_at' => AppTime::utc($row->created_at), 'reviewed_at' => AppTime::utc($row->reviewed_at)];
    }
}
