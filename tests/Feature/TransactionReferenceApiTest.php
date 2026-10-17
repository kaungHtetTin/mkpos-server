<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionReferenceApiTest extends TestCase
{
    use DatabaseTransactions;

    private function owner(): void
    {
        $this->withHeader('Origin', 'http://localhost');
        $this->postJson('/api/auth/register', ['business_name' => 'Payment reference', 'owner_name' => 'Owner',
            'email' => Str::uuid().'@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertCreated();
    }

    public function test_customer_filters_sort_and_matching_summary_are_not_limited_to_loaded_rows(): void
    {
        $this->owner();
        $customer = $this->postJson('/api/customers', ['name' => 'Emma'])->assertOk()->json();
        $records = [];
        foreach ([['customer_to_shop', 300, 'Cash'], ['customer_to_shop', 100, 'KPay'], ['shop_to_customer', 50, 'Cash']] as [$direction, $amount, $method]) {
            $records[] = $this->postJson('/api/customers/'.$customer['id'].'/payments', ['direction' => $direction, 'amount' => $amount, 'payment_method' => $method])->assertOk()->json();
        }
        $this->getJson('/api/customer-payments?status=completed&with_total=true&limit=1&sort=amount_desc')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(1, 'items')->assertJsonPath('items.0.amount', 300)
            ->assertJsonPath('summary.incoming', 400)->assertJsonPath('summary.outgoing', 50)
            ->assertJsonPath('summary.incoming_count', 2)->assertJsonPath('summary.outgoing_count', 1);
        $this->getJson('/api/customer-payments?status=completed&with_total=true&direction=customer_to_shop&payment_method=Cash&q=Emma')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('summary.incoming', 300)->assertJsonPath('summary.outgoing', 0);
        $this->getJson('/api/customer-payments?with_total=true&start=2099-01-01')->assertOk()->assertJsonPath('total', 0)->assertJsonPath('summary.incoming', 0);
        $this->getJson('/api/customer-payments/'.$records[0]['id'])->assertOk()->assertJsonPath('customer_name', 'Emma');

        $this->owner();
        $other = $this->postJson('/api/customers', ['name' => 'Other tenant'])->assertOk()->json();
        $this->postJson('/api/customers/'.$other['id'].'/payments', ['direction' => 'customer_to_shop', 'amount' => 900, 'payment_method' => 'Cash'])->assertOk();
        $this->getJson('/api/customer-payments?with_total=true')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.incoming', 900);
    }

    public function test_supplier_summary_filters_sort_and_original_payment_metadata(): void
    {
        $this->owner();
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Golden River'])->assertOk()->json();
        $paid = $this->postJson('/api/suppliers/'.$supplier['id'].'/payments', ['direction' => 'shop_to_supplier', 'amount' => 300, 'payment_method' => 'Cash'])->assertOk()->json();
        $this->postJson('/api/suppliers/'.$supplier['id'].'/payments', ['direction' => 'supplier_to_shop', 'amount' => 100, 'payment_method' => 'KPay'])->assertOk();
        $this->getJson('/api/supplier-payments?status=completed&with_total=true&limit=1&sort=amount_asc')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonPath('items.0.amount', 100)->assertJsonPath('summary.outgoing', 300)->assertJsonPath('summary.incoming', 100);
        $this->getJson('/api/supplier-payments?with_total=true&direction=shop_to_supplier&payment_method=Cash&q=Golden')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('summary.outgoing', 300)->assertJsonPath('summary.incoming', 0);
        $created = DB::table('supplier_payments')->where('id', $paid['id'])->value('created_at');
        $this->putJson('/api/supplier-payments/'.$paid['id'], ['supplier_id' => $supplier['id'], 'direction' => 'shop_to_supplier', 'amount' => 200, 'payment_method' => 'Cash'])->assertOk()->assertJsonPath('supplier_name', 'Golden River');
        $this->assertSame($created, DB::table('supplier_payments')->where('id', $paid['id'])->value('created_at'));
        $this->deleteJson('/api/suppliers/'.$supplier['id'])->assertOk();
        $this->getJson('/api/supplier-payments/'.$paid['id'])->assertOk()->assertJsonPath('supplier_name', 'Golden River');
    }
}
