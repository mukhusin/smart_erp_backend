<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allowance_categories', function (Blueprint $table) {
            $table->string('generation', 10)->default('auto')->after('duration');
            $table->boolean('taxable')->default(true)->after('other_absence');
            $table->string('description', 500)->nullable()->after('taxable');
        });

        Schema::table('department_allowances', function (Blueprint $table) {
            $table->foreignId('job_position_id')->nullable()->change();
            $table->boolean('is_active')->default(true)->change();
            $table->foreignId('created_by')->nullable()->after('last_updated_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('daily_allowances', function (Blueprint $table) {
            $table->date('start_date')->change();
            $table->date('end_date')->change();
            $table->decimal('amount', 14, 2)->change();
            $table->decimal('amount_per_item', 14, 2)->nullable()->change();
            $table->decimal('items', 8, 2)->default(1)->change();
            $table->foreignId('allowance_category_id')->nullable()->after('department_allowance_id')->constrained('allowance_categories')->nullOnDelete();
            $table->string('source', 10)->default('auto')->after('status');
            $table->foreignId('requested_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('requested_by');
            $table->string('decision_comment', 500)->nullable()->after('decided_at');
            $table->index(['employee_id', 'start_date']);
            $table->index(['department_allowance_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_allowances', function (Blueprint $table) {
            $table->dropIndex(['employee_id', 'start_date']);
            $table->dropIndex(['department_allowance_id', 'start_date']);
            $table->dropConstrainedForeignId('allowance_category_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['source', 'decided_at', 'decision_comment']);
        });
        Schema::table('department_allowances', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        Schema::table('allowance_categories', fn (Blueprint $table) => $table->dropColumn(['generation', 'taxable', 'description']));
    }
};
