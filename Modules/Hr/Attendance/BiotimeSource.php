<?php

namespace Modules\Hr\Attendance;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/** ZKTeco BioTime: reads `iclock_transaction` from the BioTime database (as HRMS did), with a short connection timeout. */
class BiotimeSource implements PunchSource
{
    private string $name;

    /** @param array{host: string, port?: int|string|null, database: string, username: string, password?: string|null} $connection */
    public function __construct(array $connection, int $companyId)
    {
        $this->name = "biotime_{$companyId}";
        Config::set("database.connections.{$this->name}", [
            'driver' => 'mysql', 'host' => $connection['host'], 'port' => $connection['port'] ?? 3306, 'database' => $connection['database'],
            'username' => $connection['username'], 'password' => $connection['password'] ?? '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => false, 'options' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        DB::purge($this->name);
    }

    public function reachable(): bool
    {
        try {
            DB::connection($this->name)->select('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function punches(Carbon $date): iterable
    {
        $lastId = 0;
        do {
            $rows = DB::connection($this->name)->table('iclock_transaction')
                ->whereBetween('punch_time', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])->where('id', '>', $lastId)->orderBy('id')->limit(2000)
                ->get(['id', 'emp_code', 'punch_time', 'punch_state', 'verify_type', 'area_alias', 'terminal_sn', 'terminal_alias']);
            foreach ($rows as $r) {
                yield ['emp_code' => (string) $r->emp_code, 'punch_time' => (string) $r->punch_time, 'punch_state' => $r->punch_state, 'verify_type' => (string) $r->verify_type,
                    'area_alias' => $r->area_alias, 'terminal_sn' => $r->terminal_sn, 'terminal_alias' => $r->terminal_alias, 'external_id' => $r->id];
                $lastId = $r->id;
            }
        } while ($rows->count() === 2000);
    }
}
