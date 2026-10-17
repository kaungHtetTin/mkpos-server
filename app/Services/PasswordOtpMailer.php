<?php

namespace App\Services;

class PasswordOtpMailer extends SmtpMailer
{
    protected string $mailAction = 'auth';
    public function send(string $email, string $name, string $code, string $audience): void
    {
        $mail = $this->configuredMailer();
        $mail->addAddress($email, $name);
        $mail->Subject = 'Reset your MKPOS password';
        $data = compact('name', 'code', 'audience');
        $mail->Body = view('emails.password-reset-otp', $data)->render();
        $mail->AltBody = view('emails.password-reset-otp-text', $data)->render();
        $mail->send();
    }
}
