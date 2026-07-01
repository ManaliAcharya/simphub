<?php

namespace Modules\Auth\Mail;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetCompletedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly CarbonInterface $time,
        public readonly string $ip,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your password was reset',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'auth::emails.password-reset-completed',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
