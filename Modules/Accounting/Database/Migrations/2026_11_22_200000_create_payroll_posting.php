<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const ACCOUNTS = ['salary', 'employer', 'net_pay', 'paye', 'nssf', 'nhif', 'heslb', 'wcf', 'sdl', 'loans', 'other'];

    public function up(): void
    {
        Schema::create('payroll_posting_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            foreach (self::ACCOUNTS as $a) {
                $table->foreignId("{$a}_account_id")->nullable()->constrained('accounts')->nullOnDelete();
            }
            $table->timestamps();
        });
        Schema::table('payroll_sheets', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_journal_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('posting_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_sheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
            $table->dropConstrainedForeignId('payment_journal_id');
            $table->dropColumn('posting_error');
        });
        Schema::dropIfExists('payroll_posting_settings');
    }
};
