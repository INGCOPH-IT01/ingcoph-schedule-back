# Status Synchronization Flow Diagrams

## Table Relationships

```
┌─────────────────────────┐
│   cart_transactions     │ (PARENT)
│  - id                   │
│  - approval_status      │
│  - payment_status       │
│  - status               │
└───────────┬─────────────┘
            │
            │ (One-to-Many)
            │
     ┌──────┴──────┬──────────────────┐
     │             │                  │
     ▼             ▼                  ▼
┌─────────┐  ┌──────────┐  ┌─────────────────┐
│cart_items│  │ bookings │  │booking_waitlists│
│- status  │  │- status  │  │- status         │
└──────────┘  └──────────┘  └─────────────────┘
```

---

## Current Flow: Cart Approval ⚠️ (BROKEN)

```
Admin Clicks "Approve"
        │
        ▼
┌────────────────────────────────────────┐
│  CartTransactionController@approve     │
│  ❌ NO DB::transaction() wrapper       │
└────────────────────────────────────────┘
        │
        ├─► Step 1: Update cart_transaction
        │   SET approval_status = 'approved'
        │   ✓ SUCCESS
        │
        ├─► Step 2: Loop through bookings
        │   │
        │   ├─► Update booking #1 → 'approved' ✓
        │   ├─► Update booking #2 → 'approved' ✓
        │   └─► Update booking #3 → ❌ DATABASE ERROR
        │       (Network timeout, deadlock, constraint error, etc.)
        │
        ├─► Step 3: Broadcast events
        │   └─► ⚠️ Only for bookings 1 & 2
        │
        └─► Step 4: Cancel waitlist
            └─► ❌ May fail, already committed

RESULT:
┌──────────────────────────────────────────┐
│ ❌ INCONSISTENT STATE                    │
│ - Transaction: APPROVED                  │
│ - Booking #1: APPROVED ✓                 │
│ - Booking #2: APPROVED ✓                 │
│ - Booking #3: PENDING ✗ (Should be approved)│
│ - User sees mixed status                 │
│ - No QR code for booking #3              │
└──────────────────────────────────────────┘
```

---

## Fixed Flow: Cart Approval ✅ (PROPOSED)

```
Admin Clicks "Approve"
        │
        ▼
┌────────────────────────────────────────┐
│  DB::beginTransaction()                │ ✓
│  CartTransactionController@approve     │
└────────────────────────────────────────┘
        │
        ├─► Step 1: Lock transaction
        │   CartTransaction::lockForUpdate()
        │   (Prevents concurrent modifications)
        │   ✓ LOCKED
        │
        ├─► Step 2: Validate
        │   if (already approved) {
        │       DB::rollBack()
        │       return error
        │   }
        │   ✓ VALID
        │
        ├─► Step 3: Update transaction
        │   SET approval_status = 'approved'
        │   ✓ SUCCESS (in memory, not committed)
        │
        ├─► Step 4: Bulk update ALL bookings
        │   UPDATE bookings
        │   SET status = 'approved'
        │   WHERE cart_transaction_id = ?
        │   ✓ ALL UPDATED (in memory)
        │
        ├─► Step 5: Individual QR codes
        │   Loop: Update each booking.qr_code
        │   ✓ ALL UPDATED (in memory)
        │
        ├─► Step 6: Cancel waitlist
        │   (Within same transaction)
        │   ✓ UPDATED (in memory)
        │
        ├─► Step 7: Check for errors
        │   if (any error) {
        │       throw exception
        │   }
        │   ✓ NO ERRORS
        │
        ▼
┌────────────────────────────────────────┐
│  DB::commit()                          │ ✓
│  (All changes saved atomically)        │
└────────────────────────────────────────┘
        │
        ├─► Step 8: Send email ✓
        │   (After commit, failure OK)
        │
        └─► Step 9: Broadcast events ✓
            (After commit, failure OK)

RESULT:
┌──────────────────────────────────────────┐
│ ✅ CONSISTENT STATE                      │
│ - Transaction: APPROVED                  │
│ - ALL Bookings: APPROVED                 │
│ - Waitlist: CANCELLED                    │
│ - All QR codes generated                 │
│ OR                                       │
│ - EVERYTHING ROLLED BACK (on any error) │
└──────────────────────────────────────────┘
```

---

## Error Handling: With vs Without Transactions

### Without Transaction (Current) ❌

```
Operation starts
     │
     ├─► Update Table 1 ✓ (COMMITTED)
     ├─► Update Table 2 ✓ (COMMITTED)
     ├─► Update Table 3 ✗ (FAILS)
     └─► Update Table 4 ⊗ (NEVER ATTEMPTED)

Result:
┌─────────────────────────────┐
│ Tables 1 & 2: MODIFIED      │
│ Tables 3 & 4: UNCHANGED     │
│ ❌ PARTIAL UPDATE           │
│ 💥 DATA INCONSISTENCY       │
└─────────────────────────────┘
```

### With Transaction (Proposed) ✅

```
DB::beginTransaction()
     │
     ├─► Update Table 1 ✓ (in memory)
     ├─► Update Table 2 ✓ (in memory)
     ├─► Update Table 3 ✗ (FAILS)
     │
     └─► catch (Exception) {
             DB::rollBack()
         }

Result:
┌─────────────────────────────┐
│ ALL Tables: UNCHANGED       │
│ ✅ NO PARTIAL UPDATES       │
│ ✅ DATA CONSISTENCY         │
└─────────────────────────────┘
```

---

## Race Condition: Concurrent Approvals

### Without Locking ❌

```
Time    Admin 1                      Admin 2
-----   -------------------------    -------------------------
T0      Click "Approve"
T1      Read transaction (pending)   Click "Approve"
T2      Update → approved            Read transaction (pending) ⚠️
T3      Send email                   Update → approved ⚠️
T4                                   Send email ⚠️

Result:
- Transaction approved twice
- User gets 2 emails
- Possible data corruption
```

### With Locking ✅

```
Time    Admin 1                      Admin 2
-----   -------------------------    -------------------------
T0      Click "Approve"
T1      DB::beginTransaction()
T2      lockForUpdate() ✓            Click "Approve"
T3      Update → approved            DB::beginTransaction()
T4                                   lockForUpdate() ⏳ (WAITING)
T5      DB::commit() ✓
T6                                   lockForUpdate() ✓ (ACQUIRED)
T7                                   Check: already approved
T8                                   DB::rollBack()
T9                                   Return error ✓

Result:
- Only Admin 1 succeeds
- Admin 2 gets "already approved" error
- No duplicate emails
- Data consistency maintained
```

---

## Checkout Flow (Already Fixed) ✅

```
User Clicks "Checkout"
        │
        ▼
┌────────────────────────────────────────┐
│  DB::beginTransaction() ✓              │
│  CartController@checkout               │
└────────────────────────────────────────┘
        │
        ├─► Validate cart items
        │   ✓
        ├─► Check availability
        │   ✓
        ├─► Create bookings (all)
        │   ├─► Booking 1 created
        │   ├─► Booking 2 created
        │   └─► Booking 3 created
        │   ✓
        ├─► Update cart_transaction
        │   SET status = 'completed'
        │   ✓
        ├─► Update cart_items
        │   SET status = 'completed'
        │   ✓
        ├─► Convert waitlist entries
        │   ✓
        │
        ▼
┌────────────────────────────────────────┐
│  DB::commit() ✓                        │
└────────────────────────────────────────┘
        │
        └─► Broadcast events ✓

If ANY step fails:
        │
        ▼
┌────────────────────────────────────────┐
│  catch (Exception)                     │
│  DB::rollBack() ✓                      │
│  Return error to user                  │
└────────────────────────────────────────┘

RESULT: ALL OR NOTHING ✓
```

---

## Proof of Payment Flow (Current) ⚠️

```
User Uploads Proof
        │
        ▼
❌ NO DB::transaction()
        │
        ├─► Upload files to storage
        │   ✓ FILES SAVED
        │
        ├─► Update cart_transaction
        │   SET payment_status = 'paid'
        │   ✓ COMMITTED
        │
        └─► Update bookings
            SET payment_status = 'paid'
            ❌ DATABASE ERROR

RESULT:
┌──────────────────────────────────┐
│ - Files: UPLOADED ✓              │
│ - Transaction: PAID ✓            │
│ - Bookings: UNPAID ✗             │
│ ❌ INCONSISTENT                  │
└──────────────────────────────────┘
```

---

## Proof of Payment Flow (Fixed) ✅

```
User Uploads Proof
        │
        ▼
┌────────────────────────────────────────┐
│  DB::beginTransaction() ✓              │
└────────────────────────────────────────┘
        │
        ├─► Upload files to storage
        │   ✓ FILES SAVED (track paths)
        │
        ├─► Update cart_transaction
        │   SET payment_status = 'paid'
        │   ✓ (in memory)
        │
        ├─► Bulk update bookings
        │   SET payment_status = 'paid'
        │   ✓ (in memory)
        │
        ▼
┌────────────────────────────────────────┐
│  DB::commit() ✓                        │
└────────────────────────────────────────┘

If ANY database step fails:
        │
        ├─► DB::rollBack()
        │   ✓ Database changes reverted
        │
        └─► Delete uploaded files
            Storage::delete($paths)
            ✓ Clean up files

RESULT: ALL OR NOTHING ✓
```

---

## Status Flow: Typical Booking Lifecycle

```
USER ACTIONS                     TABLE STATES
                    cart_trans | cart_items | bookings | waitlist
                    -----------|------------|----------|----------
1. Add to cart      pending    | pending    | -        | -
   │
2. Checkout         completed  | completed  | pending  | -
   │                paid       |            | unpaid   |
   │
3. Upload proof     completed  | completed  | pending  | -
   │                paid       |            | paid     |
   │
4. Admin approves   completed  | completed  | approved | cancelled*
   │                approved   |            |          |
   │                paid       |            |          |
   │
5. User checks in   checked_in | completed  | checked_in| -
   │
6. Booking ends     checked_in | completed  | completed| -

* If there were waitlist entries
```

---

## Multi-Booking Transaction Flow

```
User books 3 time slots (9am, 10am, 11am)
                    │
                    ▼
            ┌───────────────┐
            │cart_transaction│
            │   ID: 123     │
            └───────┬───────┘
                    │
        ┌───────────┼───────────┐
        │           │           │
        ▼           ▼           ▼
   ┌─────────┐ ┌─────────┐ ┌─────────┐
   │cart_item│ │cart_item│ │cart_item│
   │  9am    │ │  10am   │ │  11am   │
   └─────────┘ └─────────┘ └─────────┘

After Checkout (within ONE transaction):
                    │
                    ▼
            ┌───────────────┐
            │cart_transaction│
            │ status: completed│
            └───────┬───────┘
                    │
        ┌───────────┼───────────┐
        │           │           │
        ▼           ▼           ▼
   ┌─────────┐ ┌─────────┐ ┌─────────┐
   │ booking │ │ booking │ │ booking │
   │  9am    │ │  10am   │ │  11am   │
   │ pending │ │ pending │ │ pending │
   └─────────┘ └─────────┘ └─────────┘

⚠️ CURRENT PROBLEM:
Admin approves → Updates happen sequentially
If one fails, others already committed = INCONSISTENT

✅ SOLUTION:
Wrap in transaction → ALL bookings updated atomically
If one fails, EVERYTHING rolls back = CONSISTENT
```

---

## Summary: Transaction Guarantees

### ACID Properties

```
┌──────────────────────────────────────────────────┐
│ A - Atomicity                                    │
│     All operations succeed OR all fail           │
│     ✅ With DB::transaction()                    │
│     ❌ Without it                                │
├──────────────────────────────────────────────────┤
│ C - Consistency                                  │
│     Database always in valid state               │
│     ✅ With DB::transaction()                    │
│     ❌ Without it (partial updates)              │
├──────────────────────────────────────────────────┤
│ I - Isolation                                    │
│     Concurrent transactions don't interfere      │
│     ✅ With lockForUpdate()                      │
│     ❌ Without it (race conditions)              │
├──────────────────────────────────────────────────┤
│ D - Durability                                   │
│     Committed data persists                      │
│     ✅ Always (database feature)                 │
└──────────────────────────────────────────────────┘
```

---

## Key Takeaways

1. **Without transactions**: Updates happen immediately, no rollback possible
2. **With transactions**: Changes buffered in memory until commit
3. **On error without transaction**: Partial updates = data corruption
4. **On error with transaction**: Complete rollback = data integrity
5. **Locking**: Prevents concurrent modifications (race conditions)
6. **Bulk updates**: Faster and safer than loops

**Bottom Line**: Always use `DB::transaction()` when updating multiple related tables!
