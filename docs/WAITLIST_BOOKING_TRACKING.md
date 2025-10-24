# Waitlist Booking Tracking Implementation

## Overview
This document describes the implementation of booking_waitlist_id tracking for auto-created bookings from waitlists.

## Changes Made

### 1. Database Migrations

#### Added booking_waitlist_id to bookings table
- **File**: `database/migrations/2025_10_24_000001_add_booking_waitlist_id_to_bookings_table.php`
- Adds nullable `booking_waitlist_id` foreign key column to `bookings` table
- References `booking_waitlists.id` with `onDelete('set null')`
- Includes index for performance

#### Added booking_waitlist_id to cart_transactions table
- **File**: `database/migrations/2025_10_24_000002_add_booking_waitlist_id_to_cart_transactions_table.php`
- Adds nullable `booking_waitlist_id` foreign key column to `cart_transactions` table
- References `booking_waitlists.id` with `onDelete('set null')`
- Includes index for performance

### 2. Model Updates

#### Booking Model (`app/Models/Booking.php`)
- Added `booking_waitlist_id` to fillable array
- Added `bookingWaitlist()` relationship method:
  ```php
  public function bookingWaitlist(): BelongsTo
  {
      return $this->belongsTo(BookingWaitlist::class, 'booking_waitlist_id');
  }
  ```

#### CartTransaction Model (`app/Models/CartTransaction.php`)
- Added `booking_waitlist_id` to fillable array
- Added `bookingWaitlist()` relationship method:
  ```php
  public function bookingWaitlist(): BelongsTo
  {
      return $this->belongsTo(BookingWaitlist::class, 'booking_waitlist_id');
  }
  ```

#### CartItem Model (`app/Models/CartItem.php`)
- Already had `booking_waitlist_id` in fillable array (no changes needed)

### 3. Controller Updates

#### CartTransactionController (`app/Http/Controllers/Api/CartTransactionController.php`)
In the `notifyWaitlistUsers()` method (line 455):
- When creating a booking from a waitlist entry, now saves `booking_waitlist_id`
- After creating the booking, sets `booking_waitlist_id` to null in:
  - All cart_items associated with that waitlist entry
  - All cart_transactions associated with that waitlist entry

```php
// Create booking automatically for waitlisted user
$newBooking = Booking::create([
    'user_id' => $waitlistEntry->user_id,
    'cart_transaction_id' => null,
    'booking_waitlist_id' => $waitlistEntry->id, // Save the waitlist ID
    // ... other fields
]);

// Update cart_items and cart_transactions to set booking_waitlist_id to null
\App\Models\CartItem::where('booking_waitlist_id', $waitlistEntry->id)
    ->update(['booking_waitlist_id' => null]);

\App\Models\CartTransaction::where('booking_waitlist_id', $waitlistEntry->id)
    ->update(['booking_waitlist_id' => null]);
```

#### BookingController (`app/Http/Controllers/Api/BookingController.php`)
In the `processWaitlistForRejectedBooking()` method (line 1088):
- When creating a booking from a waitlist entry, now saves `booking_waitlist_id`
- After creating the booking, sets `booking_waitlist_id` to null in:
  - All cart_items associated with that waitlist entry
  - All cart_transactions associated with that waitlist entry

Same implementation pattern as CartTransactionController.

### 4. Bug Fix
Fixed `create_holidays_table` migration to check if table exists before creating it to prevent migration conflicts.

## Purpose & Benefits

### 1. Booking Traceability
- Auto-created bookings from waitlists now maintain a reference to the original waitlist entry
- Enables tracking which bookings originated from waitlist approvals

### 2. Data Integrity
- After a booking is created from a waitlist, the `booking_waitlist_id` in cart_items and cart_transactions is cleared
- This prevents orphaned references and maintains clean data relationships
- The booking itself retains the waitlist reference for historical tracking

### 3. Business Logic
When a parent booking is rejected:
1. Waitlisted users are notified
2. A new booking is auto-created for each waitlist entry
3. The new booking saves the `booking_waitlist_id` for tracking
4. The waitlist_id in associated cart_items and cart_transactions is cleared
5. This allows the system to know which bookings came from waitlists while preventing double-references

## Flow Diagram

```
Parent Booking Rejected
    ↓
Find Waitlist Entries (pending_booking_id = parent_booking.id)
    ↓
For Each Waitlist Entry:
    ↓
Create New Booking
    - booking_waitlist_id = waitlist_entry.id  ← NEW: Track origin
    ↓
Clear Waitlist References
    - cart_items.booking_waitlist_id → null  ← NEW: Clean up
    - cart_transactions.booking_waitlist_id → null  ← NEW: Clean up
    ↓
Send Notification Email
```

## Database Schema Changes

### bookings table
```sql
ALTER TABLE bookings ADD COLUMN booking_waitlist_id BIGINT UNSIGNED NULL;
ALTER TABLE bookings ADD FOREIGN KEY (booking_waitlist_id) REFERENCES booking_waitlists(id) ON DELETE SET NULL;
ALTER TABLE bookings ADD INDEX (booking_waitlist_id);
```

### cart_transactions table
```sql
ALTER TABLE cart_transactions ADD COLUMN booking_waitlist_id BIGINT UNSIGNED NULL;
ALTER TABLE cart_transactions ADD FOREIGN KEY (booking_waitlist_id) REFERENCES booking_waitlists(id) ON DELETE SET NULL;
ALTER TABLE cart_transactions ADD INDEX (booking_waitlist_id);
```

## Migration Status
✅ All migrations applied successfully
✅ No linter errors
✅ Relationships properly configured

## Testing Recommendations

1. **Reject Parent Booking**: Verify that auto-created bookings have booking_waitlist_id set
2. **Cart Items Cleanup**: Verify that cart_items.booking_waitlist_id becomes null after booking creation
3. **Cart Transactions Cleanup**: Verify that cart_transactions.booking_waitlist_id becomes null after booking creation
4. **Booking History**: Verify that bookings retain the waitlist reference for historical tracking
5. **Cascade Delete**: Verify that if a waitlist entry is deleted, the booking_waitlist_id is set to null (not deleted)
