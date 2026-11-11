<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banks', function (Blueprint $table) {
            $table->dropUnique(['code']);
        });
        Schema::table('banks', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20)->nullable()->change();
            $table->string('swift_code', 20)->nullable()->after('code');
        });
        Schema::table('employee_bank_accounts', function (Blueprint $table) {
            $table->index(['account_number']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_bank_accounts', fn (Blueprint $table) => $table->dropIndex(['account_number']));
        Schema::table('banks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('swift_code');
            $table->unique(['code']);
        });
    }
};
