# Status Synchronization - Action Plan

## 📋 Executive Summary

**Status**: 🔴 **CRITICAL ISSUES FOUND**

The system has **synchronization gaps** that can cause data inconsistencies across 4 interconnected tables:
- cart_transactions
- cart_items
- bookings
- booking_waitlists

**Impact**: Users may pay for bookings that aren't properly approved, or bookings may show different statuses than their parent transactions.

**Root Cause**: Missing database transaction wrappers on multi-table updates.

**Timeline**: 2-3 weeks to fix, test, and deploy.

---

## 🚨 Immediate Actions Required

### 1. Run Consistency Check (TODAY)

```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back

# Check for existing inconsistencies
php artisan status:check-consistency --verbose

# Save the output
php artisan status:check-consistency --verbose > consistency_report_$(date +%Y%m%d).txt
```

**Purpose**: Identify how many records are currently inconsistent.

---

### 2. Review Critical Code (THIS WEEK)

Files that need immediate attention:

1. **`app/Http/Controllers/Api/CartTransactionController.php`**
   - Line 176-296: `approve()` method
   - Line 301-342: `reject()` method
   - Line 349-435: `cancelWaitlistUsers()` method
   - Line 441-504: `notifyWaitlistUsers()` method
   - Line 669-748: `uploadProofOfPayment()` method

2. **`app/Http/Controllers/Api/BookingController.php`**
   - Line 441-540: `uploadProofOfPayment()` method

3. **`app/Observers/CartItemObserver.php`**
   - Line 16-102: `updated()` method

---

## 🔧 Implementation Plan

### Week 1: Critical Fixes

#### Priority 1: Cart Transaction Approval ⚠️ HIGHEST RISK

**File**: `app/Http/Controllers/Api/CartTransactionController.php`

**Method**: `approve()` (Line 176)

**Current Risk**: 🔴 **HIGH** - Transaction approved but bookings may stay pending

**Fix Required**:
```php
public function approve(Request $request, $id)
{
    // WRAP ENTIRE OPERATION
    DB::beginTransaction();
    try {
        // Lock to prevent concurrent modifications
        $transaction = CartTransaction::with([...])
            ->lockForUpdate()
            ->findOrFail($id);

        // Validate
        if ($transaction->approval_status === 'approved') {
            DB::rollBack();
            return response()->json(['message' => 'Already approved'], 400);
        }

        // Update transaction
        $transaction->update([...]);

        // BULK UPDATE all bookings (atomic)
        $bookingIds = $transaction->bookings->pluck('id');
        Booking::whereIn('id', $bookingIds)->update(['status' => 'approved']);

        // Update individual QR codes (within same transaction)
        foreach ($transaction->bookings as $booking) {
            $booking->update(['qr_code' => ...]);
        }

        // Cancel waitlist (within same transaction)
        $this->cancelWaitlistUsers($transaction);

        // COMMIT ALL CHANGES
        DB::commit();

        // AFTER COMMIT: Send emails and broadcast
        // (failures here won't affect data integrity)
        try {
            // Send email...
            // Broadcast events...
        } catch (\Exception $e) {
            Log::error('Post-approval notification failed', [...]);
        }

        return response()->json([...]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Approval failed', [
            'transaction_id' => $id,
            'error' => $e->getMessage()
        ]);
        return response()->json(['error' => 'Failed to approve'], 500);
    }
}
```

**Testing**:
1. Test normal approval flow
2. Test with database error mid-process (should rollback)
3. Test concurrent approvals (should prevent duplicates)
4. Run consistency checker before/after

**Estimated Time**: 4-6 hours (including testing)

---

#### Priority 2: Cart Transaction Rejection

**File**: Same as above

**Method**: `reject()` (Line 301)

**Fix**: Similar structure to approve()

**Estimated Time**: 2-3 hours

---

#### Priority 3: Waitlist Cancellation

**File**: Same as above

**Method**: `cancelWaitlistUsers()` (Line 349)

**Current Issue**: Updates 4 tables sequentially without transaction wrapper

**Fix**: Wrap entire method in transaction, or ensure it's always called within a transaction

**Estimated Time**: 3-4 hours

---

### Week 2: Medium Priority Fixes

#### Priority 4: Proof of Payment Upload (Cart)

**File**: `app/Http/Controllers/Api/CartTransactionController.php`

**Method**: `uploadProofOfPayment()` (Line 669)

**Estimated Time**: 2-3 hours

---

#### Priority 5: Proof of Payment Upload (Booking)

**File**: `app/Http/Controllers/Api/BookingController.php`

**Method**: `uploadProofOfPayment()` (Line 441)

**Estimated Time**: 2-3 hours

---

#### Priority 6: QR Verification

**File**: `app/Http/Controllers/Api/CartTransactionController.php`

**Method**: `verifyQr()` (Line 532)

**Estimated Time**: 2 hours

---

#### Priority 7: Cart Item Observer

**File**: `app/Observers/CartItemObserver.php`

**Method**: `updated()` (Line 16)

**Fix**: Wrap observer logic in DB::transaction()

**Estimated Time**: 1-2 hours

---

### Week 3: Testing & Deployment

#### Staging Deployment

1. **Deploy all fixes to staging**
2. **Run comprehensive tests**:
   - Normal flow tests
   - Error simulation tests
   - Concurrent operation tests
   - Load tests
3. **Run consistency checker**
4. **Monitor logs for 3-5 days**

#### Production Deployment

1. **Backup database**
2. **Deploy during low-traffic period**
3. **Run consistency checker immediately after**
4. **Monitor closely for 24 hours**
5. **Fix any data inconsistencies found**

---

### Week 4: Validation & Documentation

#### Data Cleanup

```bash
# Run consistency checker
php artisan status:check-consistency --verbose

# Review issues
cat consistency_report.txt

# Fix where possible
php artisan status:check-consistency --fix

# Manual review for complex cases
# (Orphaned records, missing bookings, etc.)
```

#### Add Monitoring

1. **Set up daily consistency checks**:
   ```bash
   # Add to cron
   0 2 * * * cd /path/to/project && php artisan status:check-consistency --verbose >> /var/log/consistency_check.log
   ```

2. **Add alerts for inconsistencies**:
   ```php
   // In AppServiceProvider or similar
   if (app()->environment('production')) {
       Schedule::command('status:check-consistency')
           ->daily()
           ->onFailure(function () {
               // Send alert to admin
               Mail::to('admin@example.com')->send(...);
           });
   }
   ```

#### Documentation

1. Update API documentation
2. Create runbook for handling inconsistencies
3. Train team on new error handling
4. Document rollback procedures

---

## 📊 Testing Checklist

### Unit Tests

Create tests for each fixed method:

```php
// Example: CartTransactionApprovalTest.php

public function test_approve_transaction_with_multiple_bookings()
{
    // Arrange: Create transaction with 3 bookings
    // Act: Approve transaction
    // Assert: All bookings are approved
}

public function test_approve_transaction_rolls_back_on_error()
{
    // Arrange: Create transaction, mock DB error
    // Act: Attempt approval
    // Assert: Nothing is updated
}

public function test_approve_transaction_prevents_concurrent_approvals()
{
    // Arrange: Create transaction
    // Act: Approve twice simultaneously
    // Assert: Only one succeeds
}
```

### Integration Tests

```php
public function test_full_booking_lifecycle()
{
    // 1. User adds items to cart
    // 2. User checks out
    // 3. User uploads proof
    // 4. Admin approves
    // 5. User checks in
    // Assert: All tables consistent at each step
}
```

### Manual Tests

- [ ] Test approval with 1 booking
- [ ] Test approval with 10 bookings
- [ ] Test approval with 100 bookings (load test)
- [ ] Test approval with database slowdown
- [ ] Test approval with network interruption
- [ ] Test concurrent approvals by 2 admins
- [ ] Test rejection flow
- [ ] Test waitlist cancellation
- [ ] Test proof upload flow
- [ ] Test QR verification
- [ ] Test cart item cancellation

---

## 🔍 Monitoring & Alerts

### Daily Checks

```bash
#!/bin/bash
# daily_consistency_check.sh

DATE=$(date +%Y%m%d)
REPORT="/var/log/consistency_check_$DATE.txt"

cd /path/to/project

# Run check
php artisan status:check-consistency --verbose > "$REPORT"

# Check if issues found
if grep -q "issues found" "$REPORT"; then
    # Send alert
    mail -s "STATUS SYNC ISSUES FOUND" admin@example.com < "$REPORT"
    exit 1
fi

exit 0
```

### Real-time Logging

Add to all fixed methods:

```php
Log::channel('status_sync')->info('Operation started', [
    'operation' => 'cart_approval',
    'transaction_id' => $id,
    'user_id' => $request->user()->id,
    'timestamp' => now()
]);

// ... operation ...

Log::channel('status_sync')->info('Operation completed', [
    'operation' => 'cart_approval',
    'transaction_id' => $id,
    'duration_ms' => $duration,
    'bookings_updated' => $count
]);
```

### Error Tracking

```php
catch (\Exception $e) {
    Log::channel('status_sync')->error('Operation failed', [
        'operation' => 'cart_approval',
        'transaction_id' => $id,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);

    // Notify admin immediately for critical operations
    if (in_array($operation, ['cart_approval', 'cart_rejection'])) {
        Mail::to('admin@example.com')->send(new OperationFailedAlert($e));
    }
}
```

---

## 📈 Success Metrics

### Pre-Fix Baseline

Run and record before implementing fixes:

```bash
# Count current inconsistencies
php artisan status:check-consistency --verbose > baseline_report.txt

# Extract counts
grep "Found.*issues" baseline_report.txt
```

### Post-Fix Target

- ✅ Zero new inconsistencies after fixes deployed
- ✅ All existing inconsistencies resolved within 1 week
- ✅ Zero rollback failures in testing
- ✅ 100% of tests passing

### Ongoing Monitoring

- Daily consistency checks show zero issues
- Error logs show zero failed approvals/rejections
- User complaints about mismatched statuses drop to zero

---

## 🆘 Rollback Plan

If issues arise after deployment:

### Immediate Rollback

```bash
# 1. Switch to previous code version
git revert <commit-hash>

# 2. Deploy previous version
# (Use your deployment process)

# 3. Verify system working
php artisan status:check-consistency

# 4. Notify team
```

### Data Recovery

If data inconsistencies occurred:

```bash
# 1. Restore from backup
# (Use your backup process)

# 2. Replay transactions since backup
# (Use transaction logs)

# 3. Verify consistency
php artisan status:check-consistency --fix

# 4. Manual review
# Check specific affected transactions
```

---

## 📞 Contacts & Resources

### Documentation

- **Full Analysis**: `docs/STATUS_SYNCHRONIZATION_ANALYSIS.md`
- **Quick Reference**: `docs/STATUS_SYNC_QUICK_REFERENCE.md`
- **Flow Diagrams**: `docs/STATUS_SYNC_FLOW_DIAGRAM.md`
- **This Action Plan**: `docs/STATUS_SYNC_ACTION_PLAN.md`

### Tools

- **Consistency Checker**: `php artisan status:check-consistency`
- **Code Locations**: See "Review Critical Code" section above

### Support

- **Technical Issues**: [Your tech lead contact]
- **Business Impact**: [Your product owner contact]
- **Emergency**: [Your on-call contact]

---

## ✅ Final Checklist

Before marking as complete:

- [ ] All 7 critical methods have been fixed
- [ ] All fixes wrapped in `DB::transaction()`
- [ ] Pessimistic locking added where needed
- [ ] Error handling implemented
- [ ] Logging added
- [ ] Unit tests written and passing
- [ ] Integration tests written and passing
- [ ] Manual testing completed
- [ ] Consistency checker run on staging (zero issues)
- [ ] Deployed to staging
- [ ] Monitored staging for 3-5 days
- [ ] Consistency checker run on production (before fix)
- [ ] Deployed to production
- [ ] Consistency checker run on production (after fix)
- [ ] Fixed any existing inconsistencies
- [ ] Daily monitoring set up
- [ ] Team trained
- [ ] Documentation updated

---

## 🎯 Summary

**What**: Fix status synchronization across 4 tables
**Why**: Prevent data inconsistencies and lost revenue
**How**: Add database transaction wrappers
**When**: 2-3 weeks (Weeks 1-3: Fix, test, deploy; Week 4: Validate)
**Who**: Backend developer(s) with DBA support
**Risk if not fixed**: HIGH - Ongoing data corruption

---

**Next Step**: Run consistency checker TODAY to assess current damage.

```bash
php artisan status:check-consistency --verbose
```
