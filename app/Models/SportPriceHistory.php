<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportPriceHistory extends Model
{
    protected $fillable = [
        'sport_id',
        'change_type',
        'changed_by',
        'old_value',
        'new_value',
        'effective_date',
        'description'
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'effective_date' => 'datetime',
    ];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
