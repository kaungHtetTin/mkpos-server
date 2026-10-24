<?php

namespace App\Mcp;

class SupportToolDefinitions
{
    public static function all(): array
    {
        $string = fn (int $max = 200, bool $required = false) => ['type' => 'string', 'max' => $max, 'required' => $required];
        $id = ['type' => 'integer', 'min' => 1, 'required' => true];
        $page = ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 20], 'offset' => ['type' => 'integer', 'min' => 0]];
        $definitions = [
            'get_product_import_template' => ['public', false, ['price_types' => $string(1000)]],
            'validate_product_import' => ['public', false, [
                'csv_content' => $string(5242880, true), 'filename' => $string(255), 'price_types' => $string(1000),
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 100],
                'offset' => ['type' => 'integer', 'min' => 0], 'issues_only' => ['type' => 'boolean'],
            ]],
            'get_form_field_guide' => ['public', false, ['form' => $string(80), 'field' => $string(200),
                'mode' => ['type' => 'string', 'enum' => ['create', 'edit']]]],
            'search_help_articles' => ['public', false, ['query' => $string(), ...$page]],
            'get_help_article' => ['public', false, ['article_id' => $string(80, true)]],
            'get_troubleshooting_guide' => ['public', false, ['topic' => $string(80, true)]],
            'get_supported_devices' => ['public', false, []],
            'get_latest_app_versions' => ['public', false, []],
            'get_service_status' => ['public', false, []],
            'get_support_contacts' => ['public', false, []],
            'get_subscription_payment_methods' => ['public', false, []],
            'get_my_subscription_status' => ['account', false, []],
            'list_my_subscription_requests' => ['account', false, $page],
            'get_my_subscription_request' => ['account', false, ['request_id' => $id]],
            'get_my_billing_history' => ['account', false, $page],
            'submit_subscription_payment_proof' => ['account', true, [
                'subscription_plan_id' => $id, 'payment_method_id' => $id,
                'upload_id' => ['type' => 'string', 'uuid' => true, 'required' => true], 'message' => $string(1000),
            ]],
            'get_my_profile' => ['account', false, []],
            'update_my_profile' => ['account', true, [
                'name' => $string(255, true), 'phone' => $string(80), 'address' => $string(2000),
            ]],
            'request_refund' => ['account', true, [
                'payment_id' => $id, 'amount' => $id, 'reason' => $string(2000, true),
            ]],
            'get_my_refund_status' => ['account', false, ['refund_id' => $id]],
            'approve_subscription_payment' => ['admin', true, [
                'request_id' => $id, 'price_paid' => ['type' => 'integer', 'min' => 0, 'required' => true],
                'admin_note' => $string(2000, true),
                'funds_verified' => ['type' => 'boolean', 'required' => true, 'accepted' => true],
            ]],
            'reject_subscription_payment' => ['admin', true, ['request_id' => $id, 'admin_note' => [...$string(2000, true), 'min' => 5]]],
            'get_pending_refund_requests' => ['admin', false, $page],
            'process_refund_request' => ['admin', true, [
                'refund_id' => $id,
                'action' => ['type' => 'string', 'enum' => ['approve', 'reject', 'record_transfer'], 'required' => true],
                'review_note' => [...$string(2000, true), 'min' => 5],
                'transfer_reference' => $string(255),
                'transferred_at' => ['type' => 'string', 'date' => true],
            ]],
        ];
        foreach ($definitions as &$definition) {
            $definition[2] = ['language' => ['type' => 'string', 'enum' => ['en', 'my']], ...$definition[2]];
            if ($definition[0] === 'public') {
                $definition[2]['app'] = ['type' => 'string', 'enum' => ['business_web', 'business_mobile', 'windows', 'shop']];
            }
            if ($definition[1]) {
                $definition[2]['confirmed'] = ['type' => 'boolean', 'required' => true, 'accepted' => true];
                $definition[2]['idempotency_key'] = ['type' => 'string', 'uuid' => true, 'required' => true];
            }
        }

        return $definitions;
    }
}
