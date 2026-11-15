<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('allowance_categories', function (Blueprint $table) {
            /** manual | rest: where the daily count of an activity allowance comes from. */
            $table->string('activity_source', 20)->nullable()->after('generation');
            /** Encrypted REST settings: url, params, token, count_path or list_path + count_field (+ id_field, ids). */
            $table->text('activity_config')->nullable()->after('activity_source');
        });

        Schema::create('allowance_activity_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('allowance_category_id')->constrained('allowance_categories')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('count', 12, 2)->nullable();
            /** manual | rest */
            $table->string('source', 20);
            /** ok | failed */
            $table->string('status', 20)->default('ok');
            $table->string('error', 500)->nullable();
            $table->text('raw')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['allowance_category_id', 'date']);
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_activity_counts');
        Schema::table('allowance_categories', fn (Blueprint $table) => $table->dropColumn(['activity_source', 'activity_config']));
    }
};
