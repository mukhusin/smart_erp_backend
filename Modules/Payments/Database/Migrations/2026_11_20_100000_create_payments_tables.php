<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('payable_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('withholding_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('require_attachment')->default(true);
            $table->timestamps();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('purpose')->nullable();
            $table->date('needed_by')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 15)->default('draft');
            $table->boolean('over_budget')->default(false);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
        });

        Schema::create('payment_request_payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('kind', 15)->default('supplier');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10)->default('bank');
            $table->string('bank_name', 100)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('mobile_number', 20)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('withholding', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name', 200);
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('payment_request_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('description', 255)->nullable();
            $table->decimal('gross', 18, 2);
            $table->decimal('withholding', 18, 2)->default(0);
            $table->decimal('net', 18, 2);
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payment_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('cash_bank_account_id')->constrained()->restrictOnDelete();
            $table->string('method', 10)->default('bank');
            $table->date('date');
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('payment_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_voucher_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_request_payee_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->string('reference', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_list_items');
        Schema::dropIfExists('payment_lists');
        Schema::dropIfExists('payment_vouchers');
        Schema::dropIfExists('payment_attachments');
        Schema::dropIfExists('payment_request_payees');
        Schema::dropIfExists('payment_request_lines');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('payment_settings');
    }
};
