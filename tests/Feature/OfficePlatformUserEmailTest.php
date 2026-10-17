<?php

namespace Tests\Feature;

use App\Models\PlatformAdmin;
use App\Models\PlatformCustomer;
use App\Services\PlatformUserMailer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficePlatformUserEmailTest extends TestCase
{
    use DatabaseTransactions;
    private PlatformAdmin $admin;
    private PlatformCustomer $customer;
    private $mailer;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PlatformAdmin::create(['name' => 'Email Admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->customer = PlatformCustomer::create(['name' => 'Email Customer', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->payload = ['subject' => 'Account update', 'message' => "Hello,\nAn update for your account.", 'recipient_email' => $this->customer->email, 'idempotency_key' => (string) Str::uuid()];
        $this->mailer = new class extends PlatformUserMailer {
            public array $sent = [];
            public bool $fail = false;
            public function assertConfigured(): void {}
            public function send(string $email, string $name, string $subject, string $message): void {
                $this->sent[] = compact('email', 'name', 'subject', 'message');
                if ($this->fail) throw new \RuntimeException('Private SMTP details');
            }
        };
        $this->app->instance(PlatformUserMailer::class, $this->mailer);
    }
    private function send(array $extra = [], ?int $customer = null) { return $this->postJson('/api/office/platform-users/'.($customer ?? $this->customer->id).'/email', $extra + $this->payload); }

    public function test_office_authentication_is_required(): void
    {
        $this->send()->assertUnauthorized(); $this->assertCount(0, $this->mailer->sent);
    }

    public function test_sends_to_saved_customer_address_once_and_records_admin_without_storing_content(): void
    {
        $this->actingAs($this->admin, 'office');
        $this->send()->assertOk()->assertJsonPath('message', 'Email sent to '.$this->customer->email.'.');
        $this->send()->assertOk();
        $this->assertCount(1, $this->mailer->sent);
        $this->assertSame($this->customer->email, $this->mailer->sent[0]['email']);
        $this->assertSame($this->payload['message'], $this->mailer->sent[0]['message']);
        $this->assertDatabaseHas('platform_user_emails', ['idempotency_key' => $this->payload['idempotency_key'], 'office_admin_id' => $this->admin->id, 'status' => 'sent']);
        $this->assertNotNull(DB::table('platform_user_emails')->where('idempotency_key', $this->payload['idempotency_key'])->value('sent_at'));
    }

    public function test_idempotency_key_cannot_be_reused_with_another_message_or_admin(): void
    {
        $this->actingAs($this->admin, 'office'); $this->send()->assertOk();
        $this->send(['message' => 'Different content'])->assertStatus(409);
        $other = PlatformAdmin::create(['name' => 'Other Admin', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->actingAs($other, 'office'); $this->send()->assertStatus(409);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_rejects_changed_recipient_unknown_customer_and_invalid_or_injected_content(): void
    {
        $this->actingAs($this->admin, 'office');
        $this->send(['recipient_email' => 'another@example.test'])->assertStatus(409);
        $this->send(['subject' => "Subject\r\nBcc: stranger@example.test"])->assertUnprocessable();
        $this->send(['subject' => str_repeat('x', 161)])->assertUnprocessable();
        $this->send(['message' => str_repeat('x', 10001)])->assertUnprocessable();
        $this->send(['message' => '   '])->assertUnprocessable();
        $this->send(['idempotency_key' => 'not-a-uuid'])->assertUnprocessable();
        $this->send([], 999999999)->assertNotFound();
        $this->assertCount(0, $this->mailer->sent);
        $this->assertDatabaseMissing('platform_user_emails', ['idempotency_key' => $this->payload['idempotency_key']]);
    }

    public function test_uncertain_smtp_delivery_is_sanitized_and_never_automatically_retried(): void
    {
        $this->actingAs($this->admin, 'office'); $this->mailer->fail = true;
        $response = $this->send()->assertStatus(503)->assertJsonPath('code', 'email_delivery_unconfirmed');
        $this->assertStringNotContainsString('Private SMTP details', $response->getContent());
        $this->mailer->fail = false; $this->send()->assertStatus(409);
        $this->assertCount(1, $this->mailer->sent);
        $this->assertDatabaseHas('platform_user_emails', ['idempotency_key' => $this->payload['idempotency_key'], 'status' => 'unconfirmed']);
    }

    public function test_processing_reservations_are_not_sent_twice(): void
    {
        $this->actingAs($this->admin, 'office'); $this->send()->assertOk();
        DB::table('platform_user_emails')->where('idempotency_key', $this->payload['idempotency_key'])->update(['status' => 'sending']);
        $this->send()->assertStatus(409); $this->assertCount(1, $this->mailer->sent);
    }

    public function test_phpmailer_renders_escaped_html_and_plain_text(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'security@example.test']);
        $transport = new class extends \PHPMailer\PHPMailer\PHPMailer { public function send() { return true; } };
        $mailer = new class($transport) extends PlatformUserMailer {
            public function __construct(private $transport) {}
            protected function makeMailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->transport; }
        };
        $mailer->send($this->customer->email, '<img src=x>', '<script>Subject</script>', "First line\n<script>alert(1)</script>");
        $this->assertStringNotContainsString('<script>', $transport->Body);
        $this->assertStringNotContainsString('<img src=x>', $transport->Body);
        $this->assertStringContainsString("First line\n", $transport->Body);
        $this->assertStringContainsString('First line', $transport->AltBody);
        $this->assertSame($this->customer->email, $transport->getToAddresses()[0][0]);
    }
}
