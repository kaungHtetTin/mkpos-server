<?php

namespace App\Http\Controllers\Office;

use App\Http\Controllers\Controller;
use App\Models\PlatformCustomer;
use App\Services\PlatformUserMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlatformUserEmailController extends Controller
{
    protected string $emailTable = 'platform_user_emails';
    protected string $recipientColumn = 'platform_customer_id';
    protected string $recipientLabel = 'customer';

    protected function recipientQuery(object $recipient) { return PlatformCustomer::whereKey($recipient->id); }

    public function store(Request $request, PlatformCustomer $platformUser, PlatformUserMailer $mailer)
    {
        return $this->deliver($request, $platformUser, $mailer);
    }

    protected function deliver(Request $request, object $platformUser, PlatformUserMailer $mailer)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:160', 'regex:/\A[^\r\n]+\z/u'],
            'message' => ['required', 'string', 'max:10000'],
            'recipient_email' => ['required', 'email', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $data['subject'] = trim($data['subject']); $data['message'] = trim($data['message']);
        $adminId = (int) $request->user('office')->id;
        $hash = hash('sha256', json_encode([$platformUser->id, $data['recipient_email'], $data['subject'], $data['message']]));
        $query = DB::table($this->emailTable)->where('idempotency_key', $data['idempotency_key']);
        // Keep a committed reservation while SMTP is in flight. A browser retry
        // can observe it without submitting the same email a second time.
        $fresh = DB::transaction(function () use ($query, $data, $platformUser, $adminId, $hash, $mailer) {
            $existing = (clone $query)->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->payload_hash === $hash && (int) $existing->office_admin_id === $adminId, 409, 'This send request belongs to a different message. Close the composer and try again.');
                abort_unless($existing->status === 'sent', 409, 'This email is processing or its delivery could not be confirmed. Check with the recipient before composing another email.');
                return false;
            }
            $recipient = $this->recipientQuery($platformUser)->lockForUpdate()->firstOrFail();
            abort_unless($recipient->email === $data['recipient_email'], 409, 'The '.$this->recipientLabel.' email address changed. Close this composer and refresh the list.');
            $mailer->assertConfigured();
            // The account row serializes simultaneous requests with the same
            // key before insertion; insertOrIgnore also handles cross-user races.
            $inserted = DB::table($this->emailTable)->insertOrIgnore(['idempotency_key' => $data['idempotency_key'], $this->recipientColumn => $platformUser->id, 'office_admin_id' => $adminId, 'payload_hash' => $hash, 'status' => 'sending', 'created_at' => now(), 'updated_at' => now()]);
            abort_unless($inserted, 409, 'This send request is already being processed.');
            return true;
        }, 3);
        if ($fresh) {
            try { $mailer->send($platformUser->email, $platformUser->name, $data['subject'], $data['message']); }
            catch (\Throwable $error) {
                $query->update(['status' => 'unconfirmed', 'updated_at' => now()]);
                Log::error('Office account email delivery could not be confirmed.', ['request_id' => $data['idempotency_key'], 'exception_type' => get_class($error)]);
                return response()->json(['message' => 'Email delivery could not be confirmed. Check with the recipient before composing another email.', 'code' => 'email_delivery_unconfirmed'], 503);
            }
            $query->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        }
        return ['message' => 'Email sent to '.$data['recipient_email'].'.'];
    }
}
