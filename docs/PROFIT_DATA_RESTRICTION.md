# Profit Data Restriction Implementation

## Overview
All profit-related data has been restricted to Admin role only. This includes both backend API responses and frontend display/export functionality.

## Changes Made

### Backend Changes

#### 1. `app/Models/PosSale.php`
- **Removed automatic profit appending**: Commented out `protected $appends = ['profit']`
- **Reason**: Profit should only be calculated and returned for admin users

#### 2. `app/Models/PosSaleItem.php`
- **Removed automatic item_profit appending**: Commented out `protected $appends = ['item_profit']`
- **Added hidden attribute**: `protected $hidden = ['unit_cost']` to hide cost data by default
- **Reason**: Item-level profit and cost should only be visible to admins

#### 3. `app/Http/Controllers/PosSaleController.php`

##### a. `show()` method (Lines 69-94)
Added role-based conditional profit data inclusion:
```php
// Only admins can see profit data
if (auth()->check() && auth()->user()->role === 'admin') {
    // Append profit to the sale
    $sale->append('profit');

    // Append item_profit and make unit_cost visible for each sale item
    $sale->saleItems->each(function ($item) {
        $item->append('item_profit');
        $item->makeVisible('unit_cost');
    });
}
```

##### b. `statistics()` method (Lines 326-337)
Already implemented - only calculates and returns `total_profit` for admin users

##### c. `salesReport()` method (Lines 360-363)
Already implemented - hides profit attribute for non-admin users:
```php
if (!auth()->check() || auth()->user()->role !== 'admin') {
    $sales->makeHidden('profit');
}
```

##### d. `productSalesSummary()` method (Lines 393-396)
Already implemented - only includes profit calculation in SQL query for admins

### Frontend Changes

#### 1. `src/components/PosSaleDialog.vue`
- **Added `isAdmin` computed property**: Checks user role from localStorage
- **Added conditional profit display**: Profit section only shown when `isAdmin && sale.profit !== undefined`
- **Location**: Line 147-153 in template

#### 2. `src/views/PosReports.vue`
Already properly implemented with the following restrictions:
- **Total Profit card**: Only shown to admins (line 46)
- **Sales table**: Profit column only included for admins (lines 275-276)
- **Product table**: Profit column only included for admins (lines 292-293)
- **Excel exports**: Profit data only exported for admins (multiple locations)

## Security Layers

### Layer 1: Database Model Level
- Profit and cost attributes not automatically appended to JSON responses
- `unit_cost` hidden by default in PosSaleItem model

### Layer 2: Controller Level
- All endpoints check user role before including profit data
- `show()`, `statistics()`, `salesReport()`, and `productSalesSummary()` methods enforce role-based restrictions

### Layer 3: Frontend Display Level
- UI components check `isAdmin` before rendering profit data
- Table headers dynamically adjust based on user role
- Export functionality excludes profit data for non-admins

## API Endpoints Affected

1. `GET /api/pos/sales/{id}` - Single sale details
2. `GET /api/pos/statistics` - POS statistics summary
3. `GET /api/pos/sales-report` - Sales report data
4. `GET /api/pos/product-sales-summary` - Product performance data

## Testing

### As Admin User
- ✅ Can see Total Profit card in dashboard
- ✅ Can see Profit column in sales table
- ✅ Can see Profit column in product performance table
- ✅ Can see profit in sale details dialog
- ✅ Excel exports include profit data

### As Staff/Cashier User
- ✅ Cannot see Total Profit card
- ✅ Cannot see Profit column in tables
- ✅ Cannot see profit in sale details dialog
- ✅ Excel exports do NOT include profit data

## Date
November 8, 2025
