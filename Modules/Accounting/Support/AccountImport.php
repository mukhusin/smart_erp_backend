<?php

namespace Modules\Accounting\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;

class AccountImport
{
    public const COLUMNS = ['code', 'name', 'type', 'parent_code', 'gfs_code', 'postable', 'budgetable', 'description'];

    public function run(string $path, bool $dryRun): array
    {
        $checked = $this->check($this->read($path));
        $valid = $checked->filter(fn ($r) => ! $r['errors']);
        $saved = ! $dryRun && $checked->every(fn ($r) => ! $r['errors']) ? $this->save($valid) : 0;

        return [
            'rows' => $checked->map(fn ($r) => ['line' => (int) $r['line'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'errors' => $r['errors']])->values()->all(),
            'valid' => $valid->count(), 'invalid' => $checked->count() - $valid->count(), 'saved' => $saved,
        ];
    }

    private function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim((string) $h, " \xEF\xBB\xBF")), fgetcsv($handle) ?: []);
        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (! array_filter($cells, fn ($c) => trim((string) $c) !== '')) {
                continue;
            }
            $r = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), '')) ?: [];
            $rows[] = ['line' => $line] + array_map(fn ($v) => trim((string) $v), $r) + array_fill_keys(self::COLUMNS, '');
        }
        fclose($handle);

        return $rows;
    }

    private function check(array $rows): Collection
    {
        $codes = array_column($rows, 'code');
        $counts = array_count_values(array_filter($codes));
        $existing = Account::pluck('code')->all();

        return collect($rows)->map(fn ($r) => ['line' => $r['line'], 'code' => $r['code'], 'name' => $r['name'], 'raw' => $r, 'errors' => array_values(array_filter([
            $r['code'] === '' ? 'Code is missing.' : null,
            $r['name'] === '' ? 'Name is missing.' : null,
            ! array_key_exists(strtolower($r['type']), Account::TYPES) ? 'Type must be asset, liability, equity, revenue or expense.' : null,
            $r['parent_code'] !== '' && ! in_array($r['parent_code'], $codes, true) && ! in_array($r['parent_code'], $existing, true) ? "Parent {$r['parent_code']} is not in the file or the chart." : null,
            $r['code'] !== '' && ($counts[$r['code']] ?? 0) > 1 ? 'Code appears twice in the file.' : null,
        ]))]);
    }

    private function save(Collection $valid): int
    {
        $yes = fn (string $v, bool $default) => $v === '' ? $default : in_array(strtolower($v), ['yes', 'y', '1', 'true'], true);

        return DB::transaction(function () use ($valid, $yes) {
            foreach ($valid as $r) {
                $raw = $r['raw'];
                $type = strtolower($raw['type']);
                Account::updateOrCreate(['code' => $raw['code']], [
                    'name' => $raw['name'], 'type' => $type, 'gfs_code' => $raw['gfs_code'] ?: null, 'description' => $raw['description'] ?: null,
                    'is_postable' => $yes($raw['postable'], true), 'is_budgetable' => $yes($raw['budgetable'], in_array($type, ['expense', 'revenue'], true)), 'is_active' => true,
                ]);
            }
            $ids = Account::pluck('id', 'code');
            foreach ($valid as $r) {
                if ($r['raw']['parent_code'] !== '') {
                    Account::where('code', $r['raw']['code'])->update(['parent_id' => $ids[$r['raw']['parent_code']] ?? null]);
                }
            }

            return $valid->count();
        });
    }
}
