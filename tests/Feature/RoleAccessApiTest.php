<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleAccessApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sales_history_requires_its_own_page_permission(): void
    {
        $ownerSession = $this->registerBusiness('Sales Permission Shop', 'sales-permission-owner@example.com');
        $businessId = $ownerSession['business']['id'];
        $this->activateSubscription($businessId);

        $this->getJson('/api/roles')
            ->assertOk()
            ->assertJsonFragment(['id' => 'sales', 'label' => 'Sales']);

        $role = $this->postJson('/api/roles', [
            'name' => 'Transaction Clerk',
            'permissions' => ['sell', 'transactions'],
        ])->assertCreated()->json();

        $this->postJson('/api/staff', [
            'name' => 'Sales Permission Staff',
            'email' => 'sales-permission-staff@example.com',
            'access_role_id' => $role['id'],
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'sales-permission-staff@example.com', 'password' => 'password123',
        ])->assertOk()->assertJsonPath('permissions', ['sell', 'transactions']);
        $this->getJson('/api/sales')->assertForbidden();
        $this->getJson('/api/customer-payments')->assertOk();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'sales-permission-owner@example.com', 'password' => 'password123',
        ])->assertOk();
        $this->putJson('/api/roles/'.$role['id'], [
            'name' => 'Transaction Clerk',
            'permissions' => ['sell', 'transactions', 'sales'],
        ])->assertOk()->assertJsonPath('permissions', ['sell', 'transactions', 'sales']);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'sales-permission-staff@example.com', 'password' => 'password123',
        ])->assertOk()->assertJsonPath('permissions', ['sell', 'transactions', 'sales']);
        $this->getJson('/api/sales')->assertOk();
    }

    public function test_owner_can_manage_roles_and_staff_and_staff_is_limited_to_assigned_pages(): void
    {
        $ownerSession = $this->registerBusiness('Role Shop', 'role-owner@example.com');
        $businessId = $ownerSession['business']['id'];
        $this->activateSubscription($businessId);

        $role = $this->postJson('/api/roles', [
            'name' => 'Sales Clerk',
            'permissions' => ['sell', 'products'],
        ])->assertCreated()->assertJsonPath('name', 'Sales Clerk')->json();

        $staff = $this->postJson('/api/staff', [
            'name' => 'Staff One',
            'email' => 'staff-one@example.com',
            'access_role_id' => $role['id'],
            'is_active' => true,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()
            ->assertJsonPath('access_role.name', 'Sales Clerk')
            ->assertJsonPath('is_active', true)
            ->json();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'staff-one@example.com', 'password' => 'password123',
        ])->assertOk()
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('role_name', 'Sales Clerk')
            ->assertJsonPath('permissions', ['sell', 'products']);

        $this->getJson('/api/products')->assertOk();
        $this->getJson('/api/purchases')->assertForbidden();
        $this->getJson('/api/roles')->assertForbidden();
        $this->getJson('/api/settings')->assertOk();
        $this->getJson('/api/settings/printers')->assertOk();
        $this->postJson('/api/settings/receipt-preview', [])->assertOk();
        $this->putJson('/api/settings', [
            'shop_name' => 'Staff Must Not Rename Shop',
            'shop_title' => 'Counter receipt',
            'language' => 'my',
            'payment_methods' => 'Staff Only Method',
            'receipt_footer' => 'Thank you from this counter',
            'receipt_show_customer' => '1',
            'receipt_show_payment_method' => '1',
            'receipt_show_price_type' => '1',
            'receipt_paper_size' => '80mm',
            'receipt_header_alignment' => 'center',
            'receipt_margin_left' => 3,
            'receipt_margin_right' => 3,
            'receipt_header_font_size' => 11,
            'receipt_body_font_size' => 8.2,
            'receipt_line_height' => 1.45,
            'printer_name' => 'Counter Printer',
            'barcode_paper_size' => 'a5',
            'barcode_columns' => 2,
            'barcode_label_format' => 'custom_sheet',
            'barcode_page_width_mm' => 100,
            'barcode_page_height_mm' => 150,
            'barcode_label_width_mm' => 30,
            'barcode_label_height_mm' => 32,
            'barcode_margin_x_mm' => 4,
            'barcode_margin_y_mm' => 5,
            'barcode_gap_x_mm' => 2,
            'barcode_gap_y_mm' => 3,
            'barcode_show_name' => '0',
            'barcode_show_value' => '1',
            'admin_pin' => '9999',
        ])->assertOk()
            ->assertJsonPath('shop_name', 'Role Shop')
            ->assertJsonPath('language', 'en')
            ->assertJsonPath('receipt_footer', 'Thank you from this counter')
            ->assertJsonPath('printer_name', 'Counter Printer')
            ->assertJsonPath('barcode_paper_size', 'a5')
            ->assertJsonPath('barcode_columns', '2')
            ->assertJsonPath('barcode_label_format', 'custom_sheet')
            ->assertJsonPath('barcode_page_width_mm', '100')
            ->assertJsonPath('barcode_label_width_mm', '30')
            ->assertJsonPath('barcode_label_height_mm', '32')
            ->assertJsonPath('barcode_show_name', '0')
            ->assertJsonPath('admin_pin_set', '0');
        $this->assertDatabaseMissing('settings', [
            'business_id' => $businessId,
            'key' => 'payment_methods',
            'value' => 'Staff Only Method',
        ]);
        $this->getJson('/api/subscription/plans')->assertForbidden();

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'role-owner@example.com', 'password' => 'password123',
        ])->assertOk();

        $this->deleteJson('/api/roles/'.$role['id'])->assertUnprocessable();
        $this->putJson('/api/staff/'.$staff['id'], [
            'name' => 'Staff One',
            'email' => 'staff-one@example.com',
            'access_role_id' => $role['id'],
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'staff-one@example.com', 'password' => 'password123',
        ])->assertUnprocessable();

        $this->postJson('/api/auth/login', [
            'email' => 'role-owner@example.com', 'password' => 'password123',
        ])->assertOk();
        $this->deleteJson('/api/staff/'.$staff['id'])->assertOk();
        $this->deleteJson('/api/roles/'.$role['id'])->assertOk();
    }

    private function registerBusiness(string $name, string $email): array
    {
        $this->withHeader('Origin', 'http://localhost');

        return $this->postJson('/api/auth/register', [
            'business_name' => $name,
            'owner_name' => 'Owner',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated()->json();

        $this->getJson('/api/roles?with_total=true&limit=1&offset=0')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'items');
        $this->getJson('/api/roles/'.$role['id'])->assertOk()->assertJsonPath('name', 'Cashier');
        $this->getJson('/api/staff?with_total=true&limit=1&offset=0')->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'items');
        $this->getJson('/api/staff/'.$staff['id'])->assertOk()->assertJsonPath('email', 'staff-one@example.com');
    }

    private function activateSubscription(int $businessId): void
    {
        $planId = DB::table('subscription_plans')->insertGetId([
            'name' => 'RBAC Test Plan',
            'slug' => 'rbac-test-'.uniqid(),
            'description' => 'Test access',
            'price' => 0,
            'currency' => 'Ks',
            'duration_days' => 30,
            'features' => '[]',
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('business_subscriptions')->insert([
            'business_id' => $businessId,
            'subscription_plan_id' => $planId,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addMonth(),
            'price_paid' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
