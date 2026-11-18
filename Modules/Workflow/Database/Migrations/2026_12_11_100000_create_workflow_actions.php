<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_template_id')->constrained()->cascadeOnDelete();
            $table->string('event', 15);
            $table->unsignedSmallInteger('step_order')->nullable();
            $table->decimal('min_amount', 18, 2)->nullable();
            $table->string('action', 10);
            $table->string('target', 20)->nullable();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('channels')->nullable();
            $table->unsignedSmallInteger('after_days')->nullable();
            $table->string('message', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->text('secret')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['workflow_template_id', 'event']);
        });

        Schema::create('workflow_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_action_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('workflow_instance_step_id')->nullable()->index();
            $table->string('event', 15);
            $table->string('status', 10);
            $table->string('detail', 500)->nullable();
            $table->timestamps();
            $table->index(['workflow_action_id', 'created_at']);
        });

        Schema::table('workflow_instance_steps', function (Blueprint $table) {
            $table->timestamp('pending_since')->nullable();
            $table->foreignId('escalated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
        });
        DB::table('workflow_instance_steps')->where('status', 'pending')->update(['pending_since' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('workflow_instance_steps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('escalated_user_id');
            $table->dropConstrainedForeignId('escalated_role_id');
            $table->dropColumn(['pending_since', 'escalated_at']);
        });
        Schema::dropIfExists('workflow_action_logs');
        Schema::dropIfExists('workflow_actions');
    }
};
