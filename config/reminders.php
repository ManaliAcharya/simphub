<?php

return [
    // Default cadence (days after the first payment-link email) when a client
    // hasn't set their own reminder_schedule_days.
    'default_schedule_days' => [3, 7, 14, 30, 45],

    // Informational only — the actual interval is set on the scheduled command
    // in PaymentServiceProvider::configureSchedules().
    'scheduler_interval' => 'hourly',

    // 'latest' — after scheduler downtime, send only the highest overdue cadence
    // step instead of bursting through every missed step.
    'catch_up_mode' => 'latest',
];
