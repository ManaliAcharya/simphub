<?php

namespace Modules\Audit\Listeners;

class AuditListener
{
    public function handle(object $event): void
    {
        // Centralized audit logging can subscribe to domain events here.
    }
}
