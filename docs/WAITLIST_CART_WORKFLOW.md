# Waitlist Cart Workflow Implementation

## Overview

This document describes the complete workflow for managing waitlist bookings with separate cart tracking. When a user attempts to book a time slot that's already pending approval for another user, they are added to a waitlist. The system now maintains separate cart records for waitlist entries to avoid conflicts with regular booking cart data.

## Problem Solved

Previously, the system attempted to use the same `CartItem` and `CartTransaction` models for both regular bookings and waitlist entries. This caused conflicts because:
- Waitlist bookings have different lifecycle requirements than regular bookings
- Mixed references created data integrity issues
- Different approval flows meant data could become inconsistent

## Solution Architecture

### Three-Stage Data Flow

```
1. Waitlist Creation
   └─> Creates: BookingWaitlist + WaitlistCartItem + WaitlistCartTransaction

2. Original Booking Approved
   └─> Marks waitlist records as rejected (slot no longer available)

3. Original Booking Rejected
   └─> Converts: WaitlistCartItem → CartItem
       Converts: WaitlistCartTransaction → CartTransaction
       Creates: Booking
```

## Detailed Workflow

### Stage 1: Waitlist Creation

When a user attempts to book a slot that's already pending approval:

**Location**:
- `CartController::checkout()` (line 375-418)
- `BookingController::store()` (line 183-203)

**Process**:
1. Create `BookingWaitlist` entry with pending status
2. Create `CartItem` linked to the waitlist (for the original cart)
3. Create `CartTransaction` for the checkout
4. **NEW**: Create `WaitlistCartItem` with data from `CartItem`
5. **NEW**: Create `WaitlistCartTransaction` with data from `CartTransaction`

**Code Example** (CartController):
```php
// Create waitlist entry
$waitlistEntry = BookingWaitlist::create([...]);

// Create cart item for the original cart
$cartItem = CartItem::create([
    'cart_transaction_id' => $cartTransaction->id,
    'booking_waitlist_id' => $waitlistEntry->id,
    // ... other fields
]);

// NEW: Create waitlist cart records
$waitlistCartService = app(\App\Services\WaitlistCartService::class);
$waitlistCartService->createWaitlistCartRecords(
    $waitlistEntry,
    $cartItem,
    $cartTransaction
);
```

**Code Example** (BookingController - direct booking):
```php
// Create waitlist entry
$waitlistEntry = BookingWaitlist::create([...]);

// NEW: Create waitlist cart records from waitlist data
$waitlistCartService = app(\App\Services\WaitlistCartService::class);
$waitlistCartService->createWaitlistCartRecordsFromWaitlist($waitlistEntry);
```

### Stage 2: Original Booking Approved

When the admin approves the original booking (the one people are waitlisted for):

**Location**: `CartTransactionController::approve()` (line 244-257)

**Process**:
1. Approve the original `CartTransaction`
2. Approve all associated `Booking` records
3. Mark all waitlist entries as cancelled
4. **NEW**: Mark all `WaitlistCartItem` records as rejected
5. **NEW**: Mark all `WaitlistCartTransaction` records as rejected

**Code Example**:
```php
// Approve transaction and bookings
$transaction->update(['approval_status' => 'approved']);
$transaction->bookings()->update(['status' => 'approved']);

// Cancel waitlist entries
$this->cancelWaitlistUsers($transaction);

// NEW: Reject waitlist cart records
$waitlistCartService = app(\App\Services\WaitlistCartService::class);
foreach ($transaction->bookings as $approvedBooking) {
    $waitlistEntries = BookingWaitlist::where('pending_booking_id', $approvedBooking->id)
        ->whereIn('status', [BookingWaitlist::STATUS_PENDING, BookingWaitlist::STATUS_NOTIFIED])
        ->get();

    foreach ($waitlistEntries as $waitlistEntry) {
        $waitlistCartService->rejectWaitlistCartRecords($waitlistEntry);
    }
}
```

**What happens in `rejectWaitlistCartRecords()`**:
- Updates `WaitlistCartItem.status` = 'rejected'
- Updates `WaitlistCartTransaction.approval_status` = 'rejected'
- Updates `BookingWaitlist.status` = 'cancelled'
- Adds rejection reason: "Original booking was approved - waitlist cancelled"

### Stage 3: Original Booking Rejected

When the admin rejects the original booking:

**Location**:
- `CartTransactionController::reject()` → `notifyWaitlistUsers()` (line 600-625)
- `BookingController::notifyWaitlistUsers()` (line 1202-1210)

**Process**:
1. Reject the original `CartTransaction`
2. Reject all associated `Booking` records
3. For each waitlist entry in order:
   - **NEW**: Convert `WaitlistCartItem` → `CartItem`
   - **NEW**: Convert `WaitlistCartTransaction` → `CartTransaction`
   - Create new `Booking` from waitlist data
   - Send notification email to waitlisted user

**Code Example**:
```php
// Reject transaction
$transaction->update(['approval_status' => 'rejected']);

// Process waitlist users
$waitlistCartService = app(\App\Services\WaitlistCartService::class);
foreach ($waitlistEntries as $waitlistEntry) {
    // NEW: Convert waitlist cart records to actual booking
    $newBooking = $waitlistCartService->convertWaitlistToBooking($waitlistEntry);

    // Send notification
    $waitlistEntry->sendNotification();
}
```

**What happens in `convertWaitlistToBooking()`**:
1. Find all `WaitlistCartItem` records for this waitlist entry
2. Create new `CartTransaction` from `WaitlistCartTransaction` data
3. Create new `CartItem` records from `WaitlistCartItem` data
4. Create new `Booking` with the new `CartTransaction`
5. Mark `WaitlistCartItem` status as 'converted'
6. Mark `WaitlistCartTransaction` status as 'converted'
7. Update `BookingWaitlist.status` = 'converted'
8. Update `BookingWaitlist.converted_cart_transaction_id` = new transaction ID

## Service Methods

### WaitlistCartService

#### `createWaitlistCartRecords()`
**Purpose**: Create waitlist cart records from existing cart items (cart checkout scenario)

**Parameters**:
- `BookingWaitlist $waitlistEntry`
- `CartItem $originalCartItem`
- `CartTransaction $originalCartTransaction`

**Returns**: `['waitlistCartItem' => WaitlistCartItem, 'waitlistCartTransaction' => WaitlistCartTransaction]`

#### `createWaitlistCartRecordsFromWaitlist()`
**Purpose**: Create waitlist cart records from waitlist data (direct booking scenario)

**Parameters**:
- `BookingWaitlist $waitlistEntry`

**Returns**: `['waitlistCartItem' => WaitlistCartItem, 'waitlistCartTransaction' => WaitlistCartTransaction]`

#### `convertWaitlistToBooking()`
**Purpose**: Convert waitlist cart records to actual bookings when slot becomes available

**Parameters**:
- `BookingWaitlist $waitlistEntry`

**Returns**: `Booking` (the newly created booking)

**Process**:
1. Fetches all `WaitlistCartItem` for the waitlist entry
2. Creates new `CartTransaction` with data from `WaitlistCartTransaction`
3. Creates new `CartItem` records with data from `WaitlistCartItem` records
4. Creates new `Booking` linked to the new cart transaction
5. Marks waitlist cart records as 'converted'

#### `rejectWaitlistCartRecords()`
**Purpose**: Reject waitlist cart records when original booking is approved

**Parameters**:
- `BookingWaitlist $waitlistEntry`

**Returns**: `void`

**Process**:
1. Updates all `WaitlistCartItem` to status 'rejected'
2. Updates all `WaitlistCartTransaction` to approval_status 'rejected'
3. Updates `BookingWaitlist` to status 'cancelled'

## Database Schema

### `waitlist_cart_transactions`
Stores transaction-level information for waitlist bookings.

**Key Fields**:
- `id` - Primary key
- `user_id` - User who created the waitlist
- `booking_waitlist_id` - Link to BookingWaitlist
- `total_price`, `status`, `approval_status`, etc.

### `waitlist_cart_items`
Stores individual time slots for waitlist bookings.

**Key Fields**:
- `id` - Primary key
- `waitlist_cart_transaction_id` - Link to WaitlistCartTransaction
- `booking_waitlist_id` - Link to BookingWaitlist
- `court_id`, `sport_id`, `booking_date`, `start_time`, `end_time`, etc.

## Status Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│              User Attempts to Book Slot                 │
│              (Slot already pending approval)            │
└────────────────────────┬────────────────────────────────┘
                         │
                         ▼
         ┌───────────────────────────────┐
         │  Create BookingWaitlist       │
         │  Create CartItem              │
         │  Create CartTransaction       │
         │  Create WaitlistCartItem      │◄──── NEW
         │  Create WaitlistCartTransaction│◄──── NEW
         └───────────────┬───────────────┘
                         │
                         │
        ┌────────────────┴────────────────┐
        │                                 │
        ▼                                 ▼
┌───────────────────┐          ┌──────────────────────┐
│ Original Booking  │          │ Original Booking     │
│    APPROVED       │          │    REJECTED          │
└────────┬──────────┘          └──────────┬───────────┘
         │                                 │
         ▼                                 ▼
┌─────────────────────────┐    ┌────────────────────────────┐
│ WaitlistCartItem        │    │ Convert Waitlist:          │
│  → status: 'rejected'   │    │ WaitlistCartItem           │
│                         │    │  → CartItem                │
│ WaitlistCartTransaction │    │ WaitlistCartTransaction    │
│  → status: 'rejected'   │    │  → CartTransaction         │
│                         │    │ Create Booking             │
│ BookingWaitlist         │    │                            │
│  → status: 'cancelled'  │    │ BookingWaitlist            │
│                         │    │  → status: 'converted'     │
│ Slot no longer available│    │                            │
└─────────────────────────┘    │ User notified to pay       │
                                └────────────────────────────┘
```

## Benefits

✅ **Clear Separation**: Waitlist and regular bookings have distinct data models
✅ **No Conflicts**: References are explicit and don't interfere with each other
✅ **Better Data Integrity**: Each system has its own constraints and validation
✅ **Easier Debugging**: Clear data lineage from waitlist → waitlist cart → booking
✅ **Backward Compatible**: Existing data continues to work
✅ **Atomic Operations**: All conversions happen in database transactions
✅ **Audit Trail**: Can track the full lifecycle of a waitlist entry

## Testing Checklist

- [ ] User adds booking to cart for pending slot → waitlist created with waitlist cart records
- [ ] Admin approves original booking → waitlist cart records marked as rejected
- [ ] Admin rejects original booking → waitlist cart records converted to actual cart records
- [ ] Multiple users on waitlist → conversion happens in correct order
- [ ] Direct booking to waitlisted slot → waitlist cart records created
- [ ] Waitlist email notifications sent correctly
- [ ] QR codes generated for converted bookings
- [ ] All database transactions roll back on error

## Migration Notes

1. **Migrations**: Two new tables created (`waitlist_cart_transactions`, `waitlist_cart_items`)
2. **No Data Migration Needed**: These are new tables for new functionality
3. **Existing Waitlist Entries**: Continue to work as before; new entries use the new system
4. **Observer Registered**: `WaitlistCartItemObserver` automatically registered in `AppServiceProvider`

## Future Enhancements

- [ ] Implement court change sync logic in `WaitlistCartItemObserver`
- [ ] Implement date/time change sync logic in `WaitlistCartItemObserver`
- [ ] Add admin dashboard to view waitlist cart records
- [ ] Add analytics for waitlist conversion rates
- [ ] Add bulk operations for managing waitlist entries
