<?php

namespace Modules\Inbound\Services;

use RuntimeException;

/**
 * A Lawcus recording failure the firm must review rather than simply record by hand —
 * asking them to record it could double a payment or put operating money into trust.
 */
class LawcusPaymentNeedsReview extends RuntimeException {}
