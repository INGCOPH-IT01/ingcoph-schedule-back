<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidaySportPricing extends Model
{
    protected $table = 'holiday_sport_pricing';

    protected $fillable = [
        'holiday_id',
        'sport_id',
        'price_per_hour',
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
    ];

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(Holiday::class);
    }

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
}
