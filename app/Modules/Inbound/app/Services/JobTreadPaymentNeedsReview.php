<?php

namespace Modules\Inbound\Services;

use RuntimeException;

/** A payment SimpHub won't ask the merchant to record (it could double it); left FAILED for review. */
class JobTreadPaymentNeedsReview extends RuntimeException {}
