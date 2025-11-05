# Waitlist Cart System - Quick Reference

## What Was Implemented

When a `BookingWaitlist` entry is created, the system now automatically creates:
1. `WaitlistCartItem` - Copy of the cart item data
2. `WaitlistCartTransaction` - Copy of the cart transaction data

These records track the waitlist booking separately from regular bookings to avoid conflicts.

## The Three Scenarios

### 1️⃣ When Waitlist Entry is Created
```
User books pending slot
  → Creates BookingWaitlist
  → Creates WaitlistCartItem (saves CartItem.id reference)
  → Creates WaitlistCartTransaction (saves CartTransaction.id reference)
```

### 2️⃣ When Original Booking is APPROVED
```
Admin approves original booking
  → WaitlistCartItem → status: 'rejected'
  → WaitlistCartTransaction → status: 'rejected'
  → BookingWaitlist → status: 'cancelled'

Result: Waitlisted users are notified slot is no longer available
```

### 3️⃣ When Original Booking is REJECTED
```
Admin rejects original booking
  → WaitlistCartItem → duplicated as CartItem
  → WaitlistCartTransaction → duplicated as CartTransaction
  → Creates new Booking
  → Marks waitlist records as 'converted'

Result: Waitlisted users can now book the slot
```

## Key Files

### New Files Created
- `app/Models/WaitlistCartItem.php`
- `app/Models/WaitlistCartTransaction.php`
- `app/Services/WaitlistCartService.php`
- `app/Observers/WaitlistCartItemObserver.php`
- `database/migrations/2025_11_05_163318_create_waitlist_cart_transactions_table.php`
- `database/migrations/2025_11_05_163322_create_waitlist_cart_items_table.php`

### Modified Files
- `app/Models/BookingWaitlist.php` - Added relationship
- `app/Http/Controllers/Api/CartController.php` - Creates waitlist cart on checkout
- `app/Http/Controllers/Api/BookingController.php` - Creates waitlist cart on direct booking
- `app/Http/Controllers/Api/CartTransactionController.php` - Handles approval/rejection
- `app/Providers/AppServiceProvider.php` - Registers observer

## Database Tables

### `waitlist_cart_transactions`
Mirrors `cart_transactions` with additional `booking_waitlist_id` reference

### `waitlist_cart_items`
Mirrors `cart_items` with additional `booking_waitlist_id` and `waitlist_cart_transaction_id` references

## Service Methods

Located in `app/Services/WaitlistCartService.php`:

```php
// Create from cart checkout
createWaitlistCartRecords($waitlistEntry, $cartItem, $cartTransaction)

// Create from direct booking
createWaitlistCartRecordsFromWaitlist($waitlistEntry)

// Convert when original booking rejected
convertWaitlistToBooking($waitlistEntry)

// Reject when original booking approved
rejectWaitlistCartRecords($waitlistEntry)
```

## Usage Example

### In Controller (automatic)
```php
// When creating waitlist entry
$waitlistEntry = BookingWaitlist::create([...]);

// Service automatically called to create waitlist cart records
$waitlistCartService = app(\App\Services\WaitlistCartService::class);
$waitlistCartService->createWaitlistCartRecords(
    $waitlistEntry,
    $cartItem,
    $cartTransaction
);
```

### When Approval Happens (automatic)
```php
// System automatically rejects waitlist cart records
$waitlistCartService->rejectWaitlistCartRecords($waitlistEntry);
```

### When Rejection Happens (automatic)
```php
// System automatically converts waitlist to booking
$booking = $waitlistCartService->convertWaitlistToBooking($waitlistEntry);
```

## Status Flow

```
WaitlistCartItem:     pending → rejected OR converted
WaitlistCartTransaction: pending → rejected OR converted
BookingWaitlist:      pending → cancelled OR converted
```

## Testing Quick Check

1. ✅ User books pending slot → Check `waitlist_cart_items` and `waitlist_cart_transactions` tables
2. ✅ Admin approves → Check waitlist cart records have `status='rejected'`
3. ✅ Admin rejects → Check new `cart_items`, `cart_transactions`, and `bookings` created

## Common Queries

```sql
-- See all waitlist cart items for a waitlist entry
SELECT * FROM waitlist_cart_items WHERE booking_waitlist_id = ?;

-- See waitlist cart transaction for a waitlist entry
SELECT * FROM waitlist_cart_transactions WHERE booking_waitlist_id = ?;

-- See converted bookings from waitlist
SELECT b.* FROM bookings b
JOIN booking_waitlists w ON b.cart_transaction_id = w.converted_cart_transaction_id
WHERE w.status = 'converted';
```

## Documentation

- **Architecture**: `docs/WAITLIST_CART_SYSTEM.md`
- **Workflow**: `docs/WAITLIST_CART_WORKFLOW.md`
- **Summary**: `docs/WAITLIST_IMPLEMENTATION_SUMMARY.md`
- **Quick Ref**: `WAITLIST_CART_QUICK_REFERENCE.md` (this file)

## Need Help?

1. Check the logs in `storage/logs/laravel.log`
2. Look for "waitlist cart" in log entries
3. Review the service code in `app/Services/WaitlistCartService.php`
4. Check the full documentation in `docs/` folder
