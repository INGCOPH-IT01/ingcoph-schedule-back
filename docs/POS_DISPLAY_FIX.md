# POS Products Display Fix

## Issue
POS products were not displaying in the BookingDetailsDialog even though they existed in the database.

## Root Causes

### 1. Missing Eager Loading in CartTransactionController
The `/api/cart-transactions` endpoint was not loading the POS sales relationships, so when the Bookings view fetched transactions, the POS data wasn't included.

### 2. Data Structure Mismatch
The BookingDetailsDialog was only checking for `booking.cart_transaction.pos_sales`, but when viewing a transaction directly from the Bookings list, the data structure is different:
- **Booking object**: Has `cart_transaction.pos_sales`
- **Transaction object**: Has `pos_sales` directly

## Solutions Implemented

### Backend Changes

#### 1. CartTransactionController.php - `index()` method (Line 33-46)
Added POS sales eager loading for user transactions:
```php
$transactions = CartTransaction::with([
    'user',
    'cartItems' => function($query) { ... },
    'cartItems.court.sport',
    'cartItems.sport',
    'cartItems.court.images',
    'cartItems.bookings',
    'bookings',
    'approver',
    'posSales.saleItems.product'  // ← Added
])
```

#### 2. CartTransactionController.php - `all()` method (Line 67-79)
Added POS sales eager loading for admin/staff transactions:
```php
$query = CartTransaction::with([
    'user',
    'cartItems' => function($query) { ... },
    'cartItems.court.sport',
    'cartItems.sport',
    'cartItems.court.images',
    'cartItems.bookings',
    'cartItems.bookingForUser',
    'bookings',
    'posSales.saleItems.product',  // ← Added
    'approver',
    // ... rest of relationships
])
```

### Frontend Changes

#### 1. BookingDetailsDialog.vue - `hasPosProducts` computed (Lines 1774-1786)
Updated to handle both booking and transaction objects:
```javascript
const hasPosProducts = computed(() => {
  // If booking has cart_transaction property, it's a booking object
  if (props.booking?.cart_transaction?.pos_sales) {
    return props.booking.cart_transaction.pos_sales.length > 0 &&
           props.booking.cart_transaction.pos_sales.some(sale => sale.sale_items && sale.sale_items.length > 0)
  }
  // If booking has pos_sales directly, it's a transaction object
  if (props.booking?.pos_sales) {
    return props.booking.pos_sales.length > 0 &&
           props.booking.pos_sales.some(sale => sale.sale_items && sale.sale_items.length > 0)
  }
  return false
})
```

#### 2. BookingDetailsDialog.vue - `posProductsCount` computed (Lines 1789-1798)
Updated to get POS sales from either location:
```javascript
const posProductsCount = computed(() => {
  if (!hasPosProducts.value) return 0

  // Get pos_sales from either cart_transaction or directly from booking/transaction
  const posSales = props.booking?.cart_transaction?.pos_sales || props.booking?.pos_sales || []

  return posSales.reduce((total, sale) => {
    return total + (sale.sale_items?.length || 0)
  }, 0)
})
```

#### 3. BookingDetailsDialog.vue - Template (Line 836)
Updated v-for to handle both data structures:
```vue
<div v-for="(sale, saleIndex) in (booking.cart_transaction?.pos_sales || booking.pos_sales)" :key="sale.id" class="mb-3">
```

## Data Flow

### When Viewing Transaction from Bookings List
1. User clicks "View" on a transaction
2. `Bookings.vue` sets `selectedBooking = transaction`
3. Transaction object has `pos_sales` directly
4. `BookingDetailsDialog` detects `props.booking.pos_sales`
5. POS Products section displays

### When Viewing Booking from API
1. API returns booking with `cart_transaction` relationship
2. Booking object has `cart_transaction.pos_sales`
3. `BookingDetailsDialog` detects `props.booking.cart_transaction.pos_sales`
4. POS Products section displays

## Testing Results

### Database Query
```bash
$ php artisan tinker
>>> $transaction = \App\Models\CartTransaction::with(['posSales.saleItems.product'])->find(1);
>>> $transaction->posSales->count();
=> 1
>>> $transaction->posSales[0]->saleItems->count();
=> 1
>>> $transaction->posSales[0]->saleItems[0]->product->name;
=> "Pocari Sweat 500mL"
```

### API Response Structure
```json
{
  "id": 1,
  "pos_sales": [
    {
      "id": 1,
      "sale_number": "POS-2025-00001",
      "total_amount": "50.00",
      "sale_items": [
        {
          "id": 1,
          "quantity": 1,
          "unit_price": "50.00",
          "product": {
            "id": 1,
            "name": "Pocari Sweat 500mL",
            "price": "50.00"
          }
        }
      ]
    }
  ]
}
```

## Files Modified

### Backend
1. `/app/Http/Controllers/Api/CartTransactionController.php` - Added POS sales eager loading to both index() and all() methods

### Frontend
1. `/src/components/BookingDetailsDialog.vue` - Updated computed properties and template to handle both data structures

## Verification Steps

1. ✅ Create a booking with POS products
2. ✅ View booking from Bookings list - POS products display
3. ✅ View booking from Admin Dashboard - POS products display
4. ✅ View transaction details - POS products display
5. ✅ Product names, quantities, and prices display correctly
6. ✅ Sale totals calculate correctly

## Notes

- The fix handles both data structures (booking with cart_transaction, and transaction directly)
- POS sales are now properly eager loaded in all cart transaction API endpoints
- The frontend gracefully handles missing data with fallbacks
- No breaking changes - existing functionality remains intact
