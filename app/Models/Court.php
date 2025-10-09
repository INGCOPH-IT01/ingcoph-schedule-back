<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    protected $fillable = [
        'name',
        'sport_id',
        'description',
        'price_per_hour',
        'location',
        'amenities',
        'is_active'
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'amenities' => 'array',
        'is_active' => 'boolean',
    ];

    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }
    public function images(): HasMany
    {
        return $this->hasMany(CourtImage::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
