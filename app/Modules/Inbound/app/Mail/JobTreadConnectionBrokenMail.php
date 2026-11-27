<?php

namespace Modules\Inbound\Mail;

use Illuminate\Mail\Mailable;
use Modules\Inbound\Models\Client;

class JobTreadConnectionBrokenMail extends Mailable
{
    public function __construct(
        public Client $client,
        public string $errorMessage,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("JobTread connection broken: {$this->client->client_name}")
            ->view('inbound::emails.jobtread-connection-broken');
    }
}
