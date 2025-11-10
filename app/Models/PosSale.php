<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosSale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sale_number',
        'booking_id',
        'user_id',
        'customer_id',
        'customer_name',
        'subtotal',
        'tax',
        'discount',
        'total_amount',
        'payment_method',
        'payment_reference',
        'proof_of_payment',
        'status',
        'notes',
        'sale_date',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sale_date' => 'datetime',
    ];

    // Don't automatically append profit - it will be conditionally added for admins only
    // protected $appends = ['profit'];

    /**
     * Boot method to generate sale number.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($sale) {
            if (empty($sale->sale_number)) {
                $sale->sale_number = static::generateSaleNumber();
            }
        });
    }

    /**
     * Generate unique sale number.
     */
    public static function generateSaleNumber()
    {
        $year = date('Y');
        $lastSale = static::where('sale_number', 'like', "POS-{$year}-%")
                          ->orderBy('id', 'desc')
                          ->first();

        if ($lastSale) {
            $lastNumber = intval(substr($lastSale->sale_number, -5));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return sprintf('POS-%s-%05d', $year, $newNumber);
    }

    /**
     * Get the user (staff/admin) who processed the sale.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the customer.
     */
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    /**
     * Get the booking associated with the sale.
     */
    public function booking()
    {
        return $this->belongsTo(CartTransaction::class, 'booking_id');
    }

    /**
     * Get the sale items.
     */
    public function saleItems()
    {
        return $this->hasMany(PosSaleItem::class);
    }

    /**
     * Get the stock movements associated with the sale.
     */
    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Calculate total profit.
     */
    public function getProfitAttribute()
    {
        $totalCost = $this->saleItems->sum(function ($item) {
            return $item->unit_cost * $item->quantity;
        });
        return $this->total_amount - $totalCost;
    }

    /**
     * Scope a query to only include completed sales.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to filter by date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('sale_date', [$startDate, $endDate]);
    }

    /**
     * Scope a query to filter by today.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('sale_date', today());
    }
}
