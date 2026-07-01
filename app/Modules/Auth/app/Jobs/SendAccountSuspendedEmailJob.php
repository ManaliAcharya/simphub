<?php

namespace Modules\Auth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\AccountSuspendedMail;
use Throwable;

class SendAccountSuspendedEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly string $clientName,
        public readonly string $suspendedAt,
    ) {}

    public function handle(): void
    {
        Mail::to($this->email)->send(
            new AccountSuspendedMail(
                $this->clientName,
                 $this->email,
                $this->suspendedAt,
            )
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to send account suspended email.', [
            'email' => $this->email,
            'client_name' => $this->clientName,
            'error' => $exception->getMessage(),
        ]);
    }
}
