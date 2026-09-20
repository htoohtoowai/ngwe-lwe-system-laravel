<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_adjustment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('target_type', 16);
            $table->foreignId('account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('direction', 16);
            $table->decimal('amount', 18, 2);
            $table->json('denominations_json')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 24)->default('PENDING');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['assigned_cashier_id', 'status']);
            $table->index(['target_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_adjustment_requests');
    }
};
