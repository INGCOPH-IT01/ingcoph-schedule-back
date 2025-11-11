# Booked Courts Display Fix - BookingDetailsDialog

## Problem
The "Booked Courts & Time Slots" section was not showing in the BookingDetailsDialog when viewing bookings, even though the data existed in the database.

## Root Cause
The template and computed properties were checking for `booking.cart_items`, but the actual data structure from the API has cart items nested in `booking.cart_transaction.cart_items`.

### JSON Structure
```json
{
  "id": 1,
  "cart_transaction_id": 1,
  "cart_transaction": {
    "id": 1,
    "cart_items": [
      {
        "id": 1,
        "court": {...},
        "sport": {...},
        "booking_date": "2025-01-15",
        "start_time": "08:00:00",
        "end_time": "09:00:00"
      }
    ],
    "pos_sales": [...]
  }
}
```

**Note:** `cart_items` is NOT directly on `booking` - it's nested in `booking.cart_transaction.cart_items`

## Solution

Updated all references to check for both data structures:
- `booking.cart_items` (legacy/direct structure)
- `booking.cart_transaction.cart_items` (current nested structure)

### Files Modified

**File**: `/src/components/BookingDetailsDialog.vue`

### Changes Made

#### 1. Template Conditional Checks (Lines 214, 455)

**Admin View (Line 214):**
```vue
<!-- Before -->
<div v-if="isTransaction && showAdminFeatures && booking.cart_items && booking.cart_items.length > 0">

<!-- After -->
<div v-if="isTransaction && showAdminFeatures && (booking.cart_items || booking.cart_transaction?.cart_items) && (booking.cart_items?.length > 0 || booking.cart_transaction?.cart_items?.length > 0)">
```

**User View (Line 455):**
```vue
<!-- Before -->
<div v-if="(!showAdminFeatures || !isTransaction) && booking.cart_items && booking.cart_items.length > 0">

<!-- After -->
<div v-if="(!showAdminFeatures || !isTransaction) && (booking.cart_items || booking.cart_transaction?.cart_items) && (booking.cart_items?.length > 0 || booking.cart_transaction?.cart_items?.length > 0)">
```

#### 2. Item Count in Header (Line 217)
```vue
<!-- Before -->
Booked Courts & Time Slots ({{ booking.cart_items.length }} items)

<!-- After -->
Booked Courts & Time Slots ({{ (booking.cart_items || booking.cart_transaction?.cart_items || []).length }} items)
```

#### 3. Computed Property: `isTransaction` (Lines 1770-1773)
```javascript
// Before
const isTransaction = computed(() => {
  return props.booking?.isTransaction || (props.booking?.cart_items && props.booking.cart_items.length > 0)
})

// After
const isTransaction = computed(() => {
  const cartItems = props.booking?.cart_items || props.booking?.cart_transaction?.cart_items
  return props.booking?.isTransaction || (cartItems && cartItems.length > 0)
})
```

#### 4. Computed Property: `groupedCartItems` (Lines 2992-2994)
```javascript
// Before
const groupedCartItems = computed(() => {
  if (!props.booking || !props.booking.cart_items || props.booking.cart_items.length === 0) {
    return []
  }
  const itemsCopy = [...props.booking.cart_items].sort(...)

// After
const groupedCartItems = computed(() => {
  const cartItems = props.booking?.cart_items || props.booking?.cart_transaction?.cart_items

  if (!props.booking || !cartItems || cartItems.length === 0) {
    return []
  }
  const itemsCopy = [...cartItems].sort(...)
```

#### 5. Computed Property: `adjacentTimeRanges` (Lines 2928-2935)
```javascript
// Before
const adjacentTimeRanges = computed(() => {
  if (!props.booking || !props.booking.cart_items || props.booking.cart_items.length === 0) {
    return []
  }
  const items = props.booking.cart_items

// After
const adjacentTimeRanges = computed(() => {
  const cartItems = props.booking?.cart_items || props.booking?.cart_transaction?.cart_items

  if (!props.booking || !cartItems || cartItems.length === 0) {
    return []
  }
  const items = cartItems
```

#### 6. Admin Notes Display (Lines 746-747)
```vue
<!-- Before -->
<div v-else-if="booking.cart_items && booking.cart_items.length > 0 && booking.cart_items[0].admin_notes">
  {{ booking.cart_items[0].admin_notes }}
</div>

<!-- After -->
<div v-else-if="(booking.cart_items || booking.cart_transaction?.cart_items) && (booking.cart_items?.[0]?.admin_notes || booking.cart_transaction?.cart_items?.[0]?.admin_notes)">
  {{ booking.cart_items?.[0]?.admin_notes || booking.cart_transaction?.cart_items?.[0]?.admin_notes }}
</div>
```

#### 7. Client Notes Section Visibility (Line 757)
```vue
<!-- Before -->
<div v-if="showAdminFeatures || booking.notes || (booking.cart_items && booking.cart_items.length > 0 && booking.cart_items[0].notes) || editingNotes">

<!-- After -->
<div v-if="showAdminFeatures || booking.notes || ((booking.cart_items || booking.cart_transaction?.cart_items) && (booking.cart_items?.[0]?.notes || booking.cart_transaction?.cart_items?.[0]?.notes)) || editingNotes">
```

#### 8. Client Notes Display (Lines 813-814)
```vue
<!-- Before -->
<div v-else-if="booking.cart_items && booking.cart_items.length > 0 && booking.cart_items[0].notes">
  {{ booking.cart_items[0].notes }}
</div>

<!-- After -->
<div v-else-if="(booking.cart_items || booking.cart_transaction?.cart_items) && (booking.cart_items?.[0]?.notes || booking.cart_transaction?.cart_items?.[0]?.notes)">
  {{ booking.cart_items?.[0]?.notes || booking.cart_transaction?.cart_items?.[0]?.notes }}
</div>
```

## What This Fixes

✅ "Booked Courts & Time Slots" section now displays for both admin and regular users
✅ Cart items are properly retrieved whether they're in `booking.cart_items` or `booking.cart_transaction.cart_items`
✅ Court grouping and time slot display works correctly
✅ Admin notes and client notes from cart items display properly
✅ Transaction detection works correctly
✅ Adjacent time ranges calculation works properly

## Testing Verification

### Backend Check
```bash
php artisan tinker --execute="
\$booking = \App\Models\Booking::with(['cartTransaction.cartItems.court'])->find(1);
echo 'Cart items count: ' . \$booking->cartTransaction->cartItems->count();
"
```
Output: `Cart items count: 2` ✓

### JSON Structure Check
The API returns cart items in `booking.cart_transaction.cart_items`, NOT directly in `booking.cart_items`.

## User Experience

When viewing Booking ID#1 (or any transaction-based booking):
1. ✅ "Transaction Details" section shows
2. ✅ "Booked Courts & Time Slots" section appears with court information
3. ✅ Each court shows its time slots grouped together
4. ✅ Time slots show date, time range, price, and number of players
5. ✅ POS Products section shows (if products were added)

## Backward Compatibility

The fix maintains backward compatibility by checking for BOTH structures:
- `booking.cart_items` - if data is structured this way
- `booking.cart_transaction.cart_items` - current API structure

This ensures the component works regardless of how the data is structured.
