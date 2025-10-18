# Booking Status Synchronization with Cart Transactions

## Overview

This document explains how booking statuses are automatically synchronized with cart transaction approval statuses to maintain data consistency.

## The Problem

When a cart transaction is approved or rejected, all associated bookings should reflect the same status. This ensures:
- Users see consistent information across the system
- QR codes are only generated for approved bookings
- Real-time updates work correctly
- Email notifications reflect accurate status

## The Solution

### 1. Cart Transaction Approval

**Location**: `CartTransactionController@approve`

When a cart transaction is approved:

```php
// Update cart transaction
$transaction->update([
    'approval_status' => 'approved',
    'approved_by' => $request->user()->id,
    'approved_at' => now(),
    'qr_code' => $qrData
]);

// Update each associated booking
foreach ($transaction->bookings as $booking) {
    $booking->update([
        'status' => 'approved',
        'qr_code' => $bookingQrData  // Each booking gets unique QR code
    ]);

    // Broadcast real-time status change
    broadcast(new BookingStatusChanged($booking, 'pending', 'approved'));
}
```

**Key Features**:
- Transaction loads bookings relationship: `with(['bookings.court'])`
- Each booking gets its own unique QR code containing specific details
- Real-time events are broadcast for each booking
- Email notification is sent to the user

### 2. Cart Transaction Rejection

**Location**: `CartTransactionController@reject`

When a cart transaction is rejected:

```php
// Update cart transaction
$transaction->update([
    'approval_status' => 'rejected',
    'approved_by' => $request->user()->id,
    'approved_at' => now(),
    'rejection_reason' => $request->reason
]);

// Update all associated bookings in one query
$transaction->bookings()->update([
    'status' => 'rejected'
]);

// Broadcast real-time status change for each booking
foreach ($transaction->bookings as $booking) {
    broadcast(new BookingStatusChanged($booking, 'pending', 'rejected'));
}
```

**Key Features**:
- Bulk update for efficiency: `bookings()->update()`
- Real-time events are broadcast for each booking
- Rejection reason is stored on the transaction

### 3. Helper Method

**Location**: `CartTransaction` Model

A helper method is available for programmatic status synchronization:

```php
/**
 * Sync bookings status with cart transaction approval status
 */
public function syncBookingsStatus(string $status): void
{
    $this->bookings()->update(['status' => $status]);
}
```

**Usage**:
```php
$transaction->syncBookingsStatus('approved');
$transaction->syncBookingsStatus('rejected');
$transaction->syncBookingsStatus('pending');
```

## Status Flow Diagram

```
Cart Transaction Created
        ↓
approval_status: 'pending'
        ↓
    ┌───────────────┐
    │   Admin       │
    │   Reviews     │
    └───────────────┘
           ↓
    ┌──────┴──────┐
    │             │
Approve        Reject
    │             │
    ↓             ↓
approval_status  approval_status
= 'approved'     = 'rejected'
    ↓             ↓
bookings.status  bookings.status
= 'approved'     = 'rejected'
    ↓             ↓
Generate QR      Cancel Booking
    ↓             ↓
Send Email       Notify User
```

## Database Relationships

```
CartTransaction (1) ──── (Many) Bookings
     │                        │
     │                        │
approval_status          status
```

**Constraint**: `bookings.status` should always match `cart_transactions.approval_status`

## QR Code Generation

When bookings are approved, each booking receives a unique QR code containing:

```json
{
    "transaction_id": 123,
    "booking_id": 456,
    "user_id": 789,
    "user_name": "John Doe",
    "court_name": "Court A",
    "date": "2025-10-18",
    "start_time": "14:00:00",
    "end_time": "15:00:00",
    "price": "500.00",
    "payment_method": "gcash",
    "approved_at": "2025-10-18 10:30:00",
    "type": "cart_transaction"
}
```

This QR code is:
- Stored in `bookings.qr_code` field
- Used for venue check-in verification
- Displayed in the BookingDetailsDialog component (frontend)
- Included in approval emails

## Real-Time Updates

Both approval and rejection trigger real-time broadcasts:

```php
broadcast(new BookingStatusChanged($booking, $oldStatus, $newStatus))
```

This ensures:
- Admin dashboard updates immediately
- User bookings view updates in real-time
- Staff/venue staff see updated statuses

## Best Practices

1. **Always load relationships**: When updating cart transactions, load bookings:
   ```php
   CartTransaction::with(['bookings.court'])->find($id)
   ```

2. **Use transactions for consistency**: Status updates should be atomic:
   ```php
   DB::transaction(function() {
       $transaction->update(['approval_status' => 'approved']);
       $transaction->syncBookingsStatus('approved');
   });
   ```

3. **Broadcast events**: Always broadcast status changes for real-time updates

4. **Validate before updating**: Check current status to prevent invalid transitions:
   ```php
   if ($transaction->approval_status === 'approved') {
       return response()->json(['message' => 'Already approved'], 400);
   }
   ```

## Related Files

- **Backend**:
  - `app/Http/Controllers/Api/CartTransactionController.php` - Approval/rejection logic
  - `app/Models/CartTransaction.php` - Model with syncBookingsStatus() helper
  - `app/Models/Booking.php` - Booking model
  - `app/Events/BookingStatusChanged.php` - Real-time event

- **Frontend**:
  - `src/components/BookingDetailsDialog.vue` - Displays QR code for approved bookings
  - `src/views/Bookings.vue` - Admin booking management

## Testing Checklist

When modifying status synchronization:

- [ ] Approve a cart transaction → All bookings become 'approved'
- [ ] Reject a cart transaction → All bookings become 'rejected'
- [ ] QR codes are generated only for approved bookings
- [ ] Real-time updates work on frontend
- [ ] Email notifications are sent
- [ ] Status transitions are validated (can't approve twice)
- [ ] Booking IDs are correctly extracted from transactions

## Future Enhancements

Consider implementing:
1. **Model Observers**: Auto-sync on any approval_status change
2. **Status History**: Track status changes over time
3. **Bulk Operations**: Approve/reject multiple transactions at once
4. **Partial Approval**: Approve some bookings in a transaction, reject others
