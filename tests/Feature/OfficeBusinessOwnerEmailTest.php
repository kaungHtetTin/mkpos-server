<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\BusinessOwnerMailer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficeBusinessOwnerEmailTest extends TestCase
{
    use DatabaseTransactions;
    private Business $business;
    private User $owner;
    private PlatformAdmin $admin;
    private $mailer;
    private array $payload;
    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::create(['name' => 'Email Business', 'slug' => Str::uuid(), 'status' => 'active', 'currency' => 'Ks', 'timezone' => 'Asia/Yangon']);
        $this->owner = User::create(['business_id' => $this->business->id, 'name' => 'Business Owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $this->admin = PlatformAdmin::create(['name' => 'Email Admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->payload = ['subject' => 'Subscription update', 'message' => 'Hello business owner.', 'recipient_email' => $this->owner->email, 'idempotency_key' => (string) Str::uuid()];
        $this->mailer = new class extends BusinessOwnerMailer {
            public array $sent = [];
            public bool $fail = false;
            public function assertConfigured(): void {}
            public function send(string $email, string $name, string $subject, string $message): void {
                $this->sent[] = compact('email', 'name', 'subject', 'message');
                if ($this->fail) throw new \RuntimeException('Private SMTP details');
            }
        };
        $this->app->instance(BusinessOwnerMailer::class, $this->mailer);
    }
    private function send(array $extra = [], ?int $business = null) { return $this->postJson('/api/office/businesses/'.($business ?? $this->business->id).'/email', $extra + $this->payload); }

    public function test_requires_office_authentication(): void { $this->send()->assertUnauthorized(); $this->assertCount(0, $this->mailer->sent); }
    public function test_sends_once_to_the_saved_business_owner_and_preserves_other_email_scopes(): void
    {
        $this->actingAs($this->admin, 'office'); $this->send()->assertOk(); $this->send()->assertOk();
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame($this->owner->email, $this->mailer->sent[0]['email']);
        $this->assertDatabaseHas('business_owner_emails', ['owner_user_id' => $this->owner->id, 'office_admin_id' => $this->admin->id, 'status' => 'sent']);
        $this->assertDatabaseMissing('platform_user_emails', ['idempotency_key' => $this->payload['idempotency_key']]);
        $this->send(['message' => 'A different message'])->assertStatus(409);
    }
    public function test_rejects_other_businesses_staff_addresses_missing_and_ambiguous_owners(): void
    {
        $this->actingAs($this->admin, 'office');
        $other = Business::create(['name' => 'Other Business', 'slug' => Str::uuid()]);
        User::create(['business_id' => $other->id, 'name' => 'Other owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner']);
        $this->send([], $other->id)->assertStatus(409);
        $this->send([], 999999999)->assertNotFound();
        $this->owner->update(['role' => 'staff']); $this->send()->assertStatus(409);
        $this->owner->update(['role' => 'owner']);
        User::create(['business_id' => $this->business->id, 'name' => 'Extra owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner']);
        $this->send()->assertStatus(409); $this->assertCount(0, $this->mailer->sent);
    }
    public function test_rejects_changed_email_injected_subject_and_oversized_body(): void
    {
        $this->actingAs($this->admin, 'office');
        $this->send(['subject' => "Subject\nBcc: someone@example.test"])->assertUnprocessable();
        $this->send(['message' => str_repeat('x', 10001)])->assertUnprocessable();
        $this->owner->update(['email' => Str::uuid().'@example.test']);
        $this->send()->assertStatus(409); $this->assertCount(0, $this->mailer->sent);
    }
    public function test_uncertain_delivery_is_not_resent(): void
    {
        $this->actingAs($this->admin, 'office'); $this->mailer->fail = true;
        $this->send()->assertStatus(503)->assertJsonPath('code', 'email_delivery_unconfirmed');
        $this->send()->assertStatus(409); $this->assertCount(1, $this->mailer->sent);
    }
    public function test_owner_email_uses_business_branding(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'security@example.test']);
        $transport = new class extends \PHPMailer\PHPMailer\PHPMailer { public function send() { return true; } };
        $mailer = new class($transport) extends BusinessOwnerMailer {
            public function __construct(private $transport) {}
            protected function makeMailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->transport; }
        };
        $mailer->send($this->owner->email, $this->owner->name, 'Business update', 'Hello owner.');
        $this->assertStringContainsString('Business</span>', $transport->Body);
        $this->assertStringContainsString('business account', $transport->Body);
        $this->assertSame($this->owner->email, $transport->getToAddresses()[0][0]);
    }
}
