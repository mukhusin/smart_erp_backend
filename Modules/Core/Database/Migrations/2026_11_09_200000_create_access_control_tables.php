<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permission category = ERP module (erp_modules.slug). Names stay "<module>.<action>".
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module_slug', 60)->nullable()->after('guard_name')->index();
            $table->string('label')->nullable()->after('module_slug');
            $table->string('description')->nullable()->after('label');
        });

        // A user temporarily acts in another user's role (e.g. HOD on leave), for a date range.
        Schema::create('role_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['to_user_id', 'starts_at', 'ends_at']);
        });

        // Inside a company: block a module for one branch or one user (exactly one of the two is set).
        Schema::create('module_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('module_slug', 60);
            $table->foreignId('company_branch_id')->nullable()->constrained('company_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'module_slug', 'company_branch_id', 'user_id'], 'module_locks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_locks');
        Schema::dropIfExists('role_handovers');
        Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn(['module_slug', 'label', 'description']));
    }
};
