<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('employee_contracts')->nullOnDelete();
            $table->string('source', 20);
            $table->string('status', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->foreignId('nature_of_contract_id')->nullable()->constrained('nature_of_contracts')->nullOnDelete();
            $table->unsignedSmallInteger('period')->nullable();
            $table->decimal('basic_salary', 15, 2)->nullable();
            $table->foreignId('new_contract_id')->nullable()->constrained('employee_contracts')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_comment')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'status']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('contract_renewal_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_renewal_id')->constrained('contract_renewals')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_renewal_files');
        Schema::dropIfExists('contract_renewals');
    }
};
