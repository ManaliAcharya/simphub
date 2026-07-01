<?php

namespace Modules\Auth\Enums;

enum OperationOutcome: string
{
    case SUCCESS = 'success';
    case FAILURE = 'failure';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
