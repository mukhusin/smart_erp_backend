<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_renewals', function (Blueprint $table) {
            $table->string('letter_language', 5)->nullable()->after('letter');
        });
    }

    public function down(): void
    {
        Schema::table('contract_renewals', fn (Blueprint $table) => $table->dropColumn('letter_language'));
    }
};
