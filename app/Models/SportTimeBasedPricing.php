<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportTimeBasedPricing extends Model
{
    protected $table = 'sport_time_based_pricing';

    protected $fillable = [
        'sport_id',
        'name',
        'start_time',
        'end_time',
        'price_per_hour',
        'days_of_week',
        'is_active',
        'priority',
        'effective_date'
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'is_active' => 'boolean',
        'days_of_week' => 'array',
        'priority' => 'integer',
        'effective_date' => 'datetime',
    ];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
