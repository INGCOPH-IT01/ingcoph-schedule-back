# Waitlist Cart Items Fix

## Problem

When users were added to a waitlist, **only** a `booking_waitlists` entry was created. No `cart_transactions` or `cart_items` were saved.

This meant:
- ❌ Users had to manually add to cart again after being notified
- ❌ No booking details were saved for the waitlisted user
- ❌ Poor user experience - extra steps required

## Solution

Modified the waitlist creation logic to **also create cart items** when users are waitlisted.

## What Happens Now

### When User is Waitlisted:

1. ✅ **Cart Transaction created** (if not exists)
2. ✅ **Cart Item created** with the requested court/time slot
3. ✅ **Waitlist Entry created** with position in queue
4. ✅ **Items linked together** - waitlist references the pending booking

### Database Entries Created:

```sql
-- Cart Transaction
INSERT INTO cart_transactions (
  user_id,
  total_price,
  status='pending',
  approval_status='pending'
)

-- Cart Item (NEW!)
INSERT INTO cart_items (
  user_id,
  cart_transaction_id,
  court_id,
  sport_id,
  booking_date,
  start_time,
  end_time,
  price,
  status='pending'
)

-- Waitlist Entry
INSERT INTO booking_waitlists (
  user_id,
  pending_cart_transaction_id,  -- References the blocking booking
  court_id,
  sport_id,
  start_time,
  end_time,
  position,
  status='pending'
)
```

## User Flow

### Before Fix:

```
1. User tries to book slot
2. Slot is pending (occupied by someone else)
3. User gets waitlisted
   → Only waitlist entry created ❌
4. User receives email notification
5. User must manually add to cart again ❌
6. User checks out
7. Booking created
```

### After Fix:

```
1. User tries to book slot
2. Slot is pending (occupied by someone else)
3. User gets waitlisted
   → Waitlist entry created ✅
   → Cart item created ✅
4. User receives email notification
5. User already has items in cart ✅
6. User checks out directly
7. Booking created
```

## Benefits

✅ **Better UX** - Users don't need to re-add to cart
✅ **Saved Details** - All booking information preserved
✅ **Faster Checkout** - One-click checkout after notification
✅ **Cart Visibility** - Users can see their waitlisted bookings in cart

## API Response

When a user is waitlisted, the response now includes:

```json
{
  "message": "This time slot is currently pending approval for another user. You have been added to the waitlist.",
  "waitlisted": true,
  "waitlist_entry": {
    "id": 1,
    "user_id": 2,
    "court_id": 1,
    "sport_id": 1,
    "start_time": "2025-10-24 10:00:00",
    "end_time": "2025-10-24 11:00:00",
    "price": 500.00,
    "position": 1,
    "status": "pending",
    "court": { ... },
    "sport": { ... }
  },
  "cart_item": {
    "id": 5,
    "user_id": 2,
    "cart_transaction_id": 3,
    "court_id": 1,
    "booking_date": "2025-10-24",
    "start_time": "10:00:00",
    "end_time": "11:00:00",
    "price": 500.00,
    "status": "pending",
    "court": { ... },
    "sport": { ... }
  },
  "cart_transaction": {
    "id": 3,
    "user_id": 2,
    "total_price": 500.00,
    "status": "pending",
    "approval_status": "pending",
    "cart_items": [ ... ]
  },
  "position": 1
}
```

## Frontend Integration

The frontend can now:

1. **Show waitlisted items in cart** - Display with "Waitlisted" badge
2. **Track cart count** - Include waitlisted items in cart count
3. **Quick checkout** - When notified, user can checkout directly from cart
4. **Visual distinction** - Show waitlist position and status

### Suggested Frontend Changes:

```vue
<!-- In BookingCart.vue -->
<v-chip
  v-if="item.is_waitlisted"
  color="orange"
  small
>
  Waitlisted - Position #{{ item.waitlist_position }}
</v-chip>
```

## Edge Cases Handled

### 1. Multiple Waitlisted Slots
- Each slot gets its own cart item
- Total price accumulates correctly
- Each has its own waitlist entry

### 2. Mixed Cart (Regular + Waitlisted)
- Same cart can have both regular and waitlisted items
- Checkout processes them correctly
- Waitlist items convert to bookings when slot becomes available

### 3. Waitlist Expiration
- Cart items remain until manually removed
- Expired waitlist entries don't auto-remove cart items
- User can still see what they tried to book

## Testing

### Test Case 1: Basic Waitlist with Cart Items

```bash
# 1. Admin books a slot
POST /api/cart (as admin)
{
  "items": [{
    "court_id": 1,
    "booking_date": "2025-10-24",
    "start_time": "10:00",
    "end_time": "11:00",
    "price": 500
  }]
}
POST /api/cart/checkout (as admin)

# 2. Regular user tries to book same slot
POST /api/cart (as regular user)
{
  "items": [{
    "court_id": 1,
    "booking_date": "2025-10-24",
    "start_time": "10:00",
    "end_time": "11:00",
    "price": 500
  }]
}

# Expected Response:
# {
#   "waitlisted": true,
#   "cart_item": { ... },
#   "cart_transaction": { ... },
#   "waitlist_entry": { ... }
# }

# 3. Verify database
# - cart_items table should have entry for regular user
# - cart_transactions table should have entry for regular user
# - booking_waitlists table should have entry
```

### Test Case 2: Check Cart After Waitlist

```bash
# After being waitlisted, check cart
GET /api/cart (as regular user)

# Expected:
# - Cart should show the waitlisted item
# - Total price should include waitlisted item
# - Cart count should be > 0
```

### Test Case 3: Checkout After Notification

```bash
# 1. Admin rejects the first booking
POST /api/cart-transactions/{admin_transaction_id}/reject

# 2. Regular user (waitlisted) should receive email

# 3. Regular user checks out directly
POST /api/cart/checkout (as regular user)
{
  "payment_method": "gcash",
  "proof_of_payment": [...]
}

# Expected:
# - Checkout succeeds
# - Booking created
# - Waitlist marked as 'converted'
# - Cart items marked as 'completed'
```

## Files Modified

- `app/Http/Controllers/Api/CartController.php` - Lines 250-307

## Related Documentation

- `docs/WAITLIST_FEATURE.md` - Original waitlist implementation
- `docs/WAITLIST_BUG_FIX.md` - Waitlist trigger fix
- `docs/WAITLIST_CHECKOUT_FIX.md` - Checkout auto-approval

## Migration Required?

❌ No database migration required - uses existing tables

## Breaking Changes?

⚠️ **Frontend may need updates** to handle new response structure:
- `cart_item` is now included in waitlist response
- `cart_transaction` is now included in waitlist response
- Frontend can now show waitlisted items in cart

## Rollback Plan

If issues occur, revert to previous behavior:

```php
// Remove cart item creation (lines 254-274)
// Remove cart_transaction update (lines 271-274)
// Keep only waitlist creation (lines 276-295)
```

## Summary

**Change**: Waitlist now creates cart items in addition to waitlist entries
**Impact**: Improved UX - users don't need to re-add to cart after notification
**Files**: `CartController.php` (waitlist creation logic)
**Testing**: Verify cart items are created when waitlisted
