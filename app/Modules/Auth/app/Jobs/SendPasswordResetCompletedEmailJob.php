<?php

namespace Modules\Auth\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\PasswordResetCompletedMail;
use Throwable;

class SendPasswordResetCompletedEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly CarbonInterface $time,
        public readonly string $ip,
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(
            new PasswordResetCompletedMail(
                time: $this->time,
                ip: $this->ip
            )
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to send password reset completed email.', [
            'email' => $this->email,
            'ip_address' => $this->ip,
            'error' => $exception->getMessage(),
        ]);
    }
}
