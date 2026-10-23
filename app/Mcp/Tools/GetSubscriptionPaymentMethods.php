<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetSubscriptionPaymentMethods extends SupportTool
{
    protected string $name = 'get_subscription_payment_methods';

    protected string $description = 'Get current active subscription payment accounts and exact recipient details. Subscription payments only, not shop order payment methods.';
}
