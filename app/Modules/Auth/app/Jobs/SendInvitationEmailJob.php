<?php

namespace Modules\Auth\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Auth\Mail\MerchantInvitationMail;

class SendInvitationEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $email,
        public readonly string $url,
    ) {
    }

    public function handle(): void
    {
        Mail::to($this->email)
            ->send(new MerchantInvitationMail($this->url));
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to send merchant invitation email.', [
            'email' => $this->email,
            'error' => $exception->getMessage(),
        ]);
    }
}
