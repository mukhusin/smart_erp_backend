<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 40)->nullable();
            $table->foreignId('output_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('input_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 10)->default('standard');
            $table->decimal('rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('tax_total', 18, 2)->default(0)->after('total');
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('tax_code_id')->nullable()->after('amount')->constrained()->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_code_id');
            $table->decimal('tax_amount', 18, 2)->default(0)->after('tax_rate');
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('tax_amount', 18, 2)->default(0)->after('amount');
        });
        Schema::table('receipt_lines', function (Blueprint $table) {
            $table->foreignId('tax_code_id')->nullable()->after('amount')->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 2)->default(0)->after('tax_code_id');
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->decimal('input_vat', 18, 2)->default(0)->after('withholding');
            $table->string('vat_invoice_no', 100)->nullable()->after('input_vat');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->decimal('invoice_vat', 18, 2)->default(0)->after('invoice_amount');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipts', fn (Blueprint $t) => $t->dropColumn('invoice_vat'));
        Schema::table('payment_vouchers', fn (Blueprint $t) => $t->dropColumn(['input_vat', 'vat_invoice_no']));
        Schema::table('receipt_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tax_code_id');
            $t->dropColumn('tax_amount');
        });
        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('tax_amount'));
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('tax_code_id');
            $t->dropColumn(['tax_rate', 'tax_amount']);
        });
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('tax_total'));
        Schema::dropIfExists('tax_codes');
        Schema::dropIfExists('vat_settings');
    }
};
