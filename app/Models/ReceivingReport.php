<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceivingReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_number',
        'user_id',
        'notes',
        'status',
        'confirmed_at',
        'confirmed_by',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    protected $appends = ['total_items', 'total_cost'];

    /**
     * Generate a unique report number.
     */
    public static function generateReportNumber()
    {
        $date = now()->format('Ymd');
        $lastReport = self::whereDate('created_at', now())->latest()->first();

        if ($lastReport) {
            $lastNumber = (int) substr($lastReport->report_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return 'RR-' . $date . '-' . $newNumber;
    }

    /**
     * Get the user who created the report.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user who confirmed the report.
     */
    public function confirmedBy()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the items for the receiving report.
     */
    public function items()
    {
        return $this->hasMany(ReceivingReportItem::class);
    }

    /**
     * Get total items count.
     */
    public function getTotalItemsAttribute()
    {
        return $this->items()->sum('quantity');
    }

    /**
     * Get total cost.
     */
    public function getTotalCostAttribute()
    {
        return $this->items()->sum('total_cost');
    }

    /**
     * Scope a query to only include draft reports.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope a query to only include pending reports.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include confirmed reports.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Confirm the receiving report and adjust stock.
     */
    public function confirm($userId)
    {
        if ($this->status === 'confirmed') {
            return false;
        }

        \DB::beginTransaction();
        try {
            // Update product stocks
            foreach ($this->items as $item) {
                $product = $item->product;

                // Update product cost if provided
                if ($item->unit_cost > 0) {
                    $product->cost = $item->unit_cost;
                    $product->save();
                }

                // Increase stock
                $product->increaseStock(
                    $item->quantity,
                    $userId,
                    'in',
                    "Receiving Report: {$this->report_number}",
                    $this->report_number
                );
            }

            // Update report status
            $this->status = 'confirmed';
            $this->confirmed_at = now();
            $this->confirmed_by = $userId;
            $this->save();

            \DB::commit();
            return true;
        } catch (\Exception $e) {
            \DB::rollBack();
            throw $e;
        }
    }

    /**
     * Cancel the receiving report.
     */
    public function cancel()
    {
        if ($this->status === 'confirmed') {
            return false;
        }

        $this->status = 'cancelled';
        $this->save();

        return true;
    }
}
