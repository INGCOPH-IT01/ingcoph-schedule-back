<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceivingReportItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'receiving_report_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    /**
     * Get the receiving report that owns the item.
     */
    public function receivingReport()
    {
        return $this->belongsTo(ReceivingReport::class);
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Calculate total cost before saving (only if unit_cost is provided)
        static::saving(function ($item) {
            if ($item->unit_cost !== null) {
                $item->total_cost = $item->quantity * $item->unit_cost;
            }
        });
    }
}
