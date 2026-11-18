<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_vouchers', function (Blueprint $table) {
            $table->decimal('input_vat', 18, 2)->default(0)->after('amount');
            $table->string('vat_invoice_no', 100)->nullable()->after('input_vat');
        });
        Schema::table('imprest_retirement_lines', function (Blueprint $table) {
            $table->decimal('input_vat', 18, 2)->default(0)->after('amount');
            $table->string('vat_invoice_no', 100)->nullable()->after('input_vat');
        });
    }

    public function down(): void
    {
        Schema::table('imprest_retirement_lines', fn (Blueprint $t) => $t->dropColumn(['input_vat', 'vat_invoice_no']));
        Schema::table('petty_cash_vouchers', fn (Blueprint $t) => $t->dropColumn(['input_vat', 'vat_invoice_no']));
    }
};
