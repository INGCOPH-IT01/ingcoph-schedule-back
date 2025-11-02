# Status Synchronization Quick Reference

## 🚨 **Critical Issues Found**

### Problem Summary
**4 tables need to stay synchronized** but **updates are NOT atomic** in several critical operations.

### The 4 Tables
1. **cart_transactions** - Parent transaction (shopping cart → checkout)
2. **cart_items** - Items in cart (individual time slots)
3. **bookings** - Actual court bookings created from cart
4. **booking_waitlists** - Users waiting for slots

---

## 🔴 **HIGH RISK Operations** (Need Immediate Fix)

### 1. Cart Approval (Most Critical)
- **File**: `CartTransactionController@approve` (line 176)
- **Problem**: Updates transaction first, then loops through bookings - NO transaction wrapper
- **Risk**: Transaction marked "approved" but bookings stuck as "pending"
- **Happens**: Every time admin approves a booking

```
Current Flow (BROKEN):
1. Update cart_transaction → approved ✓
2. Update booking 1 → approved ✓
3. Update booking 2 → FAILS ✗
4. Booking 3 never updated ✗
Result: Inconsistent state!
```

**Fix**: Wrap entire operation in `DB::transaction()`

---

### 2. Cart Rejection
- **File**: `CartTransactionController@reject` (line 301)
- **Problem**: Same issue as approval
- **Risk**: Transaction marked "rejected" but bookings not updated

---

### 3. Waitlist Cancellation
- **File**: `CartTransactionController@cancelWaitlistUsers` (line 349)
- **Problem**: Updates cart_items, cart_transactions, bookings, waitlists sequentially - NO wrapper
- **Risk**: Partial updates across all 4 tables
- **Happens**: When a booking is approved and waitlist users need to be notified

---

## 🟡 **MEDIUM RISK Operations**

### 4. Proof of Payment Upload
- **Files**:
  - `CartTransactionController@uploadProofOfPayment` (line 669)
  - `BookingController@uploadProofOfPayment` (line 441)
- **Problem**: Updates transaction, then bookings - NO wrapper
- **Risk**: Transaction marked "paid" but bookings show "unpaid"

### 5. QR Code Verification
- **File**: `CartTransactionController@verifyQr` (line 532)
- **Problem**: Updates transaction, then bookings - NO wrapper
- **Risk**: Transaction marked "checked_in" but bookings not updated

### 6. Cart Item Cancellation (Observer)
- **File**: `CartItemObserver@updated` (line 16)
- **Problem**: Updates bookings when cart item cancelled - NO wrapper
- **Risk**: Cart item cancelled but booking not updated

---

## ✅ **GOOD Operations** (Already Fixed)

### Checkout Process ✓
- **File**: `CartController@checkout` (line 719)
- **Status**: ✅ Properly wrapped in `DB::transaction()`
- **Protection**: If any step fails, everything rolls back

---

## 🔧 **How to Fix**

### Template for Fixing Operations

```php
public function approve(Request $request, $id)
{
    DB::beginTransaction();
    try {
        // 1. Lock the transaction
        $transaction = CartTransaction::lockForUpdate()->findOrFail($id);

        // 2. Update transaction
        $transaction->update([...]);

        // 3. Bulk update related records (faster & safer)
        $transaction->bookings()->update([...]);

        // 4. Handle waitlist/other updates
        $this->handleWaitlist($transaction);

        // 5. Commit all changes atomically
        DB::commit();

        // 6. AFTER commit: Send emails, broadcast events
        $this->sendNotifications($transaction);

        return response()->json([...]);

    } catch (\Exception $e) {
        // 7. Rollback on any error
        DB::rollBack();

        Log::error('Approval failed', [
            'transaction_id' => $id,
            'error' => $e->getMessage()
        ]);

        return response()->json(['error' => 'Failed to approve'], 500);
    }
}
```

### Key Principles

1. **Wrap in DB::transaction()** - Makes all updates atomic
2. **Lock for update** - Prevents race conditions
3. **Bulk updates** - Faster than loops, more atomic
4. **Commit before notifications** - Don't broadcast if not committed
5. **Rollback on error** - Undo ALL changes if any fail
6. **Log errors** - Track failures for debugging

---

## 📊 **Risk Assessment**

| Operation | Risk | Impact | Frequency |
|-----------|------|--------|-----------|
| Cart Approval | 🔴 HIGH | Users pay but not approved | Every approval |
| Cart Rejection | 🔴 HIGH | Inconsistent states | Every rejection |
| Waitlist Cancel | 🔴 HIGH | 4 tables out of sync | Every approval with waitlist |
| Proof Upload | 🟡 MEDIUM | Payment/booking mismatch | Every payment |
| QR Verify | 🟡 MEDIUM | Check-in not recorded | Every QR scan |
| Cart Item Cancel | 🟡 MEDIUM | Booking not updated | Every cancellation |

---

## 🧪 **Testing the Fixes**

### Manual Test Scenarios

1. **Approval with Database Error**
   ```bash
   # Simulate DB error mid-approval
   # Expected: Everything rolls back, no partial updates
   ```

2. **Concurrent Approvals**
   ```bash
   # Two admins approve same transaction simultaneously
   # Expected: Only one succeeds, other gets error
   ```

3. **Network Interruption**
   ```bash
   # Kill connection during approval
   # Expected: Either all committed or all rolled back
   ```

### Use the Consistency Checker

```bash
# Check for existing issues
php artisan status:check-consistency --verbose

# Auto-fix where possible
php artisan status:check-consistency --fix

# Verify fixes
php artisan status:check-consistency
```

---

## 📋 **Implementation Checklist**

### Phase 1: Critical Fixes (Week 1)
- [ ] Fix `CartTransactionController@approve`
- [ ] Fix `CartTransactionController@reject`
- [ ] Fix `CartTransactionController@cancelWaitlistUsers`
- [ ] Test on staging with real data
- [ ] Run consistency checker before/after

### Phase 2: Medium Priority (Week 2)
- [ ] Fix `CartTransactionController@uploadProofOfPayment`
- [ ] Fix `BookingController@uploadProofOfPayment`
- [ ] Fix `CartTransactionController@verifyQr`
- [ ] Fix `CartItemObserver@updated`
- [ ] Test on staging

### Phase 3: Validation (Week 3)
- [ ] Deploy to production
- [ ] Monitor logs for errors
- [ ] Run consistency checker daily
- [ ] Fix any data inconsistencies found

### Phase 4: Testing & Documentation (Week 4)
- [ ] Add unit tests for each fix
- [ ] Add integration tests
- [ ] Update team documentation
- [ ] Train team on error handling

---

## 🚀 **Quick Commands**

```bash
# Check for inconsistencies
php artisan status:check-consistency

# Check with details
php artisan status:check-consistency --verbose

# Auto-fix where possible
php artisan status:check-consistency --fix

# Check logs for sync errors
tail -f storage/logs/laravel.log | grep "status"

# Find transactions with mismatched statuses
mysql> SELECT ct.id, ct.approval_status, GROUP_CONCAT(b.status) as booking_statuses
       FROM cart_transactions ct
       LEFT JOIN bookings b ON b.cart_transaction_id = ct.id
       WHERE ct.approval_status = 'approved'
       GROUP BY ct.id
       HAVING booking_statuses NOT LIKE '%approved%';
```

---

## 💡 **Key Takeaways**

1. **Current State**: Multiple critical operations lack atomic updates
2. **Main Risk**: Data inconsistencies between cart_transactions and bookings
3. **Root Cause**: Missing `DB::transaction()` wrappers
4. **Solution**: Add transaction wrappers to all multi-table updates
5. **Timeline**: 2-3 weeks for complete fix and testing
6. **Priority**: HIGH - Affects revenue and user experience

---

## 📞 **Questions?**

If you have questions about:
- **What to fix first**: Start with Cart Approval (highest risk)
- **How to test**: Use the consistency checker command
- **Need help**: Refer to full analysis in `STATUS_SYNCHRONIZATION_ANALYSIS.md`

---

## 🔗 **Related Documentation**

- Full Analysis: [`STATUS_SYNCHRONIZATION_ANALYSIS.md`](./STATUS_SYNCHRONIZATION_ANALYSIS.md)
- Consistency Checker: `app/Console/Commands/CheckStatusConsistency.php`
- Database Relationships: See "Table Relationships" in full analysis
