<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierCreditApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_directory_name_sort_is_stable_and_whitelisted(): void
    {
        $this->registerOwner();
        $alpha = $this->postJson('/api/suppliers', ['name' => 'Alpha'])->assertOk()->json();
        $zulu = $this->postJson('/api/suppliers', ['name' => 'Zulu'])->assertOk()->json();
        $this->getJson('/api/suppliers?with_total=true&sort=name_desc')->assertOk()->assertJsonPath('items.0.id', $zulu['id']);
        $this->getJson('/api/suppliers?with_total=true&sort=unknown')->assertOk()->assertJsonPath('items.0.id', $alpha['id']);
        $this->getJson('/api/suppliers?with_total=true&q=Alpha&sort=name_desc&account_status=settled')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('items.0.id', $alpha['id']);
    }

    public function test_partial_credit_purchase_payments_refunds_and_reports_share_one_supplier_balance(): void
    {
        $this->registerOwner();
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Credit Supplier'])->assertOk()->json();
        $product = $this->postJson('/api/products', [
            'name' => 'Credit Product', 'sku' => 'CREDIT-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 15000, 'cost' => 10000, 'stock' => 0, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 15000]],
        ])->assertOk()->json();

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier['id'], 'payment_method' => 'Banking Pay', 'paid_amount' => 4000,
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'foc_quantity' => 0, 'unit_cost' => 10000]],
        ])->assertOk()
            ->assertJsonPath('payment_type', 'credit')
            ->assertJsonPath('paid_amount', 4000)
            ->assertJsonPath('credit_amount', 6000)
            ->json();

        $this->getJson('/api/suppliers?with_total=true')->assertOk()
            ->assertJsonPath('items.0.balance', 6000)
            ->assertJsonPath('account_summary.payable_total', 6000);

        $this->getJson('/api/suppliers?with_total=true&account_status=payable&purchase_activity=with_purchases')->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.id', $supplier['id']);
        $this->getJson('/api/suppliers?with_total=true&account_status=settled')->assertOk()
            ->assertJsonPath('total', 0);

        $payment = $this->postJson('/api/suppliers/'.$supplier['id'].'/payments', [
            'direction' => 'shop_to_supplier', 'amount' => 2000, 'payment_method' => 'Cash', 'note' => 'Part payment',
        ])->assertOk()->assertJsonPath('balance', 4000)->json();

        $this->postJson('/api/suppliers/'.$supplier['id'].'/payments', [
            'direction' => 'supplier_to_shop', 'amount' => 500, 'payment_method' => 'Cash', 'note' => 'Returned overcharge',
        ])->assertOk()->assertJsonPath('balance', 4500);

        $this->getJson('/api/purchases?with_total=true&with_current_balance=true&limit=1')
            ->assertOk()->assertJsonPath('current_payable_total', 4500)
            ->assertJsonPath('items.0.credit_amount', 6000);
        // History filters must not hide a current debt from an earlier purchase.
        $this->getJson('/api/purchases?with_total=true&with_current_balance=true&date_to=2000-01-01')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('current_payable_total', 4500);
        $this->getJson('/api/purchases?with_total=true')->assertOk()->assertJsonMissingPath('current_payable_total');

        $this->getJson('/api/suppliers/'.$supplier['id'])->assertOk()
            ->assertJsonPath('summary.credit_purchase_total', 6000)
            ->assertJsonPath('summary.paid_total', 2000)
            ->assertJsonPath('summary.refund_total', 500)
            ->assertJsonPath('summary.balance', 4500)
            ->assertJsonCount(2, 'payments');

        $this->getJson('/api/supplier-payments?with_total=true&supplier_id='.$supplier['id'])->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('items.0.supplier_name', 'Credit Supplier');

        $this->putJson('/api/supplier-payments/'.$payment['id'], [
            'supplier_id' => $supplier['id'], 'direction' => 'shop_to_supplier', 'amount' => 2500,
            'payment_method' => 'Cash', 'note' => 'Corrected part payment',
        ])->assertOk()->assertJsonPath('balance', 4000);

        $this->getJson('/api/purchases?with_total=true&with_current_balance=true&offset=100')
            ->assertOk()->assertJsonPath('current_payable_total', 4000)->assertJsonCount(0, 'items');

        // An advance to another supplier cannot cancel money owed to this one.
        $other = $this->postJson('/api/suppliers', ['name' => 'Prepaid Supplier'])->assertOk()->json();
        $this->postJson('/api/suppliers/'.$other['id'].'/payments', [
            'direction' => 'shop_to_supplier', 'amount' => 9000,
        ])->assertOk();
        $this->getJson('/api/purchases?with_total=true&with_current_balance=true')
            ->assertOk()->assertJsonPath('current_payable_total', 4000);

        $this->getJson('/api/reports/summary?all_time=true')->assertOk()
            ->assertJsonPath('purchase_total', 10000)
            ->assertJsonPath('purchase_paid_total', 4000)
            ->assertJsonPath('purchase_credit_total', 6000)
            ->assertJsonPath('current_accounts.supplier_payable_total', 4000)
            ->assertJsonPath('current_accounts.suppliers_owed', 1);

        $this->deleteJson('/api/supplier-payments/'.$payment['id'])->assertOk();
        $this->getJson('/api/suppliers/'.$supplier['id'])->assertOk()->assertJsonPath('summary.balance', 6500);
        $this->getJson('/api/purchases?with_total=true&with_current_balance=true')
            ->assertOk()->assertJsonPath('current_payable_total', 6500);
        $this->postJson('/api/suppliers/'.$supplier['id'].'/payments', [
            'direction' => 'shop_to_supplier', 'amount' => 6500,
        ])->assertOk();
        $this->getJson('/api/purchases?with_total=true&with_current_balance=true')
            ->assertOk()->assertJsonPath('current_payable_total', 0);
        $this->assertDatabaseHas('purchases', ['id' => $purchase['id'], 'credit_amount' => 6000]);
    }

    public function test_old_style_purchase_payload_is_fully_paid_and_credit_requires_a_saved_supplier(): void
    {
        $this->registerOwner();
        $product = $this->postJson('/api/products', [
            'name' => 'Paid Product', 'sku' => 'PAID-1', 'barcode' => '', 'category' => 'Tests',
            'price' => 2000, 'cost' => 1000, 'stock' => 0, 'low_stock_threshold' => 1,
            'prices' => [['name' => 'Retail', 'price' => 2000]],
        ])->assertOk()->json();
        $items = [['product_id' => $product['id'], 'quantity' => 2, 'foc_quantity' => 0, 'unit_cost' => 1000]];

        $this->postJson('/api/purchases', ['supplier_name' => 'Legacy Supplier', 'items' => $items])
            ->assertOk()->assertJsonPath('payment_type', 'cash')->assertJsonPath('paid_amount', 2000)->assertJsonPath('credit_amount', 0);

        $this->postJson('/api/purchases', ['supplier_name' => 'Unsaved Supplier', 'paid_amount' => 500, 'items' => $items])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    private function registerOwner(): void
    {
        $this->withHeader('Origin', 'http://localhost')->postJson('/api/auth/register', [
            'business_name' => 'Supplier Credit '.Str::uuid(), 'owner_name' => 'Credit Owner',
            'email' => 'supplier-credit-'.Str::uuid().'@example.com', 'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();
    }
}
