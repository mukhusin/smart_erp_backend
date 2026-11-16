<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_list_items', function (Blueprint $table) {
            $table->string('status', 10)->default('paid')->after('reference');
            $table->string('failure_reason', 255)->nullable()->after('status');
            $table->foreignId('reversal_journal_id')->nullable()->after('failure_reason')->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('settled_at')->nullable()->after('reversal_journal_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_list_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropColumn(['status', 'failure_reason', 'settled_at']);
        });
    }
};
