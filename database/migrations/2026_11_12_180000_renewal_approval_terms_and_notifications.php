<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        Schema::create('contract_renewal_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('contract_renewal_id')->constrained('contract_renewals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('step_name')->nullable();
            $table->json('before');
            $table->json('after');
            $table->string('comment', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('renewal_letter_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('language', 5);
            $table->string('subject', 200);
            $table->string('salutation', 200)->nullable();
            $table->string('closing', 100);
            $table->string('number_label', 60);
            $table->string('department_label', 60);
            $table->string('section_label', 60);
            $table->string('position_label', 60);
            $table->boolean('show_section')->default(true);
            $table->boolean('show_position')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_letter_templates');
        Schema::dropIfExists('contract_renewal_changes');
    }
};
