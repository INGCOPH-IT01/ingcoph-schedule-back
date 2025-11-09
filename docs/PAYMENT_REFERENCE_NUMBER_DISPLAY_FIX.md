# Payment Reference Number Display Fix

## Issue

The `payment_reference_number` field was being saved correctly to both `Booking` and `CartTransaction` models in the database, but it was **not displaying** in the `BookingDetailsDialog.vue` component on the frontend.

## Root Cause

The issue was in the **API Resource layer**, not in the models or frontend component.

### What Was Wrong

The `CartTransactionResource` was transforming the data before sending it to the frontend, but it was **missing** the `payment_reference_number` field in its `toArray()` method.

**Before (INCORRECT)** - `CartTransactionResource.php` lines 17-27:
```php
return [
    'id' => $this->id,
    'user_id' => $this->user_id,
    'total_price' => $this->total_price,
    'status' => $this->status,
    'payment_method' => $this->payment_method,
    'payment_status' => $this->payment_status,  // ❌ Missing payment_reference_number
    'proof_of_payment' => $this->proof_of_payment,
    'approval_status' => $this->approval_status,
    // ... rest of fields
];
```

Even though:
- ✅ The field existed in the database
- ✅ The field was in `$fillable` arrays in both models
- ✅ The field was being saved correctly
- ✅ The frontend component had the display code ready

The data was being **filtered out** by the API Resource before reaching the frontend!

## Solution

### Backend Fix

Added `payment_reference_number` to the `CartTransactionResource::toArray()` method:

**After (CORRECT)** - `CartTransactionResource.php` line 23:
```php
return [
    'id' => $this->id,
    'user_id' => $this->user_id,
    'total_price' => $this->total_price,
    'status' => $this->status,
    'payment_method' => $this->payment_method,
    'payment_reference_number' => $this->payment_reference_number,  // ✅ Added
    'payment_status' => $this->payment_status,
    'proof_of_payment' => $this->proof_of_payment,
    'approval_status' => $this->approval_status,
    // ... rest of fields
];
```

### Why This Matters

Laravel Resources act as a **transformation layer** between your Eloquent models and JSON responses. Even if a field exists in the database and model, it won't be included in the API response unless explicitly added to the resource's `toArray()` method.

## Data Flow (Now Fixed)

```
User enters payment ref → Frontend sends to backend → Backend saves to DB
                                                              ↓
                                                    ✅ Saved to cart_transactions table
                                                              ↓
                                    Backend fetches transaction with payment_reference_number
                                                              ↓
                              ✅ CartTransactionResource now includes payment_reference_number
                                                              ↓
                                             Frontend receives complete data
                                                              ↓
                                      ✅ BookingDetailsDialog displays the value
```

## Frontend Display Code (Already Correct)

The frontend component in `BookingDetailsDialog.vue` (lines 1030-1033) was already correctly implemented:

```vue
<!-- Display Payment Reference Number if it exists -->
<div class="detail-row" v-if="booking.payment_reference_number">
  <span class="detail-label">Payment Reference Number:</span>
  <span class="detail-value font-weight-medium">{{ booking.payment_reference_number }}</span>
</div>
```

This code was waiting for the backend to send the data, which it now does!

## Testing

To verify the fix works:

1. **Create a new booking with a payment reference number:**
   - Go to booking flow
   - Enter payment reference number (e.g., "QRPH123456")
   - Complete the booking

2. **View the booking details:**
   - Open `BookingDetailsDialog`
   - Check the Payment Information section
   - The payment reference number should now be displayed

3. **Upload proof of payment with reference number:**
   - For an existing unpaid booking
   - Upload proof of payment
   - Enter reference number
   - Submit
   - Reopen booking details
   - Reference number should be visible

## Related Files

### Backend
- ✅ `/app/Http/Resources/CartTransactionResource.php` - **FIXED** (added field to response)
- ✅ `/app/Models/CartTransaction.php` - Already correct (field in fillable)
- ✅ `/app/Models/Booking.php` - Already correct (field in fillable)
- ✅ `/app/Http/Controllers/Api/CartController.php` - Already correct (saves field)

### Frontend
- ✅ `/src/components/BookingDetailsDialog.vue` - Already correct (displays field)
- ✅ `/src/components/ProofOfPaymentUpload.vue` - Already correct (inputs field)
- ✅ `/src/components/NewBookingDialog.vue` - Already correct (sends field)

## Key Takeaway

**Always check API Resources when data exists in the database but doesn't appear in the frontend!**

Laravel API Resources act as a whitelist for which fields get sent to the frontend. If a field is missing from the resource's `toArray()` method, it will be silently excluded from the API response, even if it exists in the database.

This is actually a **security feature** - it prevents accidental exposure of sensitive database fields. But it means you must explicitly add any new fields to the relevant resources.

## Verification Checklist

- [x] Field added to `CartTransactionResource::toArray()`
- [x] No linter errors in modified files
- [x] Field exists in `$fillable` arrays (already was)
- [x] Frontend component displays field (already did)
- [x] Field is saved during checkout (already was)
- [x] Field is saved during proof upload (already was)

## Status

✅ **FIXED** - Payment reference numbers will now display correctly in the booking details dialog for all cart transactions.
