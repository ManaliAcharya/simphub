<?php

namespace Modules\Billing\States;

use Modules\Billing\Models\PaymentSession;

class SessionStateMachine
{
    public function transition(PaymentSession $session, string $event): string
    {
        return $event;
    }
}
