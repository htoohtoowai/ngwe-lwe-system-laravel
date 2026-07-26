<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE vault_transactions MODIFY txn_type ENUM('float_issue', 'float_receipt', 'float_reject', 'cash_in', 'cash_in_received', 'cash_in_handoff', 'cash_in_change', 'cash_out', 'return_initiate', 'return_confirm', 'adjustment') NOT NULL");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE vault_transactions MODIFY txn_type ENUM('float_issue', 'float_receipt', 'cash_in', 'cash_in_received', 'cash_in_handoff', 'cash_in_change', 'cash_out', 'return_initiate', 'return_confirm', 'adjustment') NOT NULL");
        }
    }
};
