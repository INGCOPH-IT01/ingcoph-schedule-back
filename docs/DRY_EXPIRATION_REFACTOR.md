# DRY Expiration Logic Refactoring

## Overview
Refactored the booking expiration logic to follow the **DRY (Don't Repeat Yourself)** principle by centralizing all expiration-related checks into reusable helper methods in the `BusinessHoursHelper` class.

## New Universal Functions

### 1. `isExemptFromExpiration($transaction)`

**Purpose:** Determines if a cart transaction should be exempt from the 1-hour expiration rule.

**Returns:** `bool` - `true` if exempt, `false` if subject to expiration

**Exemption Rules:**
- ✅ Admin/Staff created bookings
- ✅ Bookings with proof of payment uploaded
- ✅ Bookings that have been approved

**Example Usage:**
```php
use App\Helpers\BusinessHoursHelper;

$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::isExemptFromExpiration($transaction)) {
    // This transaction will never expire
    echo "No expiration for this booking";
} else {
    // This transaction is subject to expiration rules
    echo "This booking may expire";
}
```

### 2. `shouldExpire($transaction, ?Carbon $checkTime = null)`

**Purpose:** Comprehensive check that combines exemption logic with time-based expiration. This is the main method you should use.

**Returns:** `bool` - `true` if the transaction should be expired, `false` otherwise

**Logic:**
1. First checks if transaction is exempt from expiration
2. If not exempt, checks if the expiration time has passed
3. Returns `true` only if both conditions are met (not exempt AND time expired)

**Example Usage:**
```php
use App\Helpers\BusinessHoursHelper;

$transaction = CartTransaction::with('user')->find($id);

if (BusinessHoursHelper::shouldExpire($transaction)) {
    // Transaction should be expired
    $transaction->update(['status' => 'expired']);
    echo "Transaction has been expired";
} else {
    // Transaction should remain active
    echo "Transaction is still valid";
}

// You can also check at a specific time
$specificTime = Carbon::parse('2025-10-23 10:00:00');
if (BusinessHoursHelper::shouldExpire($transaction, $specificTime)) {
    echo "Transaction would be expired at that time";
}
```

## Files Modified

### 1. `app/Helpers/BusinessHoursHelper.php`
**Added two new static methods:**
- `isExemptFromExpiration($transaction)` - Checks exemption rules
- `shouldExpire($transaction, ?Carbon $checkTime = null)` - Comprehensive expiration check

### 2. `app/Console/Commands/CancelExpiredCartItems.php`
**Before:**
```php
foreach ($pendingTransactions as $transaction) {
    // Skip admin/staff bookings
    if ($transaction->user && $transaction->user->isAdmin()) {
        $skippedAdminCount++;
        continue;
    }

    // Skip bookings with proof of payment
    if ($transaction->proof_of_payment) {
        $skippedAdminCount++;
        continue;
    }

    // Skip approved bookings
    if ($transaction->approval_status === 'approved') {
        $skippedAdminCount++;
        continue;
    }

    // Check if transaction has expired
    $createdAt = Carbon::parse($transaction->created_at);
    if (!BusinessHoursHelper::isExpired($createdAt)) {
        $skippedNotExpiredCount++;
        continue;
    }

    // ... expire logic
}
```

**After (DRY):**
```php
foreach ($pendingTransactions as $transaction) {
    // Use universal helper to check if transaction should expire
    if (!BusinessHoursHelper::shouldExpire($transaction)) {
        // Track why it was skipped
        if (BusinessHoursHelper::isExemptFromExpiration($transaction)) {
            $skippedAdminCount++;
        } else {
            $skippedNotExpiredCount++;
        }
        continue;
    }

    // ... expire logic
}
```

### 3. `app/Http/Controllers/Api/CartController.php`

#### Method: `checkAndExpireCartItems()`
**Before:** ~25 lines of repeated logic
**After:** 3 lines using `shouldExpire()`

```php
foreach ($pendingTransactions as $transaction) {
    // Use universal helper to check if transaction should expire
    if (!BusinessHoursHelper::shouldExpire($transaction)) {
        continue;
    }

    // Mark as expired...
}
```

#### Method: `getExpirationInfo()`
**Before:** ~40 lines of repeated conditional checks
**After:** Single exemption check with detailed response

```php
// Check if transaction is exempt from expiration using universal helper
if (BusinessHoursHelper::isExemptFromExpiration($cartTransaction)) {
    // Determine the reason for exemption
    $reason = 'No expiration';
    if ($cartTransaction->user && $cartTransaction->user->isAdmin()) {
        $reason = 'No expiration (Admin)';
    } elseif ($cartTransaction->approval_status === 'approved') {
        $reason = 'No expiration (Approved)';
    } elseif ($cartTransaction->proof_of_payment) {
        $reason = 'No expiration (Proof of payment uploaded)';
    }

    return response()->json([
        'success' => true,
        'has_transaction' => true,
        'is_exempt' => true,
        'is_admin' => $cartTransaction->user && $cartTransaction->user->isAdmin(),
        'is_approved' => $cartTransaction->approval_status === 'approved',
        'has_proof_of_payment' => (bool) $cartTransaction->proof_of_payment,
        'expires_at' => null,
        'time_remaining_seconds' => null,
        'time_remaining_formatted' => $reason,
        'is_expired' => false
    ]);
}
```

## Benefits of This Refactoring

### 1. **Single Source of Truth**
All expiration logic is now in one place (`BusinessHoursHelper`). If business rules change, you only need to update one location.

### 2. **Easier to Test**
You can unit test the helper methods independently without needing to test multiple controller/command implementations.

### 3. **Better Readability**
Code is more readable with descriptive method names like `shouldExpire()` and `isExemptFromExpiration()`.

### 4. **Reduced Code Duplication**
Eliminated ~100+ lines of duplicate code across 3 files.

### 5. **Easier to Extend**
Adding new exemption rules only requires updating the `isExemptFromExpiration()` method.

## Usage Guidelines

### When to use `isExemptFromExpiration()`
Use this when you only need to check **IF** a transaction is exempt, without caring about time:
- Displaying UI badges (e.g., "No Expiration")
- Logging/debugging exemption status
- Conditional logic based on exemption status

### When to use `shouldExpire()`
Use this when you need to **TAKE ACTION** on expired transactions:
- Automated expiration cron jobs
- Manual expiration checks before cart operations
- Determining if a transaction needs to be expired right now

### Always load the user relationship
The helper methods check `$transaction->user->isAdmin()`, so make sure to load the user relationship:

```php
// ✅ Good
$transaction = CartTransaction::with('user')->find($id);
BusinessHoursHelper::shouldExpire($transaction);

// ❌ Bad - may cause N+1 queries or errors
$transaction = CartTransaction::find($id);
BusinessHoursHelper::shouldExpire($transaction);
```

## Testing Recommendations

### Unit Tests for Helper Methods
```php
// Test exemption for admin user
$adminTransaction = CartTransaction::factory()->make(['user' => $adminUser]);
$this->assertTrue(BusinessHoursHelper::isExemptFromExpiration($adminTransaction));

// Test exemption for transaction with proof of payment
$paidTransaction = CartTransaction::factory()->make(['proof_of_payment' => 'proof.jpg']);
$this->assertTrue(BusinessHoursHelper::isExemptFromExpiration($paidTransaction));

// Test exemption for approved transaction
$approvedTransaction = CartTransaction::factory()->make(['approval_status' => 'approved']);
$this->assertTrue(BusinessHoursHelper::isExemptFromExpiration($approvedTransaction));

// Test non-exempt transaction
$regularTransaction = CartTransaction::factory()->make(['approval_status' => 'pending']);
$this->assertFalse(BusinessHoursHelper::isExemptFromExpiration($regularTransaction));
```

### Integration Tests
```php
// Test that exempt transactions don't expire
$adminTransaction = CartTransaction::factory()->create(['user_id' => $adminUser->id]);
$this->assertFalse(BusinessHoursHelper::shouldExpire($adminTransaction));

// Test that non-exempt old transactions expire
$oldTransaction = CartTransaction::factory()->create([
    'created_at' => now()->subHours(2),
    'approval_status' => 'pending',
    'proof_of_payment' => null
]);
$this->assertTrue(BusinessHoursHelper::shouldExpire($oldTransaction));
```

## Future Enhancements

### Potential additions to the helper:
1. `getExemptionReason($transaction)` - Returns a string explaining why transaction is exempt
2. `willExpireAt($transaction)` - Returns Carbon datetime or null if exempt
3. `getExpirationStatus($transaction)` - Returns structured array with all expiration info

## Summary

This refactoring successfully implements DRY principles by:
- ✅ Creating reusable helper methods
- ✅ Eliminating code duplication across 3 files
- ✅ Maintaining backward compatibility
- ✅ Improving code maintainability
- ✅ Making the codebase easier to test and extend

## Date
October 22, 2025
