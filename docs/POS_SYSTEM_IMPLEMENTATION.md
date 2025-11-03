# POS System Implementation Guide

## Overview
A complete Point of Sale (POS) system has been integrated into the booking application, allowing Admin/Staff to manage inventory, record sales, and track transactions alongside court bookings.

## Backend Implementation ✅

### Database Migrations
Created comprehensive database structure:
- **`product_categories`** - Product categorization
- **`products`** - Product inventory with stock tracking
- **`pos_sales`** - Sales transactions
- **`pos_sale_items`** - Line items for each sale
- **`stock_movements`** - Inventory movement tracking
- **`cart_transactions` (updated)** - Added `pos_amount` and `booking_amount` fields to separate POS and booking revenues

### Models
- **Product** - Full inventory management with stock tracking, low stock alerts, profit margin calculations
- **ProductCategory** - Product categorization
- **PosSale** - Sales transactions with automatic sale number generation
- **PosSaleItem** - Individual items in a sale
- **StockMovement** - Audit trail for all inventory changes

### Controllers
- **ProductController** - Complete CRUD for products and categories, stock management
- **PosSaleController** - Sales processing, reporting, statistics

### API Routes
All routes require admin/staff authentication (`/api/pos/*`):
- Product management: CRUD operations, stock adjustments
- Category management: CRUD operations
- Sales: Create, view, update status, delete
- Reports: Statistics, sales reports, product performance

## Frontend Implementation ✅

### Services
- **`productService.js`** - Product and category API interactions
- **`posService.js`** - Sales processing and reporting

### Components
- **`ProductSelector.vue`** - Product selection with search, filtering, and quantity management
- **`PosCart.vue`** - Shopping cart display with totals calculation
- **`PosSaleDialog.vue`** - View sale details, update status, print receipts

### Views
- **`PosSystem.vue`** - Main POS interface for processing sales
  - Product grid with search and filtering
  - Real-time stock display
  - Cart management
  - Payment processing
  - Optional booking linkage

- **`ProductManagement.vue`** - Inventory management
  - Product CRUD operations
  - Stock adjustments
  - Low stock alerts
  - Category management
  - Image upload support

- **`PosReports.vue`** - Sales and inventory reporting
  - Sales statistics dashboard
  - Sales history with filtering
  - Product performance analysis
  - Excel export functionality

### Router
Added protected routes (admin/staff only):
- `/admin/pos` - POS System
- `/admin/products` - Product Management
- `/admin/pos-reports` - POS Reports

### Admin Dashboard Integration ✅
Added POS statistics cards displaying:
- Today's POS sales count
- Today's POS revenue
- Total POS revenue (all time)

## Key Features

### 1. Inventory Management
- Track product stock levels
- Low stock alerts
- Stock movement history
- Cost and profit tracking
- Image support for products
- Category organization
- SKU and barcode support

### 2. Sales Processing
- Quick product selection
- Real-time stock availability
- Discount and tax support
- Multiple payment methods
- Optional booking linkage
- Automatic stock deduction
- Sale number generation
- Receipt printing

### 3. Reporting
- Real-time sales statistics
- Date range filtering
- Product performance metrics
- Profit calculations
- Excel export (sales & products)
- Sales history with search

### 4. Revenue Separation
The system maintains separate tracking for:
- **Booking Amount**: Revenue from court reservations
- **POS Amount**: Revenue from product sales
- **Total Amount**: Combined revenue

This is implemented in the `cart_transactions` table with dedicated columns.

## Integration with Booking System

### Option 1: Point of Sale During Check-in (RECOMMENDED - CURRENT IMPLEMENTATION)
**Workflow:**
1. Customer makes a booking (court reservation)
2. Customer arrives at facility
3. Staff scans QR code or looks up booking
4. Staff uses POS system to add products
5. Link sale to booking using booking ID
6. Process payment

**Advantages:**
- Cleaner separation of concerns
- Better inventory management
- Proper payment flow
- No booking form complexity

### Option 2: Products in Booking Creation (REQUIRES ADDITIONAL IMPLEMENTATION)
To add product selection during booking creation:

1. **Import ProductSelector in GlobalBookingDialog.vue:**
```javascript
import ProductSelector from './ProductSelector.vue'
```

2. **Add to components:**
```javascript
components: {
  CourtImageGallery,
  ProductSelector
}
```

3. **Add ref for selected products:**
```javascript
const selectedProducts = ref([])
```

4. **Add ProductSelector in template (after booking details, before payment):**
```vue
<!-- Product Selection (Optional) -->
<v-row v-if="frequencyType === 'once'">
  <v-col cols="12">
    <v-expansion-panels>
      <v-expansion-panel>
        <v-expansion-panel-title>
          <v-icon class="mr-2">mdi-shopping</v-icon>
          Add Products (Optional)
        </v-expansion-panel-title>
        <v-expansion-panel-text>
          <ProductSelector v-model="selectedProducts" />
        </v-expansion-panel-text>
      </v-expansion-panel>
    </v-expansion-panels>
  </v-col>
</v-row>
```

5. **Update price calculation to include products:**
```javascript
const totalPrice = computed(() => {
  let bookingTotal = 0
  // ... existing booking price calculation

  const posTotal = selectedProducts.value.reduce((sum, item) => {
    return sum + (item.product.price * item.quantity)
  }, 0)

  return bookingTotal + posTotal
})
```

6. **Modify submission to separate amounts:**
```javascript
const handleSubmit = async () => {
  // Calculate separate amounts
  const bookingAmount = calculateBookingAmount()
  const posAmount = calculatePosAmount()

  // Submit with both amounts
  const bookingData = {
    // ... existing fields
    booking_amount: bookingAmount,
    pos_amount: posAmount,
    total_price: bookingAmount + posAmount,
    pos_items: selectedProducts.value.map(item => ({
      product_id: item.product.id,
      quantity: item.quantity,
      unit_price: item.product.price
    }))
  }

  // ... rest of submission logic
}
```

7. **Backend: Update CartController to handle POS items:**
```php
// In checkout method
if ($request->has('pos_items') && count($request->pos_items) > 0) {
    // Create POS sale linked to this transaction
    $posSale = PosSale::create([
        'booking_id' => $transaction->id,
        'user_id' => auth()->id(),
        'total_amount' => $request->pos_amount,
        'status' => 'pending',
    ]);

    foreach ($request->pos_items as $item) {
        PosSaleItem::create([
            'pos_sale_id' => $posSale->id,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['quantity'] * $item['unit_price'],
        ]);
    }
}
```

## Database Setup

Run migrations to create POS tables:
```bash
cd /path/to/ingcoph-schedule-back
php artisan migrate
```

## Initial Data Setup

### 1. Create Product Categories
Use the admin interface or API:
```bash
POST /api/pos/categories
{
  "name": "Beverages",
  "description": "Drinks and refreshments"
}
```

### 2. Add Products
Use Product Management interface (`/admin/products`) or API:
```bash
POST /api/pos/products
{
  "name": "Water Bottle",
  "sku": "WATER-001",
  "category_id": 1,
  "price": 20.00,
  "cost": 10.00,
  "stock_quantity": 100,
  "low_stock_threshold": 20,
  "unit": "bottle",
  "track_inventory": true,
  "is_active": true
}
```

## Usage Guide

### For Admin/Staff: Processing a Sale

1. Navigate to `/admin/pos`
2. Search for or click on products to add to cart
3. Adjust quantities as needed
4. Optional: Link to a booking by selecting from recent bookings
5. Optional: Enter customer name
6. Select payment method
7. Click "Complete Sale"
8. Sale is recorded, stock is updated automatically

### For Admin: Managing Inventory

1. Navigate to `/admin/products`
2. View current stock levels
3. Add/Edit products
4. Adjust stock levels with notes
5. Manage categories
6. View low stock alerts

### For Admin: Viewing Reports

1. Navigate to `/admin/pos-reports`
2. Select date range
3. View sales statistics
4. Export reports to Excel
5. Analyze product performance

## Security

- All POS endpoints require authentication
- Only admin and staff can access POS system
- Stock movements are audited with user tracking
- Sale numbers are unique and auto-generated
- Soft deletes preserve historical data

## Best Practices

1. **Stock Management**
   - Set appropriate low stock thresholds
   - Regular stock audits
   - Track cost prices for profit analysis

2. **Sales Processing**
   - Always verify payment before completing sale
   - Link sales to bookings when applicable
   - Add notes for cash management

3. **Reporting**
   - Regular end-of-day reports
   - Monitor product performance
   - Track profit margins

## Future Enhancements

Potential improvements:
- Barcode scanner integration
- Multi-location inventory
- Purchase order management
- Supplier management
- Loyalty program integration
- Sales targets and commissions
- Advanced analytics dashboard

## Troubleshooting

### Products not showing in POS
- Check if products are marked as active
- Verify stock quantity > 0 (if inventory tracking enabled)
- Check category filters

### Stock not updating
- Verify sale status is "completed"
- Check stock movements table for audit trail
- Ensure product has `track_inventory` enabled

### Permission errors
- Verify user has admin or staff role
- Check authentication token is valid
- Review route middleware configuration

## Support

For issues or questions:
1. Check error logs: `storage/logs/laravel.log`
2. Review database migrations status
3. Verify API endpoints are accessible
4. Check browser console for frontend errors

---

**Implementation Status: COMPLETE**
- ✅ Backend: Migrations, Models, Controllers, Routes
- ✅ Frontend: Services, Components, Views, Router
- ✅ Admin Dashboard: POS Statistics Integration
- ⚠️  Optional: Product selection in booking creation (see Option 2 above)

The POS system is fully functional and ready for use. The optional booking integration can be implemented following the guide in Option 2 if desired.

