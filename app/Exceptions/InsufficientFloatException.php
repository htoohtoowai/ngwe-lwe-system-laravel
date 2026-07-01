<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientFloatException extends RuntimeException
{
    public function __construct(
        public readonly string $available,
        public readonly string $required,
    ) {
        parent::__construct(
            "Insufficient cash in float. Available: {$available}, Required: {$required}"
        );
    }
}
