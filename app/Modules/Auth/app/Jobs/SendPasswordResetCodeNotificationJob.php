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
use Modules\Auth\Mail\PasswordResetCodeMail;
use Throwable;

class SendPasswordResetCodeNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly string $code,
        public readonly CarbonInterface $expiresAt,
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->email)->send(
            new PasswordResetCodeMail(
                code: $this->code,
                expiresAt: $this->expiresAt
            )
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Failed to send password reset code notification.', [
            'email' => $this->email,
            'error' => $exception->getMessage(),
        ]);
    }
}
