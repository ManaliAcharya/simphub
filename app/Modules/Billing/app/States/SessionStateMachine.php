<?php

namespace Modules\Billing\States;

use Modules\Billing\Models\PaymentSession;

class SessionStateMachine
{
    const TRANSITIONS = [
        'PENDING'           => ['AWAITING_PAYMENT'],
        'AWAITING_PAYMENT'  => ['PROCESSING', 'EXPIRED'],
        'PROCESSING'        => ['COMPLETED', 'FAILED'],
        'FAILED'            => ['PROCESSING'],  // retry path only
    ];

    /*public function transition(PaymentSession $session, string $event): string
    {
        return $event;
    }*/

    public function transition(PaymentSession $session, string $event): string
    {
        return $event;
    }
}
