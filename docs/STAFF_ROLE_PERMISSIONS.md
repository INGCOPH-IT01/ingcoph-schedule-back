# Staff Role Permissions Update

## Overview
Staff users now have access to all Admin user role features EXCEPT:
- User Management
- Company Settings
- Payment Settings

## Changes Made

### Backend Changes

#### 1. New Middleware Created
**File:** `app/Http/Middleware/AdminOrStaffMiddleware.php`
- Created new middleware to allow both admin and staff access
- Checks if user is either admin or staff
- Returns 403 error if user doesn't have required privileges

#### 2. Middleware Registration
**File:** `bootstrap/app.php`
- Registered new middleware alias `admin.or.staff`
- Available alongside existing `admin` and `staff` middlewares

#### 3. API Routes Updated
**File:** `routes/api.php`

**Routes Now Available to Both Admin and Staff:**
- Sport Management (create, update, delete sports)
- Time-Based Pricing Management
- Court Management (create, update, delete courts)
- Booking Management (pending, stats, approve, reject)
- Cart Transaction Management (all, pending, approve, reject, attendance)
- Cart Item Management (available courts, update, delete)
- Recurring Schedule Management (admin index)
- Holiday Management (all CRUD operations)

**Routes Restricted to Admin Only:**
- User Management (all CRUD operations)
- Company Settings (update, logo, payment QR code)
- Payment Settings (managed through company settings)

#### 4. User Model Enhancement
**File:** `app/Models/User.php`
- Added new method `isAdminOrStaff()` for easy permission checking
- Returns true if user role is either 'admin' or 'staff'

### Frontend Changes

#### 1. Router Guards Updated
**File:** `src/router/index.js`

**Routes Now Accessible to Both Admin and Staff:**
- `/admin` - Admin Dashboard
- `/admin/sports` - Sports Management
- `/admin/holidays` - Holiday Management

**Routes Restricted to Admin Only:**
- `/admin/users` - User Management
- `/admin/company-settings` - Company Settings
- `/admin/payment-settings` - Payment Settings

#### 2. Navigation Menu Updated
**File:** `src/App.vue`

**Menu Items Visible to Both Admin and Staff:**
- Admin Panel
- Sports Management
- Holiday Management
- Courts (already accessible)
- Staff Scanner (already accessible)

**Menu Items Visible to Admin Only:**
- User Management
- Company Settings
- Payment Settings

## Staff User Capabilities

### What Staff Can Do:
1. **Booking Management**
   - View all bookings (pending, approved, rejected)
   - Approve booking requests
   - Reject booking requests with reasons
   - View booking statistics
   - Update attendance status
   - Scan QR codes for check-in

2. **Sports Management**
   - Create new sports
   - Update existing sports
   - Delete sports
   - Manage time-based pricing for sports

3. **Court Management**
   - Create new courts
   - Update court information
   - Delete courts
   - View court details and availability

4. **Transaction Management**
   - View all cart transactions
   - Approve transaction payments
   - Reject transactions
   - Update attendance status

5. **Holiday Management**
   - View all holidays
   - Create new holidays
   - Update holiday information
   - Delete holidays
   - Check if dates are holidays

6. **Recurring Schedule Management**
   - View all recurring schedules
   - Manage recurring bookings

### What Staff Cannot Do:
1. **User Management**
   - Cannot create new users
   - Cannot update user information
   - Cannot delete users
   - Cannot view user statistics
   - Cannot change user roles

2. **Company Settings**
   - Cannot update company name
   - Cannot change company logo
   - Cannot modify business hours
   - Cannot update background colors

3. **Payment Settings**
   - Cannot update payment QR code
   - Cannot modify payment gateway settings
   - Cannot change payment methods

## Testing the Changes

### Backend Testing
1. Create a test staff user
2. Login as staff user and verify API access:
   - ✓ Should access `/api/admin/bookings/pending`
   - ✓ Should access `/api/admin/sports`
   - ✓ Should access `/api/admin/holidays`
   - ✗ Should be denied `/api/admin/users`
   - ✗ Should be denied `/api/admin/company-settings`

### Frontend Testing
1. Login as staff user
2. Verify navigation menu shows:
   - ✓ Admin Panel
   - ✓ Sports Management
   - ✓ Holiday Management
   - ✓ Staff Scanner
   - ✗ User Management (hidden)
   - ✗ Company Settings (hidden)
   - ✗ Payment Settings (hidden)

3. Verify route access:
   - ✓ Can navigate to `/admin`
   - ✓ Can navigate to `/admin/sports`
   - ✓ Can navigate to `/admin/holidays`
   - ✗ Redirected from `/admin/users`
   - ✗ Redirected from `/admin/company-settings`
   - ✗ Redirected from `/admin/payment-settings`

## Security Considerations

1. **Middleware Protection**: All admin routes are protected by either `admin` or `admin.or.staff` middleware
2. **Frontend Guards**: Router guards prevent unauthorized navigation
3. **API Validation**: Backend validates user role on every request
4. **Separation of Concerns**: Admin-only features are clearly separated in code

## Migration Notes

No database migrations are required as this is purely a permission-based update. The staff role already exists in the database.

## Backward Compatibility

All existing functionality remains intact:
- Admin users retain all their original permissions
- Regular users are unaffected
- Staff users gain additional capabilities while maintaining their original QR scanning abilities

## Future Enhancements

Consider implementing:
1. Granular permission system (per-feature permissions)
2. Permission audit logging
3. Role-based dashboard customization
4. Permission presets for quick role configuration
