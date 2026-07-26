<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientFloatDenominationException extends RuntimeException
{
    public function __construct(
        public readonly int $denomination,
        public readonly int $available,
        public readonly int $requested,
    ) {
        parent::__construct(
            "Insufficient {$denomination} MMK notes on Teller float. "
            ."Available: {$available}, Requested: {$requested}."
        );
    }
}
