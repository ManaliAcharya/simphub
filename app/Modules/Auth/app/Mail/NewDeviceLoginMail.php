<?php

namespace Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NewDeviceLoginMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $ipAddress,
        public string $location,
        public string $loggedInAt,
    ) {}

    public function build()
    {
        return $this->subject('New sign-in detected')
            ->view('auth::emails.new-device-login');
    }
}
