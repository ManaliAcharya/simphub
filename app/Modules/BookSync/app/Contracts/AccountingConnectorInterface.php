<?php

namespace Modules\BookSync\Contracts;

interface AccountingConnectorInterface
{
    /** Unique machine key stored on the client record (e.g. 'quickbooks', 'zoho_books'). */
    public function key(): string;

    /** Human-readable name shown in the UI. */
    public function label(): string;

    /** One-line description shown on the selection card. */
    public function description(): string;
}
