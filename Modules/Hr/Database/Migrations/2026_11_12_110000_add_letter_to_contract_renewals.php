<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_renewals', function (Blueprint $table) {
            $table->text('letter')->nullable()->after('comment');
            $table->text('agreement')->nullable()->after('letter');
            $table->dateTime('agreed_at')->nullable()->after('agreement');
        });
    }

    public function down(): void
    {
        Schema::table('contract_renewals', fn (Blueprint $table) => $table->dropColumn(['letter', 'agreement', 'agreed_at']));
    }
};
