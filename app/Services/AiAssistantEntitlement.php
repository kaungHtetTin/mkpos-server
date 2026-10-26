<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Subscription entitlement is separate from role permissions and system credit. */
class AiAssistantEntitlement
{
    public function forUser(User $user): array
    {
        $status = app(SubscriptionService::class)->status((int) $user->business_id);
        $allowed = ($status['is_valid'] ?? false) && in_array($status['access_type'] ?? null, ['trial', 'paid'], true);

        return ['allowed' => (bool) $allowed, 'access_type' => $status['access_type'] ?? null,
            'reason' => $allowed ? null : ($status['reason'] ?? 'subscription_required'),
            'ends_at' => $status['ends_at'] ?? null,
            'message' => $allowed ? null : 'Wyne Wyne requires an active free trial or valid subscription. Open Billing to renew, or contact your business owner.'];
    }

    public function requireAccess(User $user): void
    {
        $access = $this->forUser($user);
        if (! $access['allowed']) {
            throw new HttpResponseException(response()->json(['message' => $access['message'],
                'code' => 'assistant_subscription_required', 'subscription_access' => $access], 402));
        }
    }
}
