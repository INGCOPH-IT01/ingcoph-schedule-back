# Staff Role Permissions - Quick Reference

## Summary of Changes

Staff users now have full Admin access **EXCEPT**:
- ❌ User Management
- ❌ Company Settings
- ❌ Payment Settings

## Files Modified

### Backend (Laravel)
1. ✅ `app/Http/Middleware/AdminOrStaffMiddleware.php` - **NEW** middleware created
2. ✅ `bootstrap/app.php` - Registered new middleware alias
3. ✅ `routes/api.php` - Updated route protections
4. ✅ `app/Models/User.php` - Added `isAdminOrStaff()` helper method

### Frontend (Vue.js)
1. ✅ `src/router/index.js` - Updated route guards
2. ✅ `src/App.vue` - Updated navigation menu visibility
3. ✅ `src/views/Courts.vue` - Updated court management access

## What Staff Can Now Access

### Pages & Features
- ✅ Admin Dashboard (`/admin`)
- ✅ Sports Management (`/admin/sports`)
  - Create, edit, delete sports
  - Manage time-based pricing
- ✅ Court Management (`/courts`)
  - Create, edit, delete courts
  - View court details
- ✅ Holiday Management (`/admin/holidays`)
  - Create, edit, delete holidays
  - Check holiday dates
- ✅ Booking Management
  - View all bookings (pending, approved, rejected)
  - Approve/reject booking requests
  - View booking statistics
  - Update attendance status
- ✅ Transaction Management
  - View all cart transactions
  - Approve/reject payments
  - Update attendance status
- ✅ QR Code Features
  - Scan QR codes for check-in
  - Validate bookings

### API Endpoints Now Available to Staff
```
POST   /api/sports
PUT    /api/sports/{id}
DELETE /api/sports/{id}
GET    /api/sports/{sportId}/time-based-pricing
POST   /api/sports/{sportId}/time-based-pricing
PUT    /api/sports/{sportId}/time-based-pricing/{pricingId}
DELETE /api/sports/{sportId}/time-based-pricing/{pricingId}

POST   /api/courts
PUT    /api/courts/{id}
DELETE /api/courts/{id}

GET    /api/admin/bookings/pending
GET    /api/admin/bookings/stats
POST   /api/admin/bookings/{id}/approve
POST   /api/admin/bookings/{id}/reject

GET    /api/admin/cart-transactions
GET    /api/admin/cart-transactions/pending
POST   /api/admin/cart-transactions/{id}/approve
POST   /api/admin/cart-transactions/{id}/reject
PATCH  /api/admin/cart-transactions/{id}/attendance-status

GET    /api/admin/cart-items/{id}/available-courts
PUT    /api/admin/cart-items/{id}
DELETE /api/admin/cart-items/{id}

GET    /api/admin/recurring-schedules

GET    /api/admin/holidays
GET    /api/admin/holidays/year/{year}
POST   /api/admin/holidays
PUT    /api/admin/holidays/{id}
DELETE /api/admin/holidays/{id}
POST   /api/admin/holidays/check-date
```

## What Staff CANNOT Access

### Pages Blocked
- ❌ User Management (`/admin/users`)
- ❌ Company Settings (`/admin/company-settings`)
- ❌ Payment Settings (`/admin/payment-settings`)

### API Endpoints Blocked
```
GET    /api/admin/users
GET    /api/admin/users/stats
POST   /api/admin/users
GET    /api/admin/users/{id}
PUT    /api/admin/users/{id}
DELETE /api/admin/users/{id}

PUT    /api/admin/company-settings
POST   /api/admin/company-settings
DELETE /api/admin/company-settings/logo
DELETE /api/admin/company-settings/payment-qr-code
```

## Testing Checklist

### As Staff User:
- [ ] Login successfully
- [ ] See "Admin Panel" in navigation menu
- [ ] See "Sports Management" in navigation menu
- [ ] See "Holiday Management" in navigation menu
- [ ] **NOT** see "User Management" in navigation menu
- [ ] **NOT** see "Company Settings" in navigation menu
- [ ] **NOT** see "Payment Settings" in navigation menu
- [ ] Can access `/admin` dashboard
- [ ] Can access `/admin/sports`
- [ ] Can access `/admin/holidays`
- [ ] Can create/edit/delete sports
- [ ] Can create/edit/delete courts
- [ ] Can approve/reject bookings
- [ ] Cannot access `/admin/users` (redirected)
- [ ] Cannot access `/admin/company-settings` (redirected)
- [ ] Cannot access `/admin/payment-settings` (redirected)

## Code Examples

### Backend - Checking Permissions
```php
// Use the new middleware in routes
Route::middleware('admin.or.staff')->group(function () {
    // Routes accessible by both admin and staff
});

// Use in controllers
if ($user->isAdminOrStaff()) {
    // Allow access
}
```

### Frontend - Checking Permissions
```javascript
// In router guards
const user = await authService.getCurrentUser()
if (user && (user.role === 'admin' || user.role === 'staff')) {
  next()
}

// In components
const isAdmin = computed(() => user.value?.role === 'admin')
const isStaff = computed(() => user.value?.role === 'staff')
const canManage = computed(() => isAdmin.value || isStaff.value)
```

## Migration Steps

1. **No Database Changes Required** - Staff role already exists
2. **Deploy Backend** - New middleware will be available
3. **Deploy Frontend** - Navigation and routes updated
4. **Test** - Use checklist above to verify

## Rollback Plan

If issues arise, revert these files:
1. Delete `app/Http/Middleware/AdminOrStaffMiddleware.php`
2. Restore `bootstrap/app.php`
3. Restore `routes/api.php`
4. Restore `app/Models/User.php`
5. Restore `src/router/index.js`
6. Restore `src/App.vue`
7. Restore `src/views/Courts.vue`

## Support

For questions or issues, refer to:
- Full documentation: `docs/STAFF_ROLE_PERMISSIONS.md`
- Backend routes: `routes/api.php`
- Frontend routes: `src/router/index.js`
