<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PosSaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pos_sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'unit_cost',
        'discount',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    // Don't automatically append item_profit - it will be conditionally added for admins only
    // protected $appends = ['item_profit'];
    
    // Hide unit_cost by default (only admins should see this)
    protected $hidden = ['unit_cost'];

    /**
     * Get the sale.
     */
    public function sale()
    {
        return $this->belongsTo(PosSale::class, 'pos_sale_id');
    }

    /**
     * Get the product.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate item profit.
     */
    public function getItemProfitAttribute()
    {
        return ($this->unit_price - $this->unit_cost) * $this->quantity - $this->discount;
    }
}

