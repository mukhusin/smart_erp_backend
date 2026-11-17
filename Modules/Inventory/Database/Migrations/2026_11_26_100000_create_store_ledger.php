<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('opening_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('donation_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedSmallInteger('expiry_warning_days')->default(90);
            $table->unsignedSmallInteger('slow_moving_days')->default(180);
            $table->timestamps();
        });

        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
            $table->date('last_issue_on')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'item_id']);
        });

        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('serial', 100)->nullable();
            $table->date('received_on');
            $table->decimal('quantity_in', 18, 4);
            $table->decimal('quantity_left', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();
            $table->index(['store_id', 'item_id', 'quantity_left']);
            $table->index(['company_id', 'expiry_date']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_layer_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('type', 20);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('value', 18, 2);
            $table->decimal('balance_quantity', 18, 4);
            $table->decimal('balance_value', 18, 2);
            $table->string('reference', 40)->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['store_id', 'item_id', 'id']);
            $table->index(['company_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('store_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 500)->nullable();
            $table->date('needed_by')->nullable();
            $table->string('status', 15)->default('draft');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('store_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('issued_quantity', 18, 4)->default(0);
            $table->string('notes', 255)->nullable();
        });

        Schema::create('stock_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('kind', 12);
            $table->string('reason', 15)->nullable();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('to_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->foreignId('store_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->string('notes', 1000)->nullable();
            $table->string('status', 15)->default('draft');
            $table->decimal('value', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_documents')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'kind', 'status']);
        });

        Schema::create('stock_document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_requisition_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_layer_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('base_quantity', 18, 4);
            $table->decimal('system_quantity', 18, 4)->nullable();
            $table->decimal('received_quantity', 18, 4)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('value', 18, 2)->default(0);
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['stock_document_lines', 'stock_documents', 'store_requisition_lines', 'store_requisitions', 'stock_movements', 'stock_layers', 'stock_balances', 'inventory_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
