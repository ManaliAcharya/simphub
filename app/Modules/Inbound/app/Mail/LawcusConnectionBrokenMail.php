<?php

namespace Modules\Inbound\Mail;

use Illuminate\Mail\Mailable;
use Modules\Inbound\Models\Client;

class LawcusConnectionBrokenMail extends Mailable
{
    public function __construct(
        public Client $client,
        public string $errorMessage,
    ) {}

    public function build(): self
    {
        return $this
            ->subject("Lawcus connection broken — {$this->client->client_name}")
            ->view('inbound::emails.lawcus-connection-broken');
    }
}
