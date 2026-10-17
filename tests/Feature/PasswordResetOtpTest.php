<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\PasswordOtpMailer;
use App\Services\PlatformCustomerSessions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use DatabaseTransactions;
    private User $owner;
    private int $customer;
    private $mailer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $business = Business::create(['name' => 'Reset Test', 'slug' => Str::uuid(), 'status' => 'active', 'timezone' => 'Asia/Yangon', 'currency' => 'Ks']);
        $email = Str::uuid().'@example.test';
        $this->owner = User::create(['business_id' => $business->id, 'name' => 'Reset Owner', 'email' => $email, 'password' => Hash::make('old-password'), 'role' => 'owner', 'is_active' => true]);
        $this->customer = DB::table('platform_customers')->insertGetId(['name' => 'Reset Customer', 'email' => $email, 'password' => Hash::make('old-password'), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->mailer = new class extends PasswordOtpMailer {
            public array $sent = [];
            public bool $fail = false;
            public function assertConfigured(): void {}
            public function send(string $email, string $name, string $code, string $audience): void {
                if ($this->fail) throw new \RuntimeException('Synthetic delivery failure');
                $this->sent[] = compact('email', 'name', 'code', 'audience');
            }
        };
        $this->app->instance(PasswordOtpMailer::class, $this->mailer);
    }
    private function base(bool $customer): string { return $customer ? '/api/ecommerce/customer' : '/api/auth'; }
    private function send(bool $customer = false, array $extra = []) { return $this->postJson($this->base($customer).'/forgot-password', $extra + ['email' => $this->owner->email]); }
    private function reset(bool $customer, string $code, array $extra = []) { return $this->postJson($this->base($customer).'/reset-password', $extra + ['email' => $this->owner->email, 'code' => $code, 'password' => 'new-password123', 'password_confirmation' => 'new-password123']); }
    public static function scopes(): array { return [[false], [true]]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('scopes')]
    public function test_reset_is_single_use_hashed_and_revokes_tokens(bool $customer): void
    {
        $token = $this->owner->createToken('reset-test')->plainTextToken;
        $customerSession = app(PlatformCustomerSessions::class)->issue(DB::table('platform_customers')->find($this->customer));
        $this->send($customer, ['email' => '  '.strtoupper($this->owner->email).'  '])->assertOk()->assertJsonPath('expires_in', 600);
        $code = $this->mailer->sent[0]['code'];
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->assertNotSame($code, DB::table('password_reset_otps')->where('key', hash_hmac('sha256', ($customer ?? false ? 'customer' : 'owner').':'.$this->owner->email, config('app.key')))->value('code_hash'));
        $this->reset($customer, $code)->assertOk();
        $this->reset($customer, $code)->assertUnprocessable();
        if ($customer) {
            $this->assertTrue(Hash::check('new-password123', DB::table('platform_customers')->find($this->customer)->password));
            $this->assertNull(app(PlatformCustomerSessions::class)->resolve($customerSession['access_token']));
            $this->assertSame(1, $this->owner->tokens()->count());
            $this->assertTrue(Hash::check('old-password', $this->owner->fresh()->password));
        } else {
            $this->assertTrue(Hash::check('new-password123', $this->owner->fresh()->password));
            $this->assertSame(0, $this->owner->tokens()->count());
            $this->assertNotNull(app(PlatformCustomerSessions::class)->resolve($customerSession['access_token']));
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scopes')]
    public function test_expiry_attempt_limit_and_resend_replacement(bool $customer): void
    {
        $this->send($customer)->assertOk(); $code = $this->mailer->sent[0]['code'];
        $this->send($customer)->assertOk(); $this->assertCount(1, $this->mailer->sent);
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) $this->reset($customer, $wrong)->assertUnprocessable();
        $this->reset($customer, $code)->assertUnprocessable();
        $this->travel(61)->seconds(); $this->send($customer)->assertOk();
        $replacement = $this->mailer->sent[1]['code'];
        if ($replacement !== $code) $this->reset($customer, $code)->assertUnprocessable();
        $this->travel(11)->minutes(); $this->reset($customer, $replacement)->assertUnprocessable();
        $this->send($customer)->assertOk();
        $this->reset($customer, $this->mailer->sent[2]['code'])->assertOk();
    }

    public function test_codes_cannot_cross_account_types_or_other_email_addresses(): void
    {
        $this->send()->assertOk(); $code = $this->mailer->sent[0]['code'];
        $this->reset(true, $code)->assertUnprocessable();
        $this->reset(false, $code, ['email' => 'another@example.test'])->assertUnprocessable();
        $this->reset(false, $code, ['password_confirmation' => 'different'])->assertUnprocessable();
        $this->reset(false, $code)->assertOk();
    }

    public function test_unknown_inactive_and_staff_accounts_get_same_generic_response(): void
    {
        $expected = $this->send()->assertOk()->json();
        $this->send(false, ['email' => Str::uuid().'@example.test'])->assertExactJson($expected);
        $this->owner->update(['role' => 'staff']);
        $this->travel(61)->seconds(); $this->send()->assertExactJson($expected);
        $this->owner->update(['role' => 'owner', 'is_active' => false]);
        $this->travel(61)->seconds(); $this->send()->assertExactJson($expected);
        DB::table('platform_customers')->where('id', $this->customer)->update(['is_active' => false]);
        $this->send(true)->assertExactJson($expected);
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_delivery_failure_never_leaks_mail_server_details_or_leaves_a_valid_code(): void
    {
        $this->mailer->fail = true; $this->send()->assertOk()->assertJsonMissing(['Synthetic delivery failure']);
        $this->assertNull(DB::table('password_reset_otps')->where('key', hash_hmac('sha256', ($customer ?? false ? 'customer' : 'owner').':'.$this->owner->email, config('app.key')))->value('code_hash'));
    }

    public function test_email_template_escapes_names_and_contains_otp_expiry_and_account_type(): void
    {
        $html = view('emails.password-reset-otp', ['name' => '<script>alert(1)</script>', 'code' => '012345', 'audience' => 'MKPOS Shops'])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('012345', $html);
        $this->assertStringContainsString('10 minutes', $html);
        $this->assertStringContainsString('MKPOS Shops', $html);
    }

    public function test_owner_web_session_is_rejected_after_reset_and_new_login_is_accepted(): void
    {
        $this->send()->assertOk();
        $this->reset(false, $this->mailer->sent[0]['code'])->assertOk();
        $this->actingAs($this->owner->fresh(), 'web')->withSession(['password_reset_version' => 'old-version'])
            ->withHeader('Origin', 'http://localhost')->getJson('/api/auth/me')->assertUnauthorized();
        $this->postJson('/api/auth/login', ['email' => $this->owner->email, 'password' => 'new-password123'])->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_phpmailer_smtp_uses_laravel_configuration_and_both_templates(): void
    {
        config(['mail.actions.auth' => []]);
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587, 'mail.mailers.smtp.encryption' => 'tls', 'mail.mailers.smtp.username' => 'mailer', 'mail.mailers.smtp.password' => 'test-only', 'mail.from.address' => 'security@example.test', 'mail.from.name' => 'MKPOS']);
        $transport = new class extends \PHPMailer\PHPMailer\PHPMailer {
            public bool $delivered = false;
            public function send() { $this->delivered = true; return true; }
        };
        $service = new class($transport) extends PasswordOtpMailer {
            public function __construct(private $transport) {}
            protected function makeMailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->transport; }
        };
        $service->assertConfigured();
        $service->send('buyer@example.test', 'Buyer', '012345', 'MKPOS Shops');
        $this->assertTrue($transport->delivered);
        $this->assertSame('smtp', $transport->Mailer);
        $this->assertSame('tls', $transport->SMTPSecure);
        $this->assertSame('security@example.test', $transport->From);
        $this->assertStringContainsString('012345', $transport->Body);
        $this->assertStringContainsString('012345', $transport->AltBody);
    }

    public function test_public_send_endpoint_has_ip_throttling(): void
    {
        $this->withMiddleware(ThrottleRequests::class);
        $this->withServerVariables(['REMOTE_ADDR' => '198.18.'.random_int(1, 254).'.'.random_int(1, 254)]);
        for ($i = 0; $i < 5; $i++) $this->send()->assertOk();
        $this->send()->assertStatus(429);
    }
}
