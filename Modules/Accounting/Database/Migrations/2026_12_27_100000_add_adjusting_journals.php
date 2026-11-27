<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('adjustment', 20)->nullable()->after('entry_type');
            $table->date('reverse_on')->nullable()->after('adjustment');
            $table->index(['status', 'reverse_on']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['status', 'reverse_on']);
            $table->dropColumn(['adjustment', 'reverse_on']);
        });
    }
};
