<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'media',
        'is_active',
        'auto_popup_enabled',
        'auto_popup_interval_hours',
        'display_order',
        'published_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'media' => 'array',
        'is_active' => 'boolean',
        'auto_popup_enabled' => 'boolean',
        'auto_popup_interval_hours' => 'integer',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the user who created this promotion
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to get only active promotions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('published_at')
                  ->orWhere('published_at', '<=', now());
            })
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to order by display order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc')
                     ->orderBy('created_at', 'desc');
    }
}
