<?php

use App\Models\Company;
use App\Services\CompanyContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Hr\Support\DefaultLeaveTypes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->string('status', 15)->default('draft');
            $table->unsignedSmallInteger('revision')->default(0);
            $table->text('decision_comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'budget_id']);
        });

        Schema::create('procurement_plan_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procurement_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description', 255);
            $table->string('category', 15)->default('goods');
            $table->foreignId('procurement_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->decimal('estimated_unit_cost', 18, 2)->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->unsignedTinyInteger('quarter')->default(1);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->index(['procurement_plan_id', 'department_id']);
        });

        Schema::table('purchase_requisition_lines', function (Blueprint $table) {
            $table->foreignId('procurement_plan_line_id')->nullable()->after('budget_item_id')->constrained()->nullOnDelete();
        });
        Schema::table('purchase_requisitions', function (Blueprint $table) {
            $table->boolean('unplanned')->default(false)->after('over_budget');
        });
        Schema::table('procurement_settings', function (Blueprint $table) {
            $table->string('plan_check', 5)->default('off');
        });

        foreach (Company::pluck('id') as $companyId) {
            app(CompanyContext::class)->run($companyId, fn () => DefaultLeaveTypes::procurement());
        }
    }

    public function down(): void
    {
        Schema::table('procurement_settings', fn (Blueprint $t) => $t->dropColumn('plan_check'));
        Schema::table('purchase_requisitions', fn (Blueprint $t) => $t->dropColumn('unplanned'));
        Schema::table('purchase_requisition_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('procurement_plan_line_id'));
        Schema::dropIfExists('procurement_plan_lines');
        Schema::dropIfExists('procurement_plans');
    }
};
