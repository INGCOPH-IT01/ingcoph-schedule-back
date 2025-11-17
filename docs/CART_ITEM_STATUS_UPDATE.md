# Cart Item Status Update - Excluding Rejected Items

## Overview

Updated the entire booking synchronization system to exclude cart items with status `'rejected'` in addition to `'cancelled'` when calculating booking times and prices.

## Date
November 17, 2025

## Rationale

Cart items with status `'rejected'` should not count toward a booking's time range or total price, just like cancelled items. Previously, only cancelled items were excluded, which could lead to incorrect booking calculations if rejected items existed.

## Files Updated

### 1. CartItemObserver.php
**Location:** `app/Observers/CartItemObserver.php`

**Changes:** Updated cart item filtering in all sync methods:
- `syncBookingAfterCartItemCancellation()` - Line 62
- `syncBookingAfterCourtChange()` - Line 176
- `syncBookingAfterDateTimeChange()` - Line 332

**Before:**
```php
->where('status', '!=', 'cancelled')
```

**After:**
```php
->whereNotIn('status', ['cancelled', 'rejected'])
```

**Impact:** Real-time booking synchronization now correctly excludes rejected cart items.

### 2. CartController.php
**Location:** `app/Http/Controllers/Api/CartController.php`

**Changes:** Updated cart item filtering in multiple methods:
- Conflict checking when adding items (line 1548)
- Conflict checking when updating items (line 1691)
- Item count check when deleting (line 1811)
- Price recalculation after deletion (line 1832)

**Impact:**
- Conflict detection now ignores rejected items
- Item count validation excludes rejected items
- Price calculations exclude rejected items

### 3. FixBookingTimesSeeder.php
**Location:** `database/seeders/FixBookingTimesSeeder.php`

**Changes:** Updated cart item filtering in `fixBookingTimes()` method (line 78)

**Impact:** Data cleanup seeder now correctly excludes rejected items when recalculating booking times.

### 4. FixBookingTimesCommand.php
**Location:** `app/Console/Commands/FixBookingTimesCommand.php`

**Changes:** Updated cart item filtering in `fixBookingTimes()` method (line 150)

**Impact:** Artisan command now correctly excludes rejected items when fixing booking times.

### 5. Documentation Updates

#### BOOKING_TIME_SYNC.md
- Updated behavior description to mention rejected items are excluded

#### FIX_BOOKING_TIMES_SEEDER.md
- Updated "How It Works" section
- Updated "Cart Item Filtering" safety features section
- Updated SQL verification query to exclude rejected items

## Summary of Changes

### Cart Item Status Filtering

**Old Logic:**
```php
// Only exclude cancelled
CartItem::where('status', '!=', 'cancelled')
```

**New Logic:**
```php
// Exclude both cancelled and rejected
CartItem::whereNotIn('status', ['cancelled', 'rejected'])
```

### Affected Operations

1. **Booking Time Calculation**
   - Only active (non-cancelled, non-rejected) items contribute to booking start/end times

2. **Price Calculation**
   - Only active items contribute to booking total price

3. **Conflict Detection**
   - Rejected items no longer block time slots

4. **Item Count Validation**
   - Rejected items not counted when checking if deletion is allowed

## Cart Item Status Values

For reference, cart items can have the following statuses:

- `'pending'` - Active, in cart, not yet checked out ✅ **Counted**
- `'completed'` - Checked out successfully ✅ **Counted**
- `'expired'` - Cart expired (after 1 hour) ❌ **Not counted**
- `'cancelled'` - Manually cancelled ❌ **Not counted**
- `'rejected'` - Rejected by admin ❌ **Not counted** (NEW)

## Testing Recommendations

### 1. Test Rejected Cart Item Exclusion
```php
// Create a booking with cart items
// Reject one cart item
// Verify booking times recalculate without the rejected item
```

### 2. Test Conflict Detection
```php
// Create a cart item and reject it
// Try to book the same time slot
// Should succeed (no conflict with rejected item)
```

### 3. Test Price Calculation
```php
// Create booking with 3 items: 100, 100, 100
// Reject one item
// Verify booking total = 200 (not 300)
```

### 4. Test Data Cleanup
```bash
# Run the seeder/command
php artisan booking:fix-times

# Verify rejected items are excluded from calculations
```

## Migration Guide

### For Existing Data

If you have existing bookings with rejected cart items:

1. **Run the fix command:**
   ```bash
   php artisan booking:fix-times --dry-run
   ```

2. **Review the output** to see what will change

3. **Apply the changes:**
   ```bash
   php artisan booking:fix-times
   ```

### For New Code

All new code should use:
```php
// Good ✅
CartItem::whereNotIn('status', ['cancelled', 'rejected'])

// Avoid ❌
CartItem::where('status', '!=', 'cancelled')
```

## Backward Compatibility

This change is **backward compatible** because:
- Existing queries that only excluded cancelled items will now be more accurate
- No breaking API changes
- No database schema changes required
- Rejected items should never have been counted anyway (this is a bug fix)

## Related Documentation

- [Booking Time Synchronization](./BOOKING_TIME_SYNC.md) - Main sync documentation
- [Fix Booking Times Seeder](./FIX_BOOKING_TIMES_SEEDER.md) - Data cleanup tool

## Future Considerations

Consider adding:
1. Database constraint to prevent rejected items from being in approved transactions
2. Audit log for cart item status changes
3. Automated tests for all status combinations
4. UI indication when rejected items exist in a transaction
