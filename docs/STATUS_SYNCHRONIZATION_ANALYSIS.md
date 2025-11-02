# Status Synchronization Analysis: 4-Table System

## Executive Summary

This document analyzes the synchronization of status updates across four interconnected tables:
- **bookings**
- **booking_waitlists**
- **cart_transactions**
- **cart_items**

**Overall Status**: ⚠️ **PARTIAL SYNCHRONIZATION** - Critical gaps exist that could lead to data inconsistency.

---

## Table Relationships

```
cart_transactions (parent)
    ├── cart_items (children)
    ├── bookings (children)
    └── booking_waitlists (via foreign key)

bookings
    ├── cart_transaction_id (FK)
    └── booking_waitlist_id (FK)

cart_items
    ├── cart_transaction_id (FK)
    └── booking_waitlist_id (FK)

booking_waitlists
    ├── pending_cart_transaction_id (FK)
    └── converted_cart_transaction_id (FK)
```

---

## Critical Operations Analysis

### 1. Cart Transaction Approval ⚠️ **NO TRANSACTION WRAPPER**

**Location**: `CartTransactionController@approve` (lines 176-296)

**Process Flow**:
```php
1. Update cart_transaction (approval_status = 'approved')
2. Loop through bookings: Update each booking (status = 'approved')
3. Broadcast events for each booking
4. Send email notification
5. Cancel waitlist users (separate process)
```

**Issues**:
- ❌ No `DB::beginTransaction()` wrapper
- ❌ Sequential updates to bookings (not atomic)
- ❌ If booking update fails mid-loop, partial updates occur
- ❌ If waitlist cancellation fails, transaction is already approved
- ⚠️ Email failures are caught, but data is already committed

**Risk Level**: 🔴 **HIGH** - Can result in:
- Cart transaction marked approved but some bookings still pending
- Waitlist entries not cancelled when booking is approved
- QR codes not generated for all bookings

**Example Failure Scenario**:
```
1. Cart transaction updated to "approved" ✓
2. Booking 1 updated to "approved" ✓
3. Booking 2 update fails (DB error) ✗
4. Booking 3 never attempted ✗
Result: Transaction is "approved" but bookings 2 & 3 still "pending"
```

---

### 2. Cart Transaction Rejection ⚠️ **NO TRANSACTION WRAPPER**

**Location**: `CartTransactionController@reject` (lines 301-342)

**Process Flow**:
```php
1. Update cart_transaction (approval_status = 'rejected')
2. Bulk update all bookings (status = 'rejected')
3. Loop through bookings: Broadcast events
4. Notify waitlist users (separate process)
```

**Issues**:
- ❌ No `DB::beginTransaction()` wrapper
- ⚠️ Bulk update is better than loop, but still not atomic with transaction update
- ❌ If bulk update fails, transaction is already rejected
- ❌ Waitlist notification failures are silent

**Risk Level**: 🟡 **MEDIUM** - Better than approval due to bulk update, but still has gaps

**Potential Issue**:
```
1. Cart transaction updated to "rejected" ✓
2. Bulk bookings update fails (DB error) ✗
Result: Transaction is "rejected" but bookings still show as "pending" or "approved"
```

---

### 3. Checkout Process ✅ **PROPERLY WRAPPED**

**Location**: `CartController@checkout` (lines 719-1125)

**Process Flow**:
```php
DB::beginTransaction();
try {
    1. Validate cart items
    2. Check availability
    3. Create all bookings
    4. Update cart_transaction
    5. Update cart_items
    6. Handle waitlist conversions
    DB::commit();
} catch {
    DB::rollBack();
}
```

**Status**: ✅ **GOOD** - Properly uses database transactions

**Protection**: If any step fails, everything rolls back atomically

---

### 4. Proof of Payment Upload ⚠️ **NO TRANSACTION WRAPPER**

**Location**:
- `CartTransactionController@uploadProofOfPayment` (lines 669-748)
- `BookingController@uploadProofOfPayment` (lines 441-540)

**Process Flow**:
```php
1. Upload files to storage
2. Update cart_transaction (payment_status = 'paid')
3. Update all associated bookings (payment_status = 'paid')
```

**Issues**:
- ❌ No `DB::beginTransaction()` wrapper
- ❌ File upload not atomic with DB updates
- ❌ If booking update fails, transaction is marked paid but bookings are not

**Risk Level**: 🟡 **MEDIUM**

**Potential Issue**:
```
1. Files uploaded to storage ✓
2. Cart transaction updated ✓
3. Bookings update fails ✗
Result: Transaction shows "paid" but individual bookings show "unpaid"
```

---

### 5. QR Code Verification ⚠️ **PARTIAL TRANSACTION**

**Location**: `CartTransactionController@verifyQr` (lines 532-594)

**Process Flow**:
```php
1. Find and validate transaction
2. Update transaction (status = 'checked_in')
3. Bulk update bookings (status = 'completed')
4. Broadcast events for each booking
```

**Issues**:
- ❌ No `DB::beginTransaction()` wrapper
- ⚠️ Uses bulk update (better) but not atomic

---

### 6. Cart Item Observer ⚠️ **NO TRANSACTION WRAPPER**

**Location**: `CartItemObserver@updated` (lines 16-102)

**Process Flow**:
```php
When cart_item.status = 'cancelled':
    1. Find related bookings
    2. If all cart items cancelled: Cancel booking
    3. Otherwise: Recalculate booking times and price
```

**Issues**:
- ❌ No `DB::transaction()` wrapper
- ❌ Observer operations not atomic with the original cart item update
- ❌ Multiple booking updates not wrapped together

**Risk Level**: 🟡 **MEDIUM**

---

### 7. Waitlist Cancellation Process ⚠️ **NO TRANSACTION WRAPPER**

**Location**: `CartTransactionController@cancelWaitlistUsers` (lines 349-435)

**Process Flow**:
```php
For each booking in transaction:
    For each waitlist entry:
        1. Find and update cart_items (status = 'rejected')
        2. Update cart_transaction (approval_status = 'rejected')
        3. Update bookings (status = 'rejected')
        4. Cancel waitlist entry
        5. Send cancellation email
```

**Issues**:
- ❌ No `DB::beginTransaction()` at method level
- ⚠️ Multiple tables updated sequentially
- ⚠️ Exceptions are caught and ignored silently

---

### 8. Waitlist Notification (Slot Available) ⚠️ **NESTED TRANSACTIONS**

**Location**: `CartTransactionController@notifyWaitlistUsers` (lines 441-504)

**Process Flow**:
```php
For each waitlist entry:
    DB::beginTransaction(); // ⚠️ Inside loop
    try {
        1. Create new booking
        2. Update cart_items (booking_waitlist_id = null)
        3. Update cart_transaction (booking_waitlist_id = null)
        4. Update waitlist (status = 'notified')
        5. Send email
        DB::commit();
    } catch {
        DB::rollBack();
    }
```

**Issues**:
- ⚠️ Transaction wrapper is per waitlist entry (good)
- ✅ Each waitlist processing is atomic
- ⚠️ But parent method has no transaction, so failures between entries can occur

---

## Status Field Mappings

### cart_transactions.approval_status
- `pending` - Awaiting admin approval
- `approved` - Admin approved
- `rejected` - Admin rejected
- `pending_waitlist` - From waitlist conversion

### cart_transactions.status
- `pending` - In cart, not checked out
- `completed` - Checked out (bookings created)
- `checked_in` - User checked in at venue
- `expired` - Cart expired

### bookings.status
- `pending` - Awaiting approval
- `approved` - Approved by admin
- `checked_in` - User checked in
- `rejected` - Rejected by admin
- `cancelled` - Cancelled
- `completed` - Booking completed
- `recurring_schedule` - Part of recurring schedule

### cart_items.status
- `pending` - In cart
- `completed` - Checked out
- `cancelled` - User cancelled
- `rejected` - Admin rejected

### booking_waitlists.status
- `pending` - Waiting for slot
- `notified` - Slot available, user notified
- `converted` - Converted to booking
- `expired` - Notification expired
- `cancelled` - Cancelled

---

## Synchronization Summary

| Operation | Transaction Wrapped | Tables Updated | Atomic? | Risk Level |
|-----------|-------------------|----------------|---------|------------|
| **Cart Approval** | ❌ No | cart_transactions, bookings, booking_waitlists | ❌ No | 🔴 HIGH |
| **Cart Rejection** | ❌ No | cart_transactions, bookings, booking_waitlists | ❌ No | 🟡 MEDIUM |
| **Checkout** | ✅ Yes | cart_transactions, cart_items, bookings, booking_waitlists | ✅ Yes | ✅ LOW |
| **Proof Upload (Cart)** | ❌ No | cart_transactions, bookings | ❌ No | 🟡 MEDIUM |
| **Proof Upload (Booking)** | ❌ No | bookings, cart_transactions | ❌ No | 🟡 MEDIUM |
| **QR Verification** | ❌ No | cart_transactions, bookings | ❌ No | 🟡 MEDIUM |
| **Cart Item Cancel** | ❌ No | cart_items, bookings | ❌ No | 🟡 MEDIUM |
| **Waitlist Cancel** | ❌ No | cart_items, cart_transactions, bookings, booking_waitlists | ❌ No | 🔴 HIGH |
| **Waitlist Notify** | ⚠️ Partial | bookings, cart_items, cart_transactions, booking_waitlists | ⚠️ Per-entry | 🟡 MEDIUM |

---

## Critical Issues Summary

### 🔴 **CRITICAL** Issues

1. **Cart Transaction Approval not atomic**
   - File: `CartTransactionController.php:176-296`
   - Impact: Can result in approved transaction with pending bookings
   - Frequency: Every admin approval

2. **Waitlist Cancellation not atomic**
   - File: `CartTransactionController.php:349-435`
   - Impact: Can result in inconsistent state across all 4 tables
   - Frequency: Every approval that has waitlist entries

### 🟡 **HIGH PRIORITY** Issues

3. **Proof of Payment updates not atomic**
   - Files: `CartTransactionController.php:669-748`, `BookingController.php:441-540`
   - Impact: Transaction marked paid but bookings not updated
   - Frequency: Every payment upload

4. **Cart Item Observer not atomic**
   - File: `CartItemObserver.php:16-102`
   - Impact: Cart item cancelled but booking not updated
   - Frequency: Every cart item cancellation

5. **QR Verification not atomic**
   - File: `CartTransactionController.php:532-594`
   - Impact: Transaction marked checked-in but bookings not updated
   - Frequency: Every QR scan

---

## Recommended Fixes

### Priority 1: Fix Cart Approval (HIGH RISK)

```php
public function approve(Request $request, $id)
{
    DB::beginTransaction();
    try {
        $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])
            ->lockForUpdate() // Add pessimistic lock
            ->findOrFail($id);

        if ($transaction->approval_status === 'approved') {
            DB::rollBack();
            return response()->json(['message' => 'Transaction already approved'], 400);
        }

        // Generate QR code
        $qrData = json_encode([...]);

        // Update transaction
        $transaction->update([
            'approval_status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'qr_code' => $qrData
        ]);

        // Update all bookings in single query first
        $bookingIds = $transaction->bookings->pluck('id');

        // Bulk update for atomicity
        Booking::whereIn('id', $bookingIds)->update([
            'status' => 'approved'
        ]);

        // Then loop for individual QR codes (within same transaction)
        foreach ($transaction->bookings()->whereIn('id', $bookingIds)->get() as $booking) {
            $bookingQrData = json_encode([...]);
            $booking->update(['qr_code' => $bookingQrData]);
        }

        // Cancel waitlist within same transaction
        $this->cancelWaitlistUsers($transaction);

        DB::commit();

        // AFTER commit: Send email and broadcast events
        try {
            // Send email...
            // Broadcast events...
        } catch (\Exception $e) {
            Log::error('Post-approval notification failed', [...]);
        }

        return response()->json([...]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Failed to approve transaction',
            'error' => $e->getMessage()
        ], 500);
    }
}
```

### Priority 2: Fix Cart Rejection

```php
public function reject(Request $request, $id)
{
    DB::beginTransaction();
    try {
        $transaction = CartTransaction::with(['cartItems.court', 'bookings.court', 'user'])
            ->lockForUpdate()
            ->findOrFail($id);

        // Update transaction
        $transaction->update([
            'approval_status' => 'rejected',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
            'rejection_reason' => $request->reason
        ]);

        // Bulk update bookings
        $transaction->bookings()->update(['status' => 'rejected']);

        // Notify waitlist within same transaction
        $this->notifyWaitlistUsers($transaction, 'rejected');

        DB::commit();

        // AFTER commit: Broadcast events
        foreach ($transaction->bookings as $booking) {
            broadcast(new BookingStatusChanged($booking->fresh([...])))->toOthers();
        }

        return response()->json([...]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([...], 500);
    }
}
```

### Priority 3: Fix Proof of Payment Upload

```php
public function uploadProofOfPayment(Request $request, $id)
{
    // ... validation ...

    DB::beginTransaction();
    try {
        $uploadedPaths = [];

        // Upload files (do this first, before DB changes)
        foreach ($request->file('proof_of_payment') as $index => $file) {
            $filename = 'proof_txn_' . $transaction->id . '_' . time() . '_' . $index . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('proofs', $filename, 'public');
            $uploadedPaths[] = $path;
        }

        $proofOfPaymentJson = json_encode($uploadedPaths);

        // Update transaction
        $transaction->update([
            'proof_of_payment' => $proofOfPaymentJson,
            'payment_method' => $request->payment_method,
            'payment_status' => 'paid',
            'paid_at' => now()
        ]);

        // Update bookings atomically
        $transaction->bookings()->update([
            'proof_of_payment' => $proofOfPaymentJson,
            'payment_method' => $request->payment_method,
            'payment_status' => 'paid',
            'paid_at' => now()
        ]);

        DB::commit();

        return response()->json([...]);

    } catch (\Exception $e) {
        DB::rollBack();

        // Clean up uploaded files on failure
        foreach ($uploadedPaths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([...], 500);
    }
}
```

### Priority 4: Fix Cart Item Observer

```php
public function updated(CartItem $cartItem)
{
    if ($cartItem->isDirty('status') && $cartItem->status === 'cancelled') {
        // Wrap in transaction
        DB::transaction(function () use ($cartItem) {
            $this->syncBookingAfterCartItemCancellation($cartItem);
        });
    }
}
```

### Priority 5: Fix QR Verification

```php
public function verifyQr(Request $request)
{
    DB::beginTransaction();
    try {
        // ... validation ...

        // Update transaction
        $transaction->update([
            'status' => 'checked_in',
            'attendance_status' => 'showed_up'
        ]);

        // Update bookings atomically
        $transaction->bookings()->update([
            'status' => 'completed',
            'attendance_status' => 'showed_up'
        ]);

        DB::commit();

        // AFTER commit: Broadcast events
        foreach ($transaction->bookings as $booking) {
            broadcast(new BookingStatusChanged($booking->fresh([...])))->toOthers();
        }

        return response()->json([...]);

    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([...], 500);
    }
}
```

---

## Additional Recommendations

### 1. Add Database Indexes

Ensure foreign keys have indexes for faster joins and updates:

```sql
-- Check if these indexes exist
CREATE INDEX idx_bookings_cart_transaction_id ON bookings(cart_transaction_id);
CREATE INDEX idx_bookings_booking_waitlist_id ON bookings(booking_waitlist_id);
CREATE INDEX idx_cart_items_cart_transaction_id ON cart_items(cart_transaction_id);
CREATE INDEX idx_cart_items_booking_waitlist_id ON cart_items(booking_waitlist_id);
CREATE INDEX idx_booking_waitlists_pending_cart_transaction_id ON booking_waitlists(pending_cart_transaction_id);
CREATE INDEX idx_booking_waitlists_converted_cart_transaction_id ON booking_waitlists(converted_cart_transaction_id);
```

### 2. Add Status Consistency Check Command

Create an Artisan command to detect and report inconsistencies:

```php
php artisan make:command CheckStatusConsistency
```

This command should check:
- Cart transactions with `approval_status='approved'` but have bookings with `status='pending'`
- Cart transactions with `payment_status='paid'` but have bookings with `payment_status='unpaid'`
- Bookings with `cart_transaction_id` but mismatched status values

### 3. Add Logging

Add comprehensive logging for all status changes:

```php
Log::channel('status_sync')->info('Status sync started', [
    'operation' => 'cart_approval',
    'transaction_id' => $id,
    'timestamp' => now()
]);
```

### 4. Add Unit Tests

Create tests that verify atomicity:
- Test approval with database exception mid-process
- Test rejection with database exception
- Verify rollback works correctly

---

## Migration Plan

### Phase 1: Critical Fixes (Week 1)
1. Fix Cart Approval (Priority 1)
2. Fix Cart Rejection (Priority 2)
3. Deploy to staging
4. Test extensively

### Phase 2: High Priority (Week 2)
1. Fix Proof of Payment uploads
2. Fix QR Verification
3. Fix Cart Item Observer
4. Deploy to staging
5. Test extensively

### Phase 3: Monitoring & Validation (Week 3)
1. Add status consistency check command
2. Run command to identify existing inconsistencies
3. Create migration to fix existing data
4. Add comprehensive logging
5. Deploy to production with monitoring

### Phase 4: Testing & Documentation (Week 4)
1. Add unit tests for all critical paths
2. Add integration tests for multi-table updates
3. Update API documentation
4. Train team on new error handling

---

## Testing Checklist

Before deploying fixes, test these scenarios:

- [ ] Approve transaction with multiple bookings
- [ ] Approve transaction with database error mid-process
- [ ] Reject transaction with waitlist entries
- [ ] Upload proof of payment with file storage error
- [ ] Upload proof of payment with database error
- [ ] Cancel cart item with active booking
- [ ] Scan QR code with database error
- [ ] Process waitlist with database error
- [ ] Concurrent approval of same transaction (race condition)
- [ ] Network interruption during approval
- [ ] Full database transaction rollback verification

---

## Conclusion

The current system has **significant gaps** in status synchronization that could lead to data inconsistencies. The most critical issue is the **cart transaction approval process**, which updates multiple tables without transaction wrapping.

**Immediate Action Required**:
1. Implement DB transaction wrapping for cart approval/rejection
2. Move broadcasts and emails AFTER commit
3. Add proper error handling and rollback
4. Test thoroughly before production deployment

**Estimated Effort**: 2-3 weeks for full implementation and testing

**Risk if Not Fixed**: High probability of data inconsistencies leading to:
- Users paying but not getting approved bookings
- Bookings showing different status than parent transaction
- Waitlist not being properly cancelled/notified
- Lost revenue due to payment/booking mismatches
