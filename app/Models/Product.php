<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'description',
        'price',
        'cost',
        'stock_quantity',
        'low_stock_threshold',
        'unit',
        'barcode',
        'image',
        'is_active',
        'track_inventory',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'is_active' => 'boolean',
        'track_inventory' => 'boolean',
    ];

    protected $appends = ['is_low_stock', 'profit_margin'];

    /**
     * Get the category that owns the product.
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    /**
     * Get the stock movements for the product.
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get the sale items for the product.
     */
    public function saleItems()
    {
        return $this->hasMany(PosSaleItem::class);
    }

    /**
     * Check if product is low on stock.
     */
    public function getIsLowStockAttribute()
    {
        return $this->track_inventory && $this->stock_quantity <= $this->low_stock_threshold;
    }

    /**
     * Calculate profit margin.
     */
    public function getProfitMarginAttribute()
    {
        if ($this->price <= 0) {
            return 0;
        }
        return (($this->price - $this->cost) / $this->price) * 100;
    }

    /**
     * Scope a query to only include active products.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include low stock products.
     */
    public function scopeLowStock($query)
    {
        return $query->where('track_inventory', true)
                     ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    /**
     * Scope a query to only include in-stock products.
     */
    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('track_inventory', false)
              ->orWhere('stock_quantity', '>', 0);
        });
    }

    /**
     * Decrease stock quantity.
     */
    public function decreaseStock($quantity, $userId, $notes = null, $posSaleId = null)
    {
        if (!$this->track_inventory) {
            return true;
        }

        $quantityBefore = $this->stock_quantity;
        $this->stock_quantity -= $quantity;
        $this->save();

        // Record stock movement
        StockMovement::create([
            'product_id' => $this->id,
            'user_id' => $userId,
            'type' => 'sale',
            'quantity' => -$quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->stock_quantity,
            'pos_sale_id' => $posSaleId,
            'notes' => $notes,
        ]);

        return true;
    }

    /**
     * Increase stock quantity.
     */
    public function increaseStock($quantity, $userId, $type = 'in', $notes = null, $referenceNumber = null)
    {
        if (!$this->track_inventory) {
            return true;
        }

        $quantityBefore = $this->stock_quantity;
        $this->stock_quantity += $quantity;
        $this->save();

        // Record stock movement
        StockMovement::create([
            'product_id' => $this->id,
            'user_id' => $userId,
            'type' => $type,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->stock_quantity,
            'notes' => $notes,
            'reference_number' => $referenceNumber,
        ]);

        return true;
    }

    /**
     * Adjust stock quantity (can be positive or negative).
     */
    public function adjustStock($quantity, $userId, $notes = null)
    {
        if (!$this->track_inventory) {
            return true;
        }

        $quantityBefore = $this->stock_quantity;
        $this->stock_quantity = $quantity;
        $this->save();

        $difference = $quantity - $quantityBefore;

        // Record stock movement
        StockMovement::create([
            'product_id' => $this->id,
            'user_id' => $userId,
            'type' => 'adjustment',
            'quantity' => $difference,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $this->stock_quantity,
            'notes' => $notes,
        ]);

        return true;
    }
}

