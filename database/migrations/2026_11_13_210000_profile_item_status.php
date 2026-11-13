<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['employee_qualifications', 'employee_licences', 'employee_experiences', 'employee_dependants', 'employee_documents'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('status', 20)->default('approved')->index();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('decision_comment')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('submitted_by');
                $table->dropIndex(['status']);
                $table->dropColumn(['status', 'decision_comment']);
            });
        }
    }
};
