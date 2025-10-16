<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    protected $fillable = [
        'name',
        'sport_id',
        'description',
        'location',
        'amenities',
        'is_active'
    ];

    protected $casts = [
        'amenities' => 'array',
        'is_active' => 'boolean',
    ];

    // Legacy single sport relationship (kept for backward compatibility)
    public function sport(): BelongsTo
    {
        return $this->belongsTo(Sport::class);
    }

    // New many-to-many relationship for multiple sports
    public function sports(): BelongsToMany
    {
        return $this->belongsToMany(Sport::class, 'court_sport');
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
