<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivable_settings', function (Blueprint $table) {
            $table->string('credit_check', 5)->default('off');
            $table->unsignedSmallInteger('credit_overdue_days')->nullable();
            $table->boolean('claims_enabled')->default(false);
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('on_hold')->default(false)->after('credit_limit');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('over_limit')->default(false)->after('status');
            $table->string('beneficiary', 150)->nullable()->after('reference');
            $table->string('member_no', 60)->nullable()->after('beneficiary');
        });

        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('period_from');
            $table->date('period_to');
            $table->string('status', 15)->default('draft');
            $table->string('payer_reference', 100)->nullable();
            $table->decimal('claimed', 18, 2)->default(0);
            $table->decimal('approved', 18, 2)->default(0);
            $table->decimal('rejected', 18, 2)->default(0);
            $table->date('submitted_on')->nullable();
            $table->date('responded_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('insurance_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insurance_claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('claimed', 18, 2);
            $table->decimal('approved', 18, 2)->nullable();
            $table->decimal('rejected', 18, 2)->default(0);
            $table->string('rejection_reason', 500)->nullable();
            $table->string('resolution', 10)->nullable();
            $table->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rebill_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
            $table->unique(['insurance_claim_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claim_lines');
        Schema::dropIfExists('insurance_claims');
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['over_limit', 'beneficiary', 'member_no']));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('on_hold'));
        Schema::table('receivable_settings', fn (Blueprint $t) => $t->dropColumn(['credit_check', 'credit_overdue_days', 'claims_enabled']));
    }
};
