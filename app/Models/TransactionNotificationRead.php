<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionNotificationRead extends Model
{
    protected $fillable = ['user_id', 'transaction_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
