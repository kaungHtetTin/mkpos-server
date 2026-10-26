<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\SubscriptionApprovalEmail;
use App\Services\SubscriptionApprovalMailer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionApprovalEmailTest extends TestCase
{
    use DatabaseTransactions;

    private $mailer;
    private User $owner;
    private PlatformAdmin $admin;
    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 12:00:00 UTC');
        config(['shop_chat.realtime_enabled' => false]);
        $business = Business::create(['name' => 'Email Test Shop', 'slug' => Str::uuid(), 'status' => 'active', 'timezone' => 'Asia/Yangon']);
        $this->owner = User::create(['business_id' => $business->id, 'name' => 'Shop Owner', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'role' => 'owner', 'is_active' => true]);
        $this->admin = PlatformAdmin::create(['name' => 'Approver', 'email' => Str::uuid().'@example.test', 'password' => Hash::make('password123'), 'is_active' => true]);
        $this->plan = DB::table('subscription_plans')->insertGetId(['name' => 'Monthly Pro', 'slug' => Str::uuid(), 'price' => 25000, 'currency' => 'Ks', 'duration_days' => 30, 'is_system' => false, 'is_public' => true]);
        $this->mailer = new class extends SubscriptionApprovalMailer {
            public array $sent = [];
            public bool $fail = false;
            public function assertConfigured(): void {}
            public function send(string $email, string $name, array $details): void {
                $this->sent[] = compact('email', 'name', 'details');
                if ($this->fail) throw new \RuntimeException('Private SMTP credentials must not be exposed');
            }
        };
        $this->app->instance(SubscriptionApprovalMailer::class, $this->mailer);
        // Laravel 12 executes callbacks after the application transaction commits,
        // ignoring the fixture transaction. Hold an application transaction explicitly.
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); parent::tearDown();
    }

    private function requestId(): int
    {
        return DB::table('subscription_requests')->insertGetId(['business_id' => $this->owner->business_id, 'subscription_plan_id' => $this->plan, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function callbacks(): void
    {
        DB::commit();
        DB::beginTransaction();
    }

    public function test_approval_emails_only_the_saved_owner_after_commit_and_only_once(): void
    {
        $requestId = $this->requestId();
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/approve', ['price_paid' => 22000])->assertOk();
        $linkedPeriod = DB::table('subscription_requests')->where('id', $requestId)->value('business_subscription_id');
        $this->assertNotNull($linkedPeriod);
        $this->assertSame((int) $this->owner->business_id, (int) DB::table('business_subscriptions')->where('id', $linkedPeriod)->value('business_id'));
        $this->assertCount(0, $this->mailer->sent);
        $this->callbacks(); $this->callbacks();
        $this->assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];
        $this->assertSame($this->owner->email, $email['email']);
        $this->assertSame('Email Test Shop', $email['details']['business_name']);
        $this->assertSame('Monthly Pro', $email['details']['plan_name']);
        $this->assertSame(22000, $email['details']['amount_paid']);
        $this->assertSame('REQ-'.$requestId, $email['details']['reference']);
        $this->assertStringContainsString('17 Oct 2026, 18:30', $email['details']['starts_at']);
        $this->assertStringContainsString('16 Nov 2026, 18:30', $email['details']['ends_at']);
        $this->assertDatabaseHas('business_owner_emails', ['owner_user_id' => $this->owner->id, 'office_admin_id' => $this->admin->id, 'status' => 'sent']);
        $this->postJson('/api/office/subscription-requests/'.$requestId.'/approve')->assertNotFound();
        $this->assertCount(1, $this->mailer->sent);
    }

    public function test_renewal_email_includes_the_extended_end_date(): void
    {
        $first = $this->requestId();
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$first.'/approve')->assertOk();
        $this->callbacks();
        $second = $this->requestId();
        $this->postJson('/api/office/subscription-requests/'.$second.'/approve')->assertOk();
        $this->callbacks();
        $this->assertCount(2, $this->mailer->sent);
        $this->assertStringContainsString('16 Dec 2026, 18:30', $this->mailer->sent[1]['details']['ends_at']);
    }

    public function test_failed_delivery_keeps_approval_and_does_not_resend_uncertain_email(): void
    {
        $this->mailer->fail = true;
        $requestId = $this->requestId();
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/approve')->assertOk();
        $this->callbacks(); $this->callbacks();
        $this->assertCount(1, $this->mailer->sent);
        $this->assertDatabaseHas('subscription_requests', ['id' => $requestId, 'status' => 'approved']);
        $this->assertDatabaseHas('business_owner_emails', ['owner_user_id' => $this->owner->id, 'status' => 'unconfirmed', 'sent_at' => null]);
    }

    public function test_rolled_back_approval_sends_no_email(): void
    {
        $requestId = $this->requestId();
        DB::beginTransaction();
        $this->actingAs($this->admin, 'office')->postJson('/api/office/subscription-requests/'.$requestId.'/approve')->assertOk();
        DB::rollBack(); $this->callbacks();
        $this->assertCount(0, $this->mailer->sent);
        $this->assertDatabaseMissing('business_owner_emails', ['owner_user_id' => $this->owner->id]);
    }

    public function test_html_and_plain_text_templates_include_details_and_escape_untrusted_html(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'billing@example.test']);
        $transport = new class extends \PHPMailer\PHPMailer\PHPMailer { public function send() { return true; } };
        $mailer = new class($transport) extends SubscriptionApprovalMailer {
            public function __construct(private $transport) {}
            protected function makeMailer(): \PHPMailer\PHPMailer\PHPMailer { return $this->transport; }
        };
        $details = ['business_name' => '<script>shop</script>', 'plan_name' => 'Monthly Pro', 'amount_paid' => 25000, 'currency' => 'Ks', 'starts_at' => '17 Oct 2026, 18:30 +0630', 'ends_at' => '16 Nov 2026, 18:30 +0630', 'approved_at' => '17 Oct 2026, 18:30 +0630', 'reference' => 'REQ-17'];
        $mailer->send($this->owner->email, '<b>Owner</b>', $details);
        $this->assertSame('Your MKPOS subscription is approved', $transport->Subject);
        $this->assertSame($this->owner->email, $transport->getToAddresses()[0][0]);
        foreach ([$transport->Body, $transport->AltBody] as $body) {
            foreach (['Monthly Pro', '25,000 Ks', '16 Nov 2026', 'REQ-17', 'Billing'] as $value) $this->assertStringContainsString($value, $body);
        }
        $this->assertStringNotContainsString('<script>', $transport->Body);
        $this->assertStringContainsString('&lt;b&gt;Owner&lt;/b&gt;', $transport->Body);
    }
}
