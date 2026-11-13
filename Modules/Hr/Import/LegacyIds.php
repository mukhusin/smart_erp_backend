<?php

namespace Modules\Hr\Import;

use Illuminate\Support\Facades\DB;

/** HRMS id => ERP id per table for one company, so re-runs update the same rows. */
class LegacyIds
{
    /** @var array<string, array<int, int>> */
    private array $cache = [];

    public function __construct(private readonly int $companyId, private readonly string $source = 'hrms') {}

    public function get(string $table, mixed $legacyId): ?int
    {
        if ($legacyId === null || $legacyId === '' || (int) $legacyId === 0) {
            return null;
        }

        return $this->all($table)[(int) $legacyId] ?? null;
    }

    /** @return array<int, int> */
    public function all(string $table): array
    {
        return $this->cache[$table] ??= DB::table('legacy_ids')->where('company_id', $this->companyId)->where('source', $this->source)
            ->where('table_name', $table)->pluck('target_id', 'legacy_id')->map(fn ($v) => (int) $v)->all();
    }

    /** @return array<int, string> HRMS id => source updated_at stored at the last copy */
    public function stamps(string $table): array
    {
        return DB::table('legacy_ids')->where('company_id', $this->companyId)->where('source', $this->source)->where('table_name', $table)
            ->pluck('updated_at', 'legacy_id')->map(fn ($v) => (string) $v)->all();
    }

    /** @param list<array{legacy: int, target: int, stamp: string|null}> $rows */
    public function insertMany(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('legacy_ids')->insert(array_map(fn ($r) => ['company_id' => $this->companyId, 'source' => $this->source, 'table_name' => $table,
                'legacy_id' => $r['legacy'], 'target_id' => $r['target'], 'created_at' => now(), 'updated_at' => $r['stamp'] ?? now()], $chunk));
        }
        unset($this->cache[$table]);
    }

    public function touch(string $table, int $legacyId, ?string $stamp): void
    {
        DB::table('legacy_ids')->where('company_id', $this->companyId)->where('source', $this->source)->where('table_name', $table)
            ->where('legacy_id', $legacyId)->update(['updated_at' => $stamp ?? now()]);
    }

    public function set(string $table, int $legacyId, int $targetId): void
    {
        $this->all($table);
        if (($this->cache[$table][$legacyId] ?? null) === $targetId) {
            return;
        }
        DB::table('legacy_ids')->updateOrInsert(
            ['company_id' => $this->companyId, 'source' => $this->source, 'table_name' => $table, 'legacy_id' => $legacyId],
            ['target_id' => $targetId, 'updated_at' => now(), 'created_at' => now()],
        );
        $this->cache[$table][$legacyId] = $targetId;
    }
}
