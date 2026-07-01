<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientBalanceException extends RuntimeException
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $available,
        public readonly string $required,
    ) {
        parent::__construct(
            "Insufficient balance on account #{$accountId}: available {$available}, required {$required}."
        );
    }
}
