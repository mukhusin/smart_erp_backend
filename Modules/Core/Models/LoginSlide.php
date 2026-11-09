<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Login page announcement. Not company-scoped by trait: platform-wide slides have no company. */
class LoginSlide extends Model
{
    protected $fillable = ['company_id', 'title', 'content', 'image', 'sort_order', 'is_active', 'starts_at', 'ends_at', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];

    /** Active now, for one company (plus platform-wide slides), in display order. */
    public function scopeVisibleFor(Builder $query, ?int $companyId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('company_id')->when($companyId, fn ($q) => $q->orWhere('company_id', $companyId)))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->orderByRaw('company_id is null')->orderBy('sort_order')->orderBy('id');
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http') ? $this->image : '/storage/' . $this->image;
    }
}
