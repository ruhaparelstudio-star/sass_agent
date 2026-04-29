<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ActivationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $token,
        public readonly string $email,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tenant Admin Activation',
        );
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.url'), '/');
        $activationUrl = $base.'/activation?token='.rawurlencode($this->token).'&email='.rawurlencode($this->email);

        return new Content(
            text: 'emails.activation_link',
            with: [
                'token' => $this->token,
                'email' => $this->email,
                'activation_url' => $activationUrl,
            ],
        );
    }
}
