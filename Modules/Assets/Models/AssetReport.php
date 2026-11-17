<?php

namespace Modules\Assets\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AssetReport extends Model
{
    use BelongsToCompany;

    public const TYPES = ['fault' => 'Fault or damage', 'return' => 'Return'];

    public const STATUS_LABELS = ['open' => 'Open', 'repair' => 'In repair', 'resolved' => 'Resolved', 'suspended' => 'Asset suspended', 'confirmed' => 'Return confirmed', 'declined' => 'Declined'];

    protected $fillable = ['company_id', 'asset_id', 'type', 'reported_by', 'description', 'condition', 'photo_path', 'status', 'response', 'handled_by', 'handled_at'];

    protected $casts = ['handled_at' => 'datetime'];

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
