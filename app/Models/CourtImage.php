<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourtImage extends Model
{
    protected $fillable = [
        'court_id',
        'image_url',
        'image_name',
        'image_path',
        'image_type',
        'image_size'
    ];

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    /**
     * Get the full URL for the image
     */
    public function getImageUrlAttribute($value)
    {
        if ($value) {
            return asset('storage/' . $value);
        }
        return null;
    }
}
