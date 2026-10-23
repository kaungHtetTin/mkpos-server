<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsIdempotent]
class SubmitSubscriptionPaymentProof extends SupportTool
{
    protected string $name = 'submit_subscription_payment_proof';

    protected string $description = 'Submit a new subscription request using a private upload_id from POST /mcp/evidence and the selected active plan/payment method. Replaces prior pending requests using existing rules. Requires customer confirmation and a UUID idempotency_key; does not approve funds.';
}
