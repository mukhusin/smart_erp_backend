<?php

namespace Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;

class TenderMember extends Model
{

    public const ROLES = ['opening' => 'Opening committee', 'evaluation' => 'Evaluation team', 'negotiation' => 'Negotiation team'];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function cleared(): bool
    {
        return $this->declared_at !== null && ! $this->conflict;
    }

    protected $fillable = ['tender_id', 'user_id', 'role', 'chair', 'declared_at', 'conflict', 'conflict_note'];

    protected $casts = ['chair' => 'boolean', 'declared_at' => 'datetime', 'conflict' => 'boolean'];
}
