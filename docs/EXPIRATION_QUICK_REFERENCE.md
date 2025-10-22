# Expiration Logic - Quick Reference Card

## TL;DR - What You Need to Know

### 🎯 Use This Helper Method:
```php
use App\Helpers\BusinessHoursHelper;

// Check if a transaction should be expired
if (BusinessHoursHelper::shouldExpire($transaction)) {
    // Expire it!
}
```

### 📋 Expiration Rules

#### ✅ NEVER Expires:
1. Admin/Staff bookings
2. Bookings with proof of payment uploaded
3. Approved bookings

#### ⏰ Expires after 1 hour:
- User role bookings **without** proof of payment in **pending** status

---

## API Reference

### Method 1: `shouldExpire($transaction, ?Carbon $checkTime = null)`
**Use when:** You need to decide if a transaction should be expired RIGHT NOW

```php
use App\Helpers\BusinessHoursHelper;

$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::shouldExpire($transaction)) {
    $transaction->update(['status' => 'expired']);
}
```

**Parameters:**
- `$transaction` - CartTransaction model (with user relationship loaded)
- `$checkTime` - Optional Carbon time to check against (defaults to now)

**Returns:** `bool` - `true` if should expire, `false` otherwise

---

### Method 2: `isExemptFromExpiration($transaction)`
**Use when:** You only need to know IF a transaction is exempt (not whether to expire it)

```php
use App\Helpers\BusinessHoursHelper;

$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::isExemptFromExpiration($transaction)) {
    echo "This booking will never expire";
}
```

**Parameters:**
- `$transaction` - CartTransaction model (with user relationship loaded)

**Returns:** `bool` - `true` if exempt, `false` if subject to expiration

---

## Common Use Cases

### 1. Expire Old Bookings (Cron Job)
```php
$transactions = CartTransaction::with('user')
    ->where('status', 'pending')
    ->where('payment_status', 'unpaid')
    ->get();

foreach ($transactions as $transaction) {
    if (BusinessHoursHelper::shouldExpire($transaction)) {
        $transaction->update(['status' => 'expired']);
    }
}
```

### 2. Show Expiration Status in UI
```php
$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::isExemptFromExpiration($transaction)) {
    return ['expiration' => 'never'];
} else {
    $timeRemaining = BusinessHoursHelper::getTimeRemainingSeconds(
        Carbon::parse($transaction->created_at)
    );
    return ['expiration' => $timeRemaining];
}
```

### 3. Check Before Allowing Cart Actions
```php
$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::shouldExpire($transaction)) {
    return response()->json(['error' => 'Transaction has expired'], 410);
}

// Proceed with cart action...
```

---

## Important Notes

### ⚠️ Always Load User Relationship
```php
// ✅ Good
$transaction = CartTransaction::with('user')->find($id);
BusinessHoursHelper::shouldExpire($transaction);

// ❌ Bad - may cause errors
$transaction = CartTransaction::find($id);
BusinessHoursHelper::shouldExpire($transaction);
```

### ⚠️ Check Expiration Before Time-Sensitive Operations
```php
// Before processing checkout
if (BusinessHoursHelper::shouldExpire($transaction)) {
    throw new Exception('Transaction expired');
}

// Before approving
if (BusinessHoursHelper::shouldExpire($transaction)) {
    throw new Exception('Cannot approve expired transaction');
}
```

---

## Decision Tree

```
Need to check expiration?
│
├─ Do I need to expire it RIGHT NOW?
│  └─ YES → Use shouldExpire($transaction)
│
└─ Do I just need to know IF it's exempt?
   └─ YES → Use isExemptFromExpiration($transaction)
```

---

## Testing Examples

### Test Exempt Transactions
```php
// Admin transaction
$adminTrans = CartTransaction::factory()->create(['user_id' => $adminUser->id]);
$this->assertFalse(BusinessHoursHelper::shouldExpire($adminTrans));

// Transaction with proof of payment
$paidTrans = CartTransaction::factory()->create(['proof_of_payment' => 'proof.jpg']);
$this->assertFalse(BusinessHoursHelper::shouldExpire($paidTrans));

// Approved transaction
$approvedTrans = CartTransaction::factory()->create(['approval_status' => 'approved']);
$this->assertFalse(BusinessHoursHelper::shouldExpire($approvedTrans));
```

### Test Expiring Transactions
```php
// Old pending user transaction without proof
$oldTrans = CartTransaction::factory()->create([
    'user_id' => $regularUser->id,
    'created_at' => now()->subHours(2),
    'approval_status' => 'pending',
    'proof_of_payment' => null
]);
$this->assertTrue(BusinessHoursHelper::shouldExpire($oldTrans));
```

---

## Where Is This Used?

1. **`app/Console/Commands/CancelExpiredCartItems.php`**
   - Cron job that auto-expires old bookings

2. **`app/Http/Controllers/Api/CartController.php`**
   - `checkAndExpireCartItems()` - Auto-expire on cart load
   - `getExpirationInfo()` - API endpoint for expiration status

3. **`app/Helpers/BusinessHoursHelper.php`**
   - Where the magic happens ✨

---

## Need Help?

### Read the full docs:
- `EXPIRATION_POLICY_UPDATE.md` - Policy details
- `DRY_EXPIRATION_REFACTOR.md` - Implementation details
- `REFACTORING_SUMMARY.md` - Before/after comparison

### Contact:
- Check the git history for this file
- Review test cases in the test suite

---

**Last Updated:** October 22, 2025
**Version:** 2.0 (DRY Refactored)
