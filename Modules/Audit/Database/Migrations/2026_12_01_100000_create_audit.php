<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('full_name', 150)->nullable()->after('username');
            $table->timestamp('access_expires_at')->nullable()->after('is_active');
        });

        Schema::create('audit_engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('firm', 200);
            $table->string('auditor_name', 150);
            $table->string('email', 150);
            $table->date('period_from');
            $table->date('period_to');
            $table->date('access_until');
            $table->boolean('payslips')->default(false);
            $table->text('scope_note')->nullable();
            $table->string('status', 12)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
        });

        Schema::create('audit_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('title', 200);
            $table->text('question');
            $table->string('reference', 200)->nullable();
            $table->string('link', 300)->nullable();
            $table->string('area', 30)->nullable();
            $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
            $table->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('open');
            $table->text('response')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'status']);
        });

        if (DB::table('erp_modules')->exists() && ! DB::table('erp_modules')->where('slug', 'audit')->exists()) {
            DB::table('erp_modules')->insert(['slug' => 'audit', 'name' => 'Audit', 'description' => 'Read-only access for internal and external auditors, audit checks and audit queries.',
                'icon' => 'bi-shield-check', 'color' => 'dark', 'route_name' => 'audit.dashboard', 'category' => 'Admin', 'sort_order' => 95, 'is_core' => false, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_queries');
        Schema::dropIfExists('audit_engagements');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['full_name', 'access_expires_at']));
        DB::table('erp_modules')->where('slug', 'audit')->delete();
    }
};
