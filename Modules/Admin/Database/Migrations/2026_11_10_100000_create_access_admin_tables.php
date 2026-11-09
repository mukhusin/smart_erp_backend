<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 500)->nullable()->after('guard_name');
            /** Created with the company (company-admin, hr-officer...). company-admin cannot be edited or deleted. */
            $table->boolean('is_system')->default(false)->after('description');
        });

        /*
         * Every change to a user's access (roles, direct permissions, activation) is recorded here first.
         * Today changes are applied at once (status "applied"). When approval is switched on, new changes are
         * "pending" until approved, then applied by the same action.
         */
        Schema::create('access_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            /** roles | permissions | deactivate | activate */
            $table->string('type', 30);
            /** Before and after (e.g. role names), so the history reads without joins. */
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason', 500)->nullable();
            /** pending | applied | rejected */
            $table->string('status', 20)->default('applied');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('applied_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_changes');
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn(['description', 'is_system']));
    }
};
