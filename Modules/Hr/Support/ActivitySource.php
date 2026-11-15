<?php

namespace Modules\Hr\Support;

use App\Models\AllowanceCategory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads the number of activities on a day from another system over REST, as configured on the allowance category:
 * url, params (values may use {date}), optional token, and either count_path (a number in the JSON) or list_path +
 * count_field summed over the list, optionally only items whose id_field is in ids.
 */
class ActivitySource
{
    /** @return array{count: float|null, error: string|null, raw: string|null} */
    public function fetch(AllowanceCategory $category, Carbon|string $date): array
    {
        $c = $category->activity_config ?? [];
        if (empty($c['url'])) {
            return ['count' => null, 'error' => 'No address is set for the source.', 'raw' => null];
        }
        $day = Carbon::parse($date)->toDateString();
        $params = collect($c['params'] ?? [])->map(fn ($v) => str_replace('{date}', $day, (string) $v))->all();

        try {
            $request = Http::timeout(30)->acceptJson();
            if (! empty($c['token'])) {
                $request = $request->withToken($c['token']);
            }
            $response = $request->get($c['url'], $params);
        } catch (\Throwable $e) {
            return ['count' => null, 'error' => 'Could not reach the source: '.Str::limit($e->getMessage(), 200), 'raw' => null];
        }
        $raw = Str::limit($response->body(), 20000, '');
        if (! $response->successful()) {
            return ['count' => null, 'error' => "The source answered with HTTP {$response->status()}.", 'raw' => $raw];
        }
        $json = $response->json();
        if (! is_array($json)) {
            return ['count' => null, 'error' => 'The source did not answer with JSON.', 'raw' => $raw];
        }

        if (! empty($c['count_path'])) {
            $value = data_get($json, $c['count_path']);

            return is_numeric($value) ? ['count' => (float) $value, 'error' => null, 'raw' => $raw]
                : ['count' => null, 'error' => "No number at \"{$c['count_path']}\" in the answer.", 'raw' => $raw];
        }

        $list = data_get($json, $c['list_path'] ?? '');
        if (! is_array($list)) {
            return ['count' => null, 'error' => 'No list at "'.($c['list_path'] ?? '').'" in the answer.', 'raw' => $raw];
        }
        $ids = collect($c['ids'] ?? [])->map(fn ($id) => (string) $id)->flip();
        $total = 0.0;
        foreach ($list as $item) {
            if (! empty($c['id_field']) && $ids->isNotEmpty() && ! $ids->has((string) data_get($item, $c['id_field']))) {
                continue;
            }
            $total += (float) data_get($item, $c['count_field'] ?? 'count', 0);
        }

        return ['count' => $total, 'error' => null, 'raw' => $raw];
    }
}
