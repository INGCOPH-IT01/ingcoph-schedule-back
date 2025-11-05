# Waitlist Cart System

## Overview

This document describes the separate cart system for waitlist bookings, which was created to avoid conflicts with the regular booking cart system.

## Problem Statement

Previously, the system attempted to use the same `CartItem` and `CartTransaction` models for both regular bookings and waitlist-related bookings. This caused conflicts because:
- Waitlist bookings have different lifecycle requirements
- Mixed references between regular and waitlist bookings created data integrity issues
- Different validation and business rules apply to waitlist vs. regular bookings

## Solution

Created separate models and database tables specifically for waitlist cart operations:
- `WaitlistCartItem` - Stores individual time slots for waitlist bookings
- `WaitlistCartTransaction` - Groups waitlist cart items into a single transaction

## Database Tables

### `waitlist_cart_transactions`

Stores transaction-level information for waitlist bookings.

**Columns:**
- `id` - Primary key
- `user_id` - User who created the waitlist cart
- `booking_for_user_id` - User this booking is for (if admin booking for someone)
- `booking_for_user_name` - Name of user this booking is for
- `booking_waitlist_id` - Reference to the waitlist entry
- `total_price` - Total price of all items
- `status` - Transaction status (pending, completed, cancelled)
- `approval_status` - Approval status (pending, approved, rejected)
- `approved_by` - Admin who approved
- `approved_at` - Approval timestamp
- `rejection_reason` - Reason for rejection
- `payment_method` - Payment method (pending, gcash)
- `payment_status` - Payment status (unpaid, paid)
- `proof_of_payment` - Image/file proof
- `paid_at` - Payment timestamp
- `qr_code` - QR code for attendance
- `attendance_status` - Attendance tracking (not_set, showed_up, no_show)
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- `user_id`, `created_at`
- `status`
- `booking_waitlist_id`
- `approval_status`, `payment_status`, `created_at`
- `status`, `payment_status`
- `user_id`, `status`, `created_at`

### `waitlist_cart_items`

Stores individual time slots for waitlist bookings.

**Columns:**
- `id` - Primary key
- `user_id` - User who created the item
- `booking_for_user_id` - User this booking is for
- `booking_for_user_name` - Name of user this booking is for
- `waitlist_cart_transaction_id` - Parent transaction
- `booking_waitlist_id` - Reference to waitlist entry
- `court_id` - Court being booked
- `sport_id` - Sport being played
- `booking_date` - Date of booking
- `start_time` - Start time
- `end_time` - End time
- `price` - Price for this time slot
- `number_of_players` - Number of players
- `status` - Item status (pending, approved, cancelled)
- `notes` - User notes
- `admin_notes` - Admin notes
- `session_id` - For guest users
- `created_at`, `updated_at` - Timestamps

**Indexes:**
- `user_id`, `created_at`
- `session_id`, `created_at`
- `waitlist_cart_transaction_id`
- `booking_waitlist_id`
- `sport_id`

## Model Relationships

### WaitlistCartItem

**Relationships:**
- `belongsTo User` (user)
- `belongsTo User` (bookingForUser)
- `belongsTo Court` (court)
- `belongsTo Sport` (sport)
- `belongsTo WaitlistCartTransaction` (waitlistCartTransaction)
- `belongsTo BookingWaitlist` (bookingWaitlist)
- `hasManyThrough Booking` (bookings via waitlistCartTransaction)

### WaitlistCartTransaction

**Relationships:**
- `belongsTo User` (user)
- `belongsTo User` (bookingForUser)
- `belongsTo User` (approver)
- `belongsTo BookingWaitlist` (bookingWaitlist)
- `hasMany WaitlistCartItem` (waitlistCartItems)
- `hasMany Booking` (bookings)
- `hasMany BookingWaitlist` (waitlistEntries)

**Methods:**
- `syncBookingsStatus(string $status)` - Sync booking status with transaction approval status

### BookingWaitlist

**New Relationships:**
- `belongsTo WaitlistCartTransaction` (convertedWaitlistCartTransaction)

## Observer

### WaitlistCartItemObserver

Handles automatic synchronization when waitlist cart items change:
- **Status changes** - Updates associated bookings when items are cancelled
- **Court changes** - Recalculates bookings when court is modified (placeholder for future implementation)
- **Date/time changes** - Updates booking times when cart item times change (placeholder for future implementation)

The observer is registered in `AppServiceProvider.php`.

## Usage Guidelines

### When to Use Waitlist Cart Models

Use `WaitlistCartItem` and `WaitlistCartTransaction` when:
1. Processing waitlist conversions to actual bookings
2. Creating bookings from waitlist entries
3. Managing cart items that originated from the waitlist system

### When to Use Regular Cart Models

Use `CartItem` and `CartTransaction` when:
1. Processing regular (non-waitlist) bookings
2. Direct bookings by users or staff
3. Any booking that didn't originate from the waitlist

## Migration Instructions

1. **Run the migrations:**
```bash
php artisan migrate
```

This will create the two new tables:
- `2025_11_05_163318_create_waitlist_cart_transactions_table.php`
- `2025_11_05_163322_create_waitlist_cart_items_table.php`

2. **No data migration needed** - These are new tables for future waitlist conversions

3. **Existing waitlist entries** will continue to reference `CartTransaction` through the `converted_cart_transaction_id` field in the `booking_waitlists` table. New waitlist conversions should use `WaitlistCartTransaction`.

## Implementation Notes

1. **Backward Compatibility** - The `BookingWaitlist` model maintains both:
   - `convertedCartTransaction()` - For existing/legacy conversions
   - `convertedWaitlistCartTransaction()` - For new conversions

2. **Foreign Keys** - The `booking_waitlist_id` in both tables uses `onDelete('set null')` to preserve historical data even if waitlist entries are deleted

3. **Observers** - `WaitlistCartItemObserver` is automatically registered and will handle data synchronization

4. **Future Work** - The court change and date/time change sync logic in `WaitlistCartItemObserver` are placeholders. Implement these as needed based on your business requirements.

## Next Steps

1. Update controllers to use `WaitlistCartItem` and `WaitlistCartTransaction` for waitlist conversions
2. Modify waitlist conversion logic to create waitlist cart items instead of regular cart items
3. Update any queries or reports that need to include waitlist cart data
4. Test the new system thoroughly before deploying to production

## Benefits

✅ **Clear separation** - Waitlist and regular bookings have distinct data models
✅ **No conflicts** - References are explicit and don't interfere with each other
✅ **Better data integrity** - Each system has its own constraints and validation
✅ **Easier debugging** - Clear data lineage from waitlist → waitlist cart → booking
✅ **Backward compatible** - Existing data continues to work
