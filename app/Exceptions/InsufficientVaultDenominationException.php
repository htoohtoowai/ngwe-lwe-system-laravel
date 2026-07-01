<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientVaultDenominationException extends RuntimeException
{
    public function __construct(
        public readonly int $denomination,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Insufficient main vault denomination {$denomination} MMK. "
            ."Available: {$available}, Requested: {$requested}."
        );
    }
}
