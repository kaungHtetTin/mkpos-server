<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleAccessApiTest extends TestCase
{
    use DatabaseTransactions;

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
        $this->putJson('/api/settings', [])->assertForbidden();
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
