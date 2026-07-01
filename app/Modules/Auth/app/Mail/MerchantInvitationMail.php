<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MerchantInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url)
    {
    }

    public function build()
    {
        return $this->subject('Reset Your Password')
                ->view('auth::emails.invitation');
    }
}
