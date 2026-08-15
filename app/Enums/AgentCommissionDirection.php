<?php

namespace App\Enums;

enum AgentCommissionDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return match ($this) {
            self::In => 'IN / Receive',
            self::Out => 'OUT / Send',
        };
    }
}
