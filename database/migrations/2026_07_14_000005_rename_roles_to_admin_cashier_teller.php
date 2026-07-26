<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Temporarily allow both vocabularies so legacy rows can be
            // renamed without MySQL coercing them to an empty enum value.
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner', 'employee', 'admin', 'teller', 'cashier') NOT NULL DEFAULT 'teller'");
        }

        DB::table('users')->where('role', 'owner')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'employee')->update(['role' => 'teller']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'teller', 'cashier') NOT NULL DEFAULT 'teller'");
        }

        $this->renameDemoLogin('owner', 'admin', 'owner@ngwe-lwe.local', 'admin@ngwe-lwe.local');
        $this->renameDemoLogin('employee', 'teller', 'employee@ngwe-lwe.local', 'teller@ngwe-lwe.local');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('owner', 'employee', 'cashier') NOT NULL DEFAULT 'employee'");
        }

        DB::table('users')->where('role', 'admin')->update(['role' => 'owner']);
        DB::table('users')->where('role', 'teller')->update(['role' => 'employee']);
    }

    private function renameDemoLogin(
        string $legacyUsername,
        string $newUsername,
        string $legacyEmail,
        string $newEmail,
    ): void {
        $legacy = DB::table('users')->where('username', $legacyUsername)->first();
        $targetExists = DB::table('users')
            ->where('username', $newUsername)
            ->orWhere('email', $newEmail)
            ->exists();

        if ($legacy !== null && ! $targetExists) {
            DB::table('users')
                ->where('id', $legacy->id)
                ->update([
                    'username' => $newUsername,
                    'email' => $newEmail,
                ]);
        }
    }
};
