<?php

namespace Modules\Reconciliation\Services;

class ReconciliationService
{
    public function reconcile(string $transactionReference): bool
    {
        return $transactionReference !== '';
    }
}
