<?php

namespace Tests\Feature;

use App\Services\BusinessOwnerMailer;
use App\Services\PasswordOtpMailer;
use App\Services\PlatformUserMailer;
use App\Services\SubscriptionApprovalMailer;
use PHPMailer\PHPMailer\PHPMailer;
use Tests\TestCase;

trait CapturesActionMail
{
    public ?PHPMailer $transport = null;

    protected function makeMailer(): PHPMailer
    {
        return $this->transport = new class extends PHPMailer {
            public function send() { return true; }
        };
    }
}

class ActionMailConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.actions' => [], 'mail.mailers.smtp' => ['host' => 'default.example.test', 'port' => 587, 'username' => 'default-user', 'password' => 'default-test-password', 'encryption' => 'tls'], 'mail.from' => ['address' => 'default@example.test', 'name' => 'MKPOS Default']]);
    }

    private function mailers(): array
    {
        return [
            'auth' => new class extends PasswordOtpMailer { use CapturesActionMail; },
            'subscription' => new class extends SubscriptionApprovalMailer { use CapturesActionMail; },
            'messages' => new class extends PlatformUserMailer { use CapturesActionMail; },
            'owner-messages' => new class extends BusinessOwnerMailer { use CapturesActionMail; },
        ];
    }

    private function send(string $action, object $mailer): void
    {
        if ($action === 'auth') $mailer->send('recipient@example.test', 'Recipient', '123456', 'MKPOS Business');
        elseif ($action === 'subscription') $mailer->send('recipient@example.test', 'Recipient', ['business_name' => 'Shop', 'plan_name' => 'Pro', 'amount_paid' => 1000, 'currency' => 'Ks', 'starts_at' => '17 Oct 2026', 'ends_at' => '17 Nov 2026', 'approved_at' => '17 Oct 2026', 'reference' => 'REQ-1']);
        else $mailer->send('recipient@example.test', 'Recipient', 'Custom subject', 'Custom message');
    }

    public function test_each_action_uses_its_own_sender_and_smtp_account(): void
    {
        foreach (['auth', 'subscription', 'messages'] as $action) config(['mail.actions.'.$action => ['host' => $action.'.example.test', 'port' => 465, 'username' => $action.'-user', 'password' => $action.'-test-password', 'encryption' => 'ssl', 'from_address' => $action.'@example.test', 'from_name' => 'MKPOS '.$action]]);
        foreach ($this->mailers() as $action => $mailer) {
            $mailer->assertConfigured(); $this->send($action, $mailer);
            $profile = $action === 'owner-messages' ? 'messages' : $action;
            $this->assertSame($profile.'.example.test', $mailer->transport->Host);
            $this->assertSame(465, $mailer->transport->Port);
            $this->assertSame($profile.'-user', $mailer->transport->Username);
            $this->assertSame($profile.'-test-password', $mailer->transport->Password);
            $this->assertSame('ssl', $mailer->transport->SMTPSecure);
            $this->assertTrue($mailer->transport->SMTPAuth);
            $this->assertSame($profile.'@example.test', $mailer->transport->From);
            $this->assertSame('MKPOS '.$profile, $mailer->transport->FromName);
            $this->assertSame('recipient@example.test', $mailer->transport->getToAddresses()[0][0]);
        }
    }

    public function test_unset_action_settings_inherit_the_existing_global_settings(): void
    {
        foreach ($this->mailers() as $action => $mailer) {
            $this->send($action, $mailer);
            $this->assertSame('default.example.test', $mailer->transport->Host);
            $this->assertSame('default-user', $mailer->transport->Username);
            $this->assertSame('default@example.test', $mailer->transport->From);
        }
    }

    public function test_sender_only_override_preserves_smtp_and_explicit_null_disables_authentication_and_encryption(): void
    {
        config(['mail.actions.messages' => ['from_address' => 'support@example.test']]);
        $mailer = $this->mailers()['messages'];$this->send('messages', $mailer);
        $this->assertSame('support@example.test', $mailer->transport->From);
        $this->assertSame('default-user', $mailer->transport->Username);
        config(['mail.actions.messages' => ['username' => null, 'password' => null, 'encryption' => null]]);
        $mailer = $this->mailers()['messages'];$this->send('messages', $mailer);
        $this->assertFalse($mailer->transport->SMTPAuth);
        $this->assertSame('', $mailer->transport->SMTPSecure);
    }

    public function test_configuration_check_uses_the_selected_action_not_the_global_sender(): void
    {
        config(['mail.mailers.smtp.host' => null, 'mail.from.address' => null, 'mail.actions.auth' => ['host' => 'auth.example.test', 'from_address' => 'auth@example.test']]);
        $mailers = $this->mailers();$mailers['auth']->assertConfigured();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $mailers['subscription']->assertConfigured();
    }
}
