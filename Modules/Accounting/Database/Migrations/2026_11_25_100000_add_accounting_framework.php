<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->string('framework', 15)->default('ifrs_sme')->after('company_id');
            $table->string('framework_name', 100)->nullable()->after('framework');
            $table->json('policies')->nullable()->after('framework_name');
        });
        Schema::create('accounting_policy_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('framework', 15);
            $table->string('framework_name', 100)->nullable();
            $table->json('policies');
            $table->date('effective_from');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'effective_from']);
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('book', 20)->default('primary')->after('entry_type');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', fn (Blueprint $t) => $t->dropColumn('book'));
        Schema::dropIfExists('accounting_policy_changes');
        Schema::table('accounting_settings', fn (Blueprint $t) => $t->dropColumn(['framework', 'framework_name', 'policies']));
    }
};
