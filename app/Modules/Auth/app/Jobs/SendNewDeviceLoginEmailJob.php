<?php

namespace Modules\Auth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\NewDeviceLoginMail;
use Throwable;

class SendNewDeviceLoginEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public string $email,
        public string $ipAddress,
        public string $location,
        public string $loggedInAt,
    ) {
        $this->onConnection('database');
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(
            new NewDeviceLoginMail(
                ipAddress: $this->ipAddress,
                location: $this->location,
                loggedInAt: $this->loggedInAt,
            )
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to send new device login email.', [
            'email' => $this->email,
            'error' => $exception->getMessage(),
        ]);
    }
}
