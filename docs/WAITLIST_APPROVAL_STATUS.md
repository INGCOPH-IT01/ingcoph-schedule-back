# Waitlist Booking Approval Status

## Overview
Waitlist bookings now have their own distinct approval status (`pending_waitlist`) instead of being auto-approved when converted from the waitlist queue. This ensures all bookings, including those from waitlists, go through proper admin approval.

## Problem Statement
Previously, when a user joined a waitlist and was notified that a slot became available, their booking would be **automatically approved** upon checkout. This bypassed the normal approval workflow, which could be problematic for administrative oversight and booking management.

## Solution
Introduced a new approval status: **`pending_waitlist`**

### Approval Status Values
- `pending` - Regular booking awaiting admin approval
- `pending_waitlist` - Waitlist booking awaiting admin approval (converted from waitlist)
- `approved` - Booking approved by admin
- `rejected` - Booking rejected by admin

## Changes Made

### Backend Changes

#### 1. CartController.php
**Location:** `app/Http/Controllers/Api/CartController.php`

**Checkout Process (Lines 755-759):**
```php
// OLD: Auto-approved waitlist bookings
$approvalStatus = $hasWaitlistEntry ? 'approved' : 'pending';
$approvedAt = $hasWaitlistEntry ? now() : null;

// NEW: Waitlist bookings need admin approval
$approvalStatus = $hasWaitlistEntry ? 'pending_waitlist' : 'pending';
$approvedAt = null; // Waitlist bookings need admin approval, not auto-approved
```

**Booking Status (Lines 832-834):**
```php
// OLD: Auto-approved waitlist bookings
$bookingStatus = $matchedWaitlistForBooking ? 'approved' : 'pending';

// NEW: All bookings start as pending
$bookingStatus = 'pending';
```

**Waitlist Detection (Lines 240-252):**
Updated to recognize both `pending` and `pending_waitlist` statuses when determining if a slot should trigger waitlist:
```php
if ($cartTrans &&
    in_array($cartTrans->approval_status, ['pending', 'pending_waitlist']) &&
    $cartTrans->user &&
    $cartTrans->user->role === 'user') {
    // Trigger waitlist
}
```

**Cart Item Deletion (Lines 1260-1267):**
Allow deletion of cart items with either pending status:
```php
if ($cartItem->cartTransaction &&
    !in_array($cartItem->cartTransaction->approval_status, ['pending', 'pending_waitlist'])) {
    // Cannot delete - booking already processed
}
```

### Frontend Changes

#### 2. AdminDashboard.vue
**Location:** `src/views/AdminDashboard.vue`

**Added Helper Functions:**
```javascript
const getApprovalStatusColor = (status) => {
  const colors = {
    'approved': 'success',
    'rejected': 'error',
    'pending': 'warning',
    'pending_waitlist': 'info'  // New status
  }
  return colors[status] || 'warning'
}

const getApprovalStatusText = (status) => {
  const texts = {
    'approved': 'Approved',
    'rejected': 'Rejected',
    'pending': 'Pending',
    'pending_waitlist': 'Pending Waitlist'  // New status
  }
  return texts[status] || 'Pending'
}

const getApprovalStatusIcon = (status) => {
  const icons = {
    'approved': 'mdi-check-circle',
    'rejected': 'mdi-close-circle',
    'pending': 'mdi-clock-alert',
    'pending_waitlist': 'mdi-clock-check'  // New icon
  }
  return icons[status] || 'mdi-clock-alert'
}
```

**Updated Approve/Reject Buttons:**
```vue
<v-btn
  v-if="item.approval_status === 'pending' || item.approval_status === 'pending_waitlist'"
  @click="approveBooking(item.id)"
>
  Approve
</v-btn>
```

**Updated Transaction Count:**
```javascript
const pendingTransactionsCount = computed(() => {
  return pendingBookings.value.filter(t => {
    const status = t.approval_status || 'pending'
    return status === 'pending' || status === 'pending_waitlist'
  }).length
})
```

#### 3. Bookings.vue
**Location:** `src/views/Bookings.vue`

**Added Waitlist Banner:**
```vue
<div v-else-if="booking.approval_status === 'pending_waitlist'"
     class="status-banner-compact waitlist-banner">
  <v-icon size="x-small" class="mr-1">mdi-clock-check</v-icon>
  WAITLIST PENDING
</div>
```

**Added CSS Styling:**
```css
.waitlist-banner {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}
```

**Updated Card Styling:**
```vue
:class="{
  'pending-card': booking.approval_status === 'pending' ||
                   booking.approval_status === 'pending_waitlist',
  'approved-card': booking.approval_status === 'approved',
  'rejected-card': booking.approval_status === 'rejected'
}"
```

**Updated Expiration Checks:**
```javascript
// Check for expired bookings (unpaid for more than 1 hour)
if (transaction.payment_status !== 'paid' &&
    (transaction.approval_status === 'pending' ||
     transaction.approval_status === 'pending_waitlist')) {
  // Check if expired
}
```

#### 4. BookingDetailsDialog.vue
**Location:** `src/components/BookingDetailsDialog.vue`

**Updated Action Buttons:**
```vue
<template v-if="isAdmin &&
                (booking.approval_status === 'pending' ||
                 booking.approval_status === 'pending_waitlist')">
  <!-- Approve/Reject buttons -->
</template>
```

#### 5. BookingsSimple.vue
**Location:** `src/views/BookingsSimple.vue`

**Updated Status Display:**
```vue
:class="{
  'border-warning': transaction.approval_status === 'pending',
  'border-info': transaction.approval_status === 'pending_waitlist',
  'border-success': transaction.approval_status === 'approved',
  'border-error': transaction.approval_status === 'rejected'
}"
```

## User Experience Flow

### For Regular Users:

1. **Join Waitlist**: User joins waitlist when slot is unavailable
2. **Get Notified**: User receives notification when slot becomes available
3. **Checkout**: User completes checkout and uploads proof of payment
4. **Status**: Booking shows as **"WAITLIST PENDING"** (blue badge)
5. **Wait for Approval**: Admin reviews and approves/rejects the booking
6. **Get Approved**: Booking status changes to "APPROVED" (green badge)

### For Admins:

1. **View Dashboard**: See all pending bookings including waitlist bookings
2. **Identify Waitlist**: Waitlist bookings show with **"Pending Waitlist"** status (blue badge with clock-check icon)
3. **Review Details**: Can view booking details including waitlist origin
4. **Approve/Reject**: Same approval workflow as regular bookings
5. **Track Separately**: Can filter and track waitlist bookings separately from regular bookings

## Visual Indicators

### Status Colors:
- **Pending** (Regular): Orange/Warning badge (`mdi-clock-alert`)
- **Pending Waitlist**: Blue/Info badge (`mdi-clock-check`)
- **Approved**: Green/Success badge (`mdi-check-circle`)
- **Rejected**: Red/Error badge (`mdi-close-circle`)

## Database Considerations

The `approval_status` column in the `cart_transactions` table accepts string values. No migration is required as the column is already flexible enough to accommodate the new `pending_waitlist` value.

### Existing Column Definition:
```php
$table->string('approval_status')->default('pending');
```

This string type allows for the new `pending_waitlist` value without schema changes.

## Benefits

1. **Consistent Approval Process**: All bookings go through the same approval workflow
2. **Better Tracking**: Admins can distinguish between regular and waitlist bookings
3. **Audit Trail**: Clear indication of booking origin (waitlist vs direct booking)
4. **Fairness**: Waitlist users don't get automatic approval advantage
5. **Admin Control**: Admins maintain oversight of all bookings regardless of source

## Migration Notes

- **No database migration required** - existing string column supports new value
- **Backward compatible** - existing pending bookings continue to work
- **No data migration needed** - new status only applies to future waitlist conversions

## Testing Checklist

- [x] Waitlist bookings show `pending_waitlist` status after checkout
- [x] Admin can see waitlist bookings with distinct badge
- [x] Admin can approve waitlist bookings
- [x] Admin can reject waitlist bookings
- [x] Pending count includes both pending and pending_waitlist
- [x] Expiration checks work for pending_waitlist bookings
- [x] Cart item deletion works for pending_waitlist bookings
- [x] Waitlist detection includes pending_waitlist status
- [x] Frontend displays correctly in all booking views
- [x] Status colors and icons display correctly

## Future Enhancements

Potential improvements for consideration:
1. Add analytics to track waitlist conversion rates
2. Add separate filtering for waitlist bookings in admin dashboard
3. Add notification preferences for waitlist vs regular bookings
4. Add priority scoring for waitlist based on wait time
