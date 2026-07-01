<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AccountLockedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $email,
        public readonly ?string $ipAddress,
        public readonly mixed $lockedAt
    ) {
    }

    public function build()
    {
        return $this->subject('Account temporarily locked')
            ->view('auth::emails.account-locked');
    }
}
