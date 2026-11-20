<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('allowance_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 12)->default('draft');
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_comment', 500)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'allowance_category_id', 'department_id', 'date'], 'allowance_batches_day_unique');
        });

        Schema::create('allowance_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('allowance_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('items', 8, 2);
            $table->decimal('amount_per_item', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('department_allowance_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('daily_allowance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['allowance_batch_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_batch_lines');
        Schema::dropIfExists('allowance_batches');
    }
};
