<?php

namespace App\Services;

class PlatformUserMailer extends SmtpMailer
{
    protected string $mailAction = 'messages';
    protected string $audience = 'Shops';

    public function send(string $email, string $name, string $subject, string $message): void
    {
        $mail = $this->configuredMailer();
        $mail->addAddress($email, $name);
        $mail->Subject = $subject;
        $data = compact('name', 'subject', 'message') + ['audience' => $this->audience];
        $mail->Body = view('emails.platform-user-message', $data)->render();
        $mail->AltBody = view('emails.platform-user-message-text', $data)->render();
        $mail->send();
    }
}
