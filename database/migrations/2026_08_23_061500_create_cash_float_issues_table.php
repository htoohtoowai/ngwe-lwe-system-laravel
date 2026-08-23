<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_float_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('float_id')->constrained('cash_float_assignments')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('employee_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->enum('issue_type', ['INITIAL', 'ADDITIONAL'])->default('INITIAL');
            $table->enum('status', ['PENDING_RECEIPT', 'RECEIVED', 'REJECTED'])->default('PENDING_RECEIPT');
            $table->decimal('amount', 18, 2);
            $table->json('denominations_json');
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['employee_id', 'status']);
            $table->index(['float_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_float_issues');
    }
};
