<?php

namespace Modules\Payment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessGatewayPaymentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    
    public int $tries   = 3;
    public int $timeout = 30;

    public function __construct(
        public string $transactionReference,
    ) {}

    public function handle(): void
    {
        // Gateway dispatch belongs here once orchestration rules are finalized.
        try {
            $this->orchestrator->process($this->session, $this->token);
        } catch (GatewayConnectionException $e) {
            // Transient network error — safe to retry with backoff
            $this->release($this->backoff());
        } catch (GatewayDeclinedException $e) {
            // Hard decline — do not retry, mark FAILED immediately
            $this->stateMachine->transition($this->session, 'FAILED');
            event(new PaymentDeclined($this->session, $e->response));
            $this->fail($e);
        } catch (GatewayTimeoutException $e) {
            // DANGEROUS: charge may or may not have gone through
            // Mark PENDING_VERIFICATION — do NOT retry charge
            Transaction::where('payment_session_id', $this->session->id)
                ->update(['status' => 'PENDING_VERIFICATION']);
            ReconcileTransactionJob::dispatch($this->session)->delay(60);
            $this->fail($e);
        }
    }
    private function backoff(): int
    {
        return [30, 90, 300][$this->attempts() - 1] ?? 300;
    }
}
