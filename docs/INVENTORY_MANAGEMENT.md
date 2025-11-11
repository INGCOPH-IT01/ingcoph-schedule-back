# Inventory Management Module

## Overview
The Inventory Management module provides a comprehensive system for managing product receiving reports, tracking inventory changes, and maintaining accurate stock levels. This module is accessible by Admin, Staff, and Cashier roles.

## Features

### 1. Receiving Reports
- Create draft receiving reports with multiple product items
- Submit reports for confirmation
- Confirm reports to automatically adjust product stock
- Cancel or delete reports (based on status)
- View detailed report information
- Track report history and audit trail

### 2. Stock Management
- Automatic stock adjustment upon report confirmation
- Automatic cost price updates based on receiving reports
- Track quantity received and total costs
- View stock movement history linked to receiving reports

### 3. Export Functionality
- Export receiving reports to Excel
- Detailed view with all items and costs
- Filter exports by status and date range
- Summary and detailed data in one file

### 4. Reporting & Statistics
- View total reports created
- Track pending confirmations
- Monitor confirmed reports
- Calculate total items received
- Calculate total inventory value

## Backend Implementation

### Database Schema

#### `receiving_reports` Table
- `id` - Primary key
- `report_number` - Unique report number (format: RR-YYYYMMDD-0001)
- `user_id` - User who created the report
- `notes` - Optional notes for the report
- `status` - Enum: draft, pending, confirmed, cancelled
- `confirmed_at` - Timestamp when confirmed
- `confirmed_by` - User who confirmed the report
- `created_at`, `updated_at` - Timestamps

#### `receiving_report_items` Table
- `id` - Primary key
- `receiving_report_id` - Foreign key to receiving_reports
- `product_id` - Foreign key to products
- `quantity` - Quantity received
- `unit_cost` - Cost per unit
- `total_cost` - Automatically calculated (quantity × unit_cost)
- `notes` - Optional notes for the item
- `created_at`, `updated_at` - Timestamps

### Models

#### `ReceivingReport` Model
**Location:** `app/Models/ReceivingReport.php`

**Key Methods:**
- `generateReportNumber()` - Generates unique report numbers
- `confirm($userId)` - Confirms report and adjusts stock
- `cancel()` - Cancels the report
- `getTotalItemsAttribute()` - Calculates total items
- `getTotalCostAttribute()` - Calculates total cost

**Relationships:**
- `user()` - Belongs to User (creator)
- `confirmedBy()` - Belongs to User (confirmer)
- `items()` - Has many ReceivingReportItem

**Scopes:**
- `draft()` - Filter draft reports
- `pending()` - Filter pending reports
- `confirmed()` - Filter confirmed reports

#### `ReceivingReportItem` Model
**Location:** `app/Models/ReceivingReportItem.php`

**Key Features:**
- Auto-calculates total_cost before saving
- Links to Product and ReceivingReport

**Relationships:**
- `receivingReport()` - Belongs to ReceivingReport
- `product()` - Belongs to Product

### Controller

#### `InventoryController`
**Location:** `app/Http/Controllers/InventoryController.php`

**Endpoints:**

1. **GET** `/api/inventory/receiving-reports`
   - List all receiving reports with filters
   - Supports pagination, search, status filter, date range
   - Returns reports with user, confirmer, and item details

2. **GET** `/api/inventory/receiving-reports/{id}`
   - Get single report details
   - Includes all items and related data

3. **POST** `/api/inventory/receiving-reports`
   - Create new receiving report (draft status)
   - Validates items and product existence
   - Auto-generates report number

4. **PUT** `/api/inventory/receiving-reports/{id}`
   - Update existing report (draft or pending only)
   - Can update notes and items

5. **POST** `/api/inventory/receiving-reports/{id}/submit`
   - Submit draft report for confirmation
   - Changes status to pending

6. **POST** `/api/inventory/receiving-reports/{id}/confirm`
   - Confirm pending report
   - Adjusts product stock quantities
   - Updates product costs
   - Creates stock movement records
   - Status changes to confirmed

7. **POST** `/api/inventory/receiving-reports/{id}/cancel`
   - Cancel draft or pending report
   - Status changes to cancelled

8. **DELETE** `/api/inventory/receiving-reports/{id}`
   - Delete draft or cancelled report
   - Removes report and all items

9. **GET** `/api/inventory/receiving-reports-export`
   - Export reports to Excel
   - Supports same filters as index
   - Returns .xlsx file

10. **GET** `/api/inventory/statistics`
    - Get inventory statistics
    - Returns counts and totals

### Excel Export

#### `ReceivingReportsExport` Class
**Location:** `app/Exports/ReceivingReportsExport.php`

**Features:**
- Exports detailed report data to Excel
- One row per item with report details
- Includes all relevant information
- Auto-sized columns
- Bold header row
- Professional formatting

**Columns:**
- Report Number
- Date Created
- Created By
- Status
- Product SKU
- Product Name
- Quantity
- Unit Cost
- Total Cost
- Item Notes
- Report Notes
- Confirmed At
- Confirmed By

### API Routes

All routes are protected by `pos.access` middleware, allowing Admin, Staff, and Cashier roles.

**Location:** `routes/api.php`

```php
Route::middleware('pos.access')->group(function () {
    Route::get('/inventory/receiving-reports', [InventoryController::class, 'index']);
    Route::get('/inventory/receiving-reports/{id}', [InventoryController::class, 'show']);
    Route::post('/inventory/receiving-reports', [InventoryController::class, 'store']);
    Route::put('/inventory/receiving-reports/{id}', [InventoryController::class, 'update']);
    Route::post('/inventory/receiving-reports/{id}/submit', [InventoryController::class, 'submit']);
    Route::post('/inventory/receiving-reports/{id}/confirm', [InventoryController::class, 'confirm']);
    Route::post('/inventory/receiving-reports/{id}/cancel', [InventoryController::class, 'cancel']);
    Route::delete('/inventory/receiving-reports/{id}', [InventoryController::class, 'destroy']);
    Route::get('/inventory/receiving-reports-export', [InventoryController::class, 'export']);
    Route::get('/inventory/statistics', [InventoryController::class, 'statistics']);
});
```

## Frontend Implementation

### Service Layer

#### `inventoryService.js`
**Location:** `src/services/inventoryService.js`

Provides API integration methods for all inventory operations:
- CRUD operations for receiving reports
- Submit, confirm, cancel actions
- Excel export with blob handling
- Statistics retrieval

### Views

#### `InventoryManagement.vue`
**Location:** `src/views/InventoryManagement.vue`

**Features:**
- Statistics dashboard with cards
- Advanced filtering (status, date range, search)
- Data table with pagination and sorting
- Action menu for each report (view, edit, submit, confirm, cancel, delete)
- Excel export button
- Responsive design

**Components Used:**
- `ReceivingReportDialog` - Create/Edit dialog
- `ReceivingReportViewDialog` - View details dialog
- Statistics cards
- Filters and search
- Confirmation dialogs
- Snackbar notifications

### Components

#### `ReceivingReportDialog.vue`
**Location:** `src/components/ReceivingReportDialog.vue`

**Purpose:** Create and edit receiving reports

**Features:**
- Form validation
- Product autocomplete with search
- Dynamic item management (add/remove rows)
- Real-time cost calculation
- Notes field for report and items
- Summary section showing totals
- Responsive table layout

**Props:**
- `value` - Dialog visibility (v-model)
- `report` - Report object for editing
- `mode` - 'create' or 'edit'

**Events:**
- `saved` - Emitted when report is saved
- `closed` - Emitted when dialog closes

#### `ReceivingReportViewDialog.vue`
**Location:** `src/components/ReceivingReportViewDialog.vue`

**Purpose:** View receiving report details (read-only)

**Features:**
- Report header information
- Status badge
- Creator and confirmer details
- Items table with totals
- Notes display
- Formatted dates and currency

**Props:**
- `value` - Dialog visibility (v-model)
- `report` - Report object to view

**Events:**
- `closed` - Emitted when dialog closes

### Router Configuration

**Location:** `src/router/index.js`

```javascript
{
  path: '/admin/inventory',
  name: 'InventoryManagement',
  component: () => import('../views/InventoryManagement.vue'),
  beforeEnter: async (to, from, next) => {
    // Accessible by admin, staff, and cashier
    const user = await authService.getCurrentUser()
    if (user && (user.role === 'admin' || user.role === 'staff' || user.role === 'cashier')) {
      next()
    } else {
      next('/')
    }
  }
}
```

### Navigation Menu

**Location:** `src/App.vue`

Added menu item in the POS section:
```vue
<v-list-item
  v-if="isAuthenticated && (isAdmin || isStaff || isCashier)"
  prepend-icon="mdi-warehouse"
  title="Inventory Management"
  value="inventory-management"
  :to="{ name: 'InventoryManagement' }"
  class="excel-nav-item"
></v-list-item>
```

## User Workflow

### Creating a Receiving Report

1. Navigate to **Inventory Management** from the menu
2. Click **"New Receiving Report"** button
3. Add notes (optional)
4. Click **"Add Item"** to add products
5. For each item:
   - Select product from dropdown
   - Enter quantity received
   - Enter unit cost
   - Add notes (optional)
6. Review totals at the bottom
7. Click **"Create"** to save as draft

### Submitting for Confirmation

1. Find the draft report in the list
2. Click the actions menu (⋮)
3. Select **"Submit for Confirmation"**
4. Confirm the action
5. Report status changes to "Pending"

### Confirming a Report

1. Find the pending report in the list
2. Click the actions menu (⋮)
3. Select **"Confirm & Adjust Stock"**
4. Read the warning message carefully
5. Confirm the action
6. System will:
   - Update product stock quantities
   - Update product costs
   - Create stock movement records
   - Change report status to "Confirmed"

### Viewing Reports

1. Click the actions menu (⋮) on any report
2. Select **"View"**
3. View all details including:
   - Report information
   - Status and dates
   - All items with costs
   - Notes and totals

### Exporting to Excel

1. Apply any desired filters (status, date range)
2. Click **"Export to Excel"** button
3. File will download automatically
4. Open in Excel/Sheets to view detailed data

## Status Workflow

```
Draft → Pending → Confirmed
  ↓       ↓
Cancelled
  ↓
Deleted (only draft/cancelled)
```

### Status Rules

- **Draft**: Can be edited, submitted, cancelled, or deleted
- **Pending**: Can be edited, confirmed, or cancelled
- **Confirmed**: Cannot be modified or deleted (permanent record)
- **Cancelled**: Can be deleted

## Stock Movement Integration

When a receiving report is confirmed, the system:

1. Creates stock movements for each item with type "receiving"
2. Links movements to the report via `reference_number`
3. Updates product `stock_quantity`
4. Updates product `cost` with the new unit cost
5. Records the user who confirmed the report

This ensures full traceability of inventory changes.

## Permissions

### Admin
- Full access to all features
- Can create, edit, submit, confirm, cancel, and delete reports
- Can export data

### Staff
- Full access to all features
- Can create, edit, submit, confirm, cancel, and delete reports
- Can export data

### Cashier
- Full access to all features
- Can create, edit, submit, confirm, cancel, and delete reports
- Can export data

## Dependencies

### Backend
- Laravel 12.x
- maatwebsite/excel 3.x (for Excel export)

### Frontend
- Vue 3
- Vuetify 3
- Vue Router
- Axios

## API Response Examples

### List Reports
```json
{
  "data": [
    {
      "id": 1,
      "report_number": "RR-20251108-0001",
      "user_id": 1,
      "notes": "Monthly stock replenishment",
      "status": "confirmed",
      "confirmed_at": "2025-11-08T10:30:00.000000Z",
      "confirmed_by": 1,
      "created_at": "2025-11-08T09:00:00.000000Z",
      "updated_at": "2025-11-08T10:30:00.000000Z",
      "items_count": 5,
      "total_items": 150,
      "total_cost": 15000.00,
      "user": {
        "id": 1,
        "name": "John Doe"
      },
      "confirmed_by_user": {
        "id": 1,
        "name": "John Doe"
      }
    }
  ],
  "current_page": 1,
  "per_page": 15,
  "total": 1
}
```

### Create Report
```json
{
  "notes": "Weekly restocking",
  "items": [
    {
      "product_id": 1,
      "quantity": 50,
      "unit_cost": 25.00,
      "notes": "Bulk order discount applied"
    }
  ]
}
```

## Testing

### Manual Testing Checklist

1. ✅ Create a draft receiving report
2. ✅ Add multiple items to report
3. ✅ Edit a draft report
4. ✅ Submit report for confirmation
5. ✅ Confirm a pending report
6. ✅ Verify stock quantities updated
7. ✅ Verify product costs updated
8. ✅ View report details
9. ✅ Cancel a report
10. ✅ Delete a cancelled report
11. ✅ Export to Excel
12. ✅ Test filters and search
13. ✅ Test pagination
14. ✅ Test with different roles (Admin, Staff, Cashier)

## Future Enhancements

Potential improvements for future versions:

1. **Supplier Management**
   - Link receiving reports to suppliers
   - Track supplier performance

2. **Return Reports**
   - Create return reports for damaged goods
   - Adjust stock downwards

3. **Approval Workflow**
   - Multi-level approval process
   - Email notifications

4. **Barcode Scanning**
   - Scan products directly into reports
   - Mobile-friendly interface

5. **Inventory Alerts**
   - Low stock notifications
   - Overstock warnings

6. **Batch/Lot Tracking**
   - Track products by batch number
   - Expiration date management

7. **Cost Analysis**
   - Price trends over time
   - Cost variance reports

## Troubleshooting

### Common Issues

**Issue:** Excel export not working
- **Solution:** Ensure maatwebsite/excel is installed: `composer require maatwebsite/excel`

**Issue:** Stock not updating after confirmation
- **Solution:** Check that products have `track_inventory` enabled

**Issue:** Cannot delete report
- **Solution:** Only draft and cancelled reports can be deleted

**Issue:** Permissions error
- **Solution:** Verify user has admin, staff, or cashier role

## Support

For issues or questions:
1. Check this documentation
2. Review the code comments
3. Check Laravel and Vue documentation
4. Contact the development team

## Changelog

### Version 1.0.0 (2025-11-08)
- Initial release
- Create, edit, submit, confirm, cancel receiving reports
- Stock adjustment automation
- Excel export functionality
- Statistics dashboard
- Role-based access control
