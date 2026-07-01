<?php

namespace Modules\Auth\Mail;

use Illuminate\Mail\Mailable;

class AccountSuspendedMail extends Mailable
{
    public function __construct(public string $clientName, public string $email, public string $suspendedAt) {}

    public function build(): self
    {
        return $this
            ->subject('Account Suspended')
            ->view('auth::emails.account-suspended');
    }
}
