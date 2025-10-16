<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Sport extends Model
{
    protected $fillable = [
        'name',
        'description',
        'image',
        'icon',
        'price_per_hour',
        'is_active'
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    // Legacy one-to-many relationship (kept for backward compatibility)
    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    // New many-to-many relationship for multiple courts
    public function courtsMany(): BelongsToMany
    {
        return $this->belongsToMany(Court::class, 'court_sport');
    }
}
