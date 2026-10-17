<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;

class SmtpMailer
{
    protected string $mailAction = '';

    protected function makeMailer(): PHPMailer { return new PHPMailer(true); }

    private function settings(): array
    {
        return array_replace(config('mail.mailers.smtp', []), [
            'from_address' => config('mail.from.address'), 'from_name' => config('mail.from.name'),
        ], $this->mailAction ? config('mail.actions.'.$this->mailAction, []) : []);
    }

    public function assertConfigured(): void
    {
        $settings = $this->settings();
        abort_unless($settings['host'] && $settings['from_address'], 503, 'Email delivery is temporarily unavailable. Please try again later.');
    }

    protected function configuredMailer(): PHPMailer
    {
        $smtp = $this->settings();
        $mail = $this->makeMailer();
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->Port = (int) $smtp['port'];
        $mail->SMTPAuth = !empty($smtp['username']);
        $mail->Username = $smtp['username'] ?? '';
        $mail->Password = $smtp['password'] ?? '';
        $mail->SMTPSecure = $smtp['encryption'] ?? '';
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($smtp['from_address'], $smtp['from_name'] ?: 'MKPOS');
        $mail->isHTML(true);
        return $mail;
    }
}
