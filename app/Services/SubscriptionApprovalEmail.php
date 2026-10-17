<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

class SubscriptionApprovalEmail
{
    public function send(int $eventId, int $businessId, int $adminId, array $snapshot): void
    {
        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'mkpos:subscription-approval:'.$eventId)->toString();
        $reserved = false;
        $attempted = false;
        try {
            // A committed reservation prevents a repeated callback or approval retry
            // from submitting the same email to SMTP twice.
            $owner = DB::transaction(function () use ($eventId, $businessId, $adminId, $snapshot, $key) {
                $event = DB::table('subscription_approval_events')->where('id', $eventId)->where('business_id', $businessId)->lockForUpdate()->first();
                if (!$event || DB::table('business_owner_emails')->where('idempotency_key', $key)->exists()) return null;
                $owners = User::where('business_id', $businessId)->where('role', 'owner')->lockForUpdate()->get();
                if ($owners->count() !== 1 || !filter_var($owners->first()->email, FILTER_VALIDATE_EMAIL)) {
                    Log::warning('Subscription approval email has no unique valid owner.', ['event_id' => $eventId]);
                    return null;
                }
                $owner = $owners->first();
                DB::table('business_owner_emails')->insert([
                    'idempotency_key' => $key, 'owner_user_id' => $owner->id, 'office_admin_id' => $adminId,
                    'payload_hash' => hash('sha256', json_encode([$owner->email, $snapshot])),
                    'status' => 'sending', 'created_at' => now(), 'updated_at' => now(),
                ]);
                return $owner;
            });
            if (!$owner) return;
            $reserved = true;
            $details = $snapshot;
            foreach (['starts_at', 'ends_at', 'approved_at'] as $field) {
                $details[$field] = $snapshot[$field] ? Carbon::parse($snapshot[$field], config('app.timezone', 'UTC'))->setTimezone($snapshot['timezone'])->format('d M Y, H:i T') : 'No end date';
            }
            $mailer = app(SubscriptionApprovalMailer::class);
            $mailer->assertConfigured();
            $attempted = true;
            $mailer->send($owner->email, $owner->name, $details);
            DB::table('business_owner_emails')->where('idempotency_key', $key)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $error) {
            if ($reserved) {
                try { DB::table('business_owner_emails')->where('idempotency_key', $key)->update(['status' => $attempted ? 'unconfirmed' : 'failed', 'updated_at' => now()]); }
                catch (\Throwable $recordError) { /* Keep the sending reservation if recording fails. */ }
            }
            Log::error('Subscription approval email delivery could not be confirmed.', ['event_id' => $eventId, 'exception_type' => get_class($error)]);
        }
    }
}
