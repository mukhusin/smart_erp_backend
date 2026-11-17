<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = ['assets', 'asset_locations', 'asset_categories'];

    public function up(): void
    {
        foreach (self::LEGACY as $legacy) {
            if (Schema::hasTable($legacy) && ! Schema::hasColumn($legacy, 'dep_from')) {
                if (DB::table($legacy)->exists()) {
                    throw new RuntimeException("Legacy table {$legacy} has data; move it before installing Fixed Assets.");
                }
                Schema::disableForeignKeyConstraints();
                Schema::drop($legacy);
                Schema::enableForeignKeyConstraints();
            }
        }

        Schema::create('asset_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('number_pattern', 60)->default('{CAT}-{YEAR}-{SEQ}');
            $table->string('depreciation_start', 12)->default('in_use');
            $table->foreignId('opening_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('donation_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('gain_loss_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('revaluation_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('impairment_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedTinyInteger('confirmation_month')->nullable();
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->foreignId('item_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_class', 20)->default('equipment');
            $table->json('details')->nullable();
            $table->string('acquisition_type', 12)->default('purchase');
            $table->date('acquisition_date');
            $table->date('in_use_date')->nullable();
            $table->decimal('cost', 18, 2)->default(0);
            $table->decimal('opening_depreciation', 18, 2)->default(0);
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->decimal('residual_value', 18, 2)->default(0);
            $table->foreignId('fund_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number', 100)->nullable();
            $table->string('tag', 100)->nullable();
            $table->foreignId('company_branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 200)->nullable();
            $table->string('condition', 12)->default('new');
            $table->string('status', 20)->default('draft');
            $table->date('dep_from')->nullable();
            $table->decimal('dep_value', 18, 2)->nullable();
            $table->date('dep_until')->nullable();
            $table->date('dep_charged_to')->nullable();
            $table->unsignedSmallInteger('confirmed_year')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'employee_id']);
        });

        Schema::create('asset_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('kind', 20);
            $table->decimal('cost', 18, 2)->default(0);
            $table->decimal('depreciation', 18, 2)->default(0);
            $table->string('description', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->nullableMorphs('source');
            $table->timestamps();
            $table->index(['asset_id', 'date']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location', 200)->nullable();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->string('condition_out', 12)->nullable();
            $table->string('condition_in', 12)->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('returned_from', 12)->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('asset_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 15);
            $table->date('date');
            $table->decimal('amount', 18, 2)->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->foreignId('credit_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('valuer', 150)->nullable();
            $table->text('reason');
            $table->decimal('book_value', 18, 2)->nullable();
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12)->default('internal');
            $table->boolean('permanent')->default(true);
            $table->date('date');
            $table->date('return_date')->nullable();
            $table->foreignId('to_branch_id')->nullable()->constrained('company_branches')->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('to_location', 200)->nullable();
            $table->string('to_organisation', 200)->nullable();
            $table->json('previous')->nullable();
            $table->text('reason');
            $table->string('status', 15)->default('pending');
            $table->timestamp('returned_at')->nullable();
            $table->date('reminded_on')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('method', 12);
            $table->date('date');
            $table->decimal('proceeds', 18, 2)->default(0);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('buyer', 200)->nullable();
            $table->text('reason');
            $table->string('minutes_path')->nullable();
            $table->string('minutes_name')->nullable();
            $table->decimal('book_value', 18, 2)->nullable();
            $table->decimal('gain_loss', 18, 2)->nullable();
            $table->string('status', 15)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_end');
            $table->string('status', 10)->default('posted');
            $table->unsignedInteger('assets')->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'period_end']);
        });

        Schema::create('asset_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('condition', 12)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 12)->default('open');
            $table->text('response')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        DB::table('erp_modules')->where('slug', 'assets')->update(['name' => 'Fixed Assets', 'description' => 'Asset register, holders, depreciation, transfers, revaluation and disposal.']);
    }

    public function down(): void
    {
        foreach (['asset_reports', 'depreciation_runs', 'asset_disposals', 'asset_transfers', 'asset_changes', 'asset_assignments', 'asset_entries', 'assets', 'asset_settings'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::table('erp_modules')->where('slug', 'assets')->delete();
    }
};
