<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('clearing_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('direct_limit', 18, 2)->default(1000000);
            $table->unsignedTinyInteger('min_quotes')->default(3);
            $table->decimal('match_tolerance_percent', 5, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('purpose')->nullable();
            $table->string('kind', 10)->default('normal');
            $table->text('special_reason')->nullable();
            $table->date('needed_by')->nullable();
            $table->string('delivery_place', 200)->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 15)->default('draft');
            $table->boolean('over_budget')->default(false);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->text('specification')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('estimated_unit_cost', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('quotation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->date('closing_date');
            $table->text('instructions')->nullable();
            $table->string('status', 15)->default('open');
            $table->text('award_comment')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->date('valid_until')->nullable();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('responsive')->default(true);
            $table->string('path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->timestamps();
            $table->unique(['quotation_request_id', 'supplier_id']);
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('unit_price', 18, 2);
            $table->decimal('quantity', 18, 4);
            $table->decimal('amount', 18, 2);
            $table->boolean('awarded')->default(false);
            $table->string('remarks', 255)->nullable();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 15)->default('quotation');
            $table->text('method_reason')->nullable();
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('delivery_place', 200)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->string('status', 15)->default('draft');
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->decimal('received_quantity', 18, 4)->default(0);
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delivery_note', 100)->nullable();
            $table->date('received_on');
            $table->text('notes')->nullable();
            $table->string('status', 15)->default('draft');
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('supplier_invoice', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('invoice_amount', 18, 2)->nullable();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->cascadeOnDelete();
            $table->decimal('delivered_quantity', 18, 4);
            $table->decimal('accepted_quantity', 18, 4);
            $table->decimal('rejected_quantity', 18, 4)->default(0);
            $table->string('rejection_reason', 255)->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('expiry_date')->nullable();
            $table->json('serials')->nullable();
            $table->decimal('amount', 18, 2)->default(0);
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('requested_by');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->foreignId('supplier_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });
        Schema::table('payment_request_lines', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('budget_item_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_request_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('account_id'));
        Schema::table('payment_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('supplier_id');
            $t->dropColumn(['source_type', 'source_id']);
        });
        foreach (['goods_receipt_lines', 'goods_receipts', 'purchase_order_lines', 'purchase_orders', 'quotation_lines', 'quotations', 'quotation_requests', 'purchase_requisition_lines', 'purchase_requisitions', 'procurement_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
