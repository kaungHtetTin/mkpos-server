<?php

namespace App\Services;

class SubscriptionApprovalMailer extends SmtpMailer
{
    protected string $mailAction = 'subscription';
    public function send(string $email, string $name, array $details): void
    {
        $mail = $this->configuredMailer();
        $mail->addAddress($email, $name);
        $mail->Subject = 'Your MKPOS subscription is approved';
        $data = compact('name', 'details');
        $mail->Body = view('emails.subscription-approved', $data)->render();
        $mail->AltBody = view('emails.subscription-approved-text', $data)->render();
        $mail->send();
    }
}
