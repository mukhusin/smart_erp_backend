<?php

namespace Modules\Appraisals\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AppraisalCycle extends Model
{
    use Auditable, BelongsToCompany;

    public const STATUS_LABELS = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'];

    protected $fillable = ['company_id', 'name', 'start_date', 'end_date', 'mid_year_date', 'self_due', 'review_due', 'status', 'config', 'created_by', 'opened_at', 'closed_at'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'mid_year_date' => 'date', 'self_due' => 'date', 'review_due' => 'date', 'config' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];

    public function appraisals()
    {
        return $this->hasMany(Appraisal::class);
    }

    public function parts(): array
    {
        return array_values(array_filter($this->config['parts'] ?? AppraisalSetting::PARTS, fn ($p) => $p['enabled'] ?? true));
    }

    public function scale(): string
    {
        return $this->config['scale'] ?? 'percent';
    }

    public function band(?float $score): ?string
    {
        if ($score === null) {
            return null;
        }
        foreach (collect($this->config['bands'] ?? AppraisalSetting::BANDS)->sortByDesc('min') as $b) {
            if ($score >= $b['min']) {
                return $b['label'];
            }
        }

        return null;
    }
}
