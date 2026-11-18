<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Driving licences live on the HR profile (employee_licences); the fleet keeps no copy. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professional_licences', function (Blueprint $table) {
            $table->boolean('is_driving')->default(false)->after('short_name');
        });
        Schema::table('employee_licences', function (Blueprint $table) {
            $table->json('classes')->nullable()->after('licence_no');
        });
        DB::table('professional_licences')->where(fn ($q) => $q->where('name', 'like', '%driv%')->orWhere('name', 'like', '%udereva%'))->update(['is_driving' => true]);

        if (Schema::hasTable('fleet_driver_licences')) {
            $rows = DB::table('fleet_driver_licences as l')->join('fleet_drivers as d', 'd.id', '=', 'l.fleet_driver_id')
                ->select('l.*', 'd.employee_id', 'd.company_id')->orderBy('l.id')->get();
            foreach ($rows as $l) {
                $type = DB::table('professional_licences')->where('company_id', $l->company_id)->where('is_driving', true)->whereNull('deleted_at')->value('id')
                    ?? DB::table('professional_licences')->insertGetId(['company_id' => $l->company_id, 'name' => 'Driving Licence', 'is_driving' => true, 'is_active' => true,
                        'created_at' => now(), 'updated_at' => now()]);
                DB::table('employee_licences')->insert(['employee_id' => $l->employee_id, 'professional_licence_id' => $type, 'licence_no' => $l->number, 'classes' => $l->classes,
                    'start_date' => $l->issued_on ?? $l->created_at, 'end_date' => $l->expires_on, 'file_path' => $l->document_path, 'file_name' => $l->document_name,
                    'status' => 'approved', 'created_at' => $l->created_at, 'updated_at' => $l->updated_at]);
            }
            Schema::drop('fleet_driver_licences');
        }
    }

    public function down(): void
    {
        Schema::table('employee_licences', fn (Blueprint $table) => $table->dropColumn('classes'));
        Schema::table('professional_licences', fn (Blueprint $table) => $table->dropColumn('is_driving'));
    }
};
