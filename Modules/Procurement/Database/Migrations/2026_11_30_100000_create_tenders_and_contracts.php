<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procurement_settings', function (Blueprint $table) {
            $table->foreignId('retention_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('damages_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('advance_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->decimal('variation_limit_percent', 5, 2)->default(15);
            $table->unsignedSmallInteger('expiry_warning_days')->default(30);
            $table->unsignedSmallInteger('award_acceptance_days')->default(7);
        });

        Schema::create('procurement_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('sourcing', 12);
            $table->string('category', 15)->nullable();
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->decimal('max_amount', 18, 2)->nullable();
            $table->unsignedTinyInteger('min_bidders')->default(1);
            $table->string('evaluation', 12)->default('lowest');
            $table->unsignedTinyInteger('technical_weight')->default(80);
            $table->unsignedTinyInteger('pass_mark')->default(70);
            $table->boolean('negotiation')->default(false);
            $table->boolean('highest_wins')->default(false);
            $table->unsignedSmallInteger('standstill_days')->default(0);
            $table->boolean('needs_reason')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('tenders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $table->foreignId('procurement_method_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('document_type', 20)->default('goods');
            $table->text('instructions')->nullable();
            $table->text('method_reason')->nullable();
            $table->dateTime('closing_at');
            $table->unsignedSmallInteger('validity_days')->default(90);
            $table->decimal('bid_security_amount', 18, 2)->nullable();
            $table->string('status', 20)->default('draft');
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('opening_attendees')->nullable();
            $table->text('evaluation_summary')->nullable();
            $table->text('negotiation_minutes')->nullable();
            $table->date('standstill_until')->nullable();
            $table->text('decision_comment')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('tender_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->constrained()->cascadeOnDelete();
            $table->string('lot', 60)->default('Lot 1');
            $table->decimal('quantity', 18, 4);
        });

        Schema::create('tender_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('kind', 12)->default('mandatory');
            $table->decimal('weight', 6, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
        });

        Schema::create('tender_clarifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15)->default('clarification');
            $table->text('question')->nullable();
            $table->text('answer');
            $table->dateTime('closing_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('tender_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('evaluation');
            $table->boolean('chair')->default(false);
            $table->timestamp('declared_at')->nullable();
            $table->boolean('conflict')->default(false);
            $table->string('conflict_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['tender_id', 'user_id', 'role']);
        });

        Schema::create('tender_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->dateTime('received_at');
            $table->string('currency', 3)->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->string('bid_security', 200)->nullable();
            $table->boolean('documents_complete')->default(true);
            $table->string('missing_documents', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->decimal('read_out_total', 18, 2)->nullable();
            $table->boolean('responsive')->nullable();
            $table->decimal('technical_score', 6, 2)->nullable();
            $table->decimal('financial_score', 6, 2)->nullable();
            $table->decimal('combined_score', 6, 2)->nullable();
            $table->unsignedSmallInteger('rank')->nullable();
            $table->boolean('withdrawn')->default(false);
            $table->timestamps();
            $table->unique(['tender_id', 'supplier_id']);
        });

        Schema::create('tender_bid_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_bid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_line_id')->constrained()->cascadeOnDelete();
            $table->text('unit_price');
        });

        Schema::create('tender_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_bid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tender_criterion_id')->constrained('tender_criteria')->cascadeOnDelete();
            $table->decimal('score', 6, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->string('comment', 500)->nullable();
            $table->foreignId('scored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tender_bid_id', 'tender_criterion_id']);
        });

        Schema::create('tender_awards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->string('lot', 60);
            $table->foreignId('tender_bid_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamp('letter_sent_at')->nullable();
            $table->date('accept_by')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tender_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->date('received_on');
            $table->text('description');
            $table->string('outcome', 12)->default('pending');
            $table->string('action', 12)->nullable();
            $table->text('decision')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('tender_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tender_award_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('type', 15)->default('lump_sum');
            $table->string('category', 15)->default('goods');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('currency', 3)->nullable();
            $table->decimal('exchange_rate', 14, 6)->default(1);
            $table->decimal('value', 18, 2)->default(0);
            $table->decimal('retention_percent', 5, 2)->default(0);
            $table->decimal('damages_percent_per_day', 6, 3)->default(0);
            $table->decimal('damages_cap_percent', 5, 2)->default(10);
            $table->string('security_type', 15)->nullable();
            $table->decimal('security_amount', 18, 2)->nullable();
            $table->string('security_reference', 100)->nullable();
            $table->date('security_expiry')->nullable();
            $table->unsignedTinyInteger('percent_complete')->default(0);
            $table->string('status', 15)->default('draft');
            $table->date('signed_on')->nullable();
            $table->date('warned_on')->nullable();
            $table->text('decision_comment')->nullable();
            $table->text('close_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('contract_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('amount', 18, 2);
            $table->foreignId('budget_item_id')->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 18, 4)->default(0);
        });

        Schema::create('contract_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('kind', 12)->default('delivery');
            $table->decimal('amount', 18, 2);
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('planned');
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contract_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_milestone_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('period', 100)->nullable();
            $table->unsignedTinyInteger('percent_complete')->nullable();
            $table->decimal('gross', 18, 2);
            $table->decimal('retention', 18, 2)->default(0);
            $table->decimal('damages', 18, 2)->default(0);
            $table->unsignedSmallInteger('days_late')->default(0);
            $table->decimal('advance_recovery', 18, 2)->default(0);
            $table->decimal('net', 18, 2);
            $table->text('description')->nullable();
            $table->string('path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('contract_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);
            $table->decimal('amount', 18, 2)->default(0);
            $table->foreignId('budget_item_id')->nullable()->constrained()->nullOnDelete();
            $table->date('new_end_date')->nullable();
            $table->text('reason');
            $table->string('status', 12)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('contract_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 15)->default('note');
            $table->unsignedTinyInteger('percent_complete')->nullable();
            $table->text('description');
            $table->string('path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('contract_id')->nullable()->after('quotation_id')->constrained()->nullOnDelete();
        });
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->foreignId('contract_line_id')->nullable()->after('purchase_requisition_line_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('contract_line_id'));
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('contract_id'));
        foreach (['contract_progress', 'contract_variations', 'contract_certificates', 'contract_milestones', 'contract_lines', 'contracts', 'tender_complaints', 'tender_awards',
            'tender_scores', 'tender_bid_lines', 'tender_bids', 'tender_members', 'tender_clarifications', 'tender_criteria', 'tender_lines', 'tenders', 'procurement_methods'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('procurement_settings', function (Blueprint $table) {
            foreach (['retention_account_id', 'damages_account_id', 'advance_account_id'] as $c) {
                $table->dropConstrainedForeignId($c);
            }
            $table->dropColumn(['variation_limit_percent', 'expiry_warning_days', 'award_acceptance_days']);
        });
    }
};
