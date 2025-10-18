# Contact Information Feature

## Overview
Added Contact Information fields (Viber, Mobile, Email) to the Company Settings module. This allows administrators to manage and display company contact information that can be used throughout the application.

## Implementation Details

### Backend Changes

#### 1. CompanySettingController.php
**File:** `/app/Http/Controllers/Api/CompanySettingController.php`

**Changes Made:**
- Added default values for contact information in the `index()` method:
  - `contact_viber`
  - `contact_mobile`
  - `contact_email`

- Added validation rules in the `update()` method:
  ```php
  'contact_viber' => 'nullable|string|max:100',
  'contact_mobile' => 'nullable|string|max:50',
  'contact_email' => 'nullable|email|max:255',
  ```

- Added save logic for contact information:
  ```php
  if ($request->has('contact_viber')) {
      CompanySetting::set('contact_viber', $request->contact_viber);
  }
  if ($request->has('contact_mobile')) {
      CompanySetting::set('contact_mobile', $request->contact_mobile);
  }
  if ($request->has('contact_email')) {
      CompanySetting::set('contact_email', $request->contact_email);
  }
  ```

- Added contact fields to response data in `update()` method

### Frontend Changes

#### 2. SystemSettings.vue
**File:** `/src/views/SystemSettings.vue`

**Changes Made:**
- Added reactive state variables for contact information:
  - `contactViber`
  - `contactMobile`
  - `contactEmail`
  - Original values for reset functionality

- Updated `loadSettings()` function to load contact information from API

- Updated `saveCompanySettings()` function to include contact fields in FormData

- Updated `resetCompanyForm()` function to reset contact information

- Added UI components in the Company Settings tab:
  - **Viber Number** field with `mdi-message-processing` icon
  - **Mobile Number** field with `mdi-cellphone` icon
  - **Email Address** field with `mdi-email` icon and email validation

- Added email validation rule:
  ```javascript
  email: value => !value || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) || 'Invalid email address'
  ```

## Features

### Contact Information Fields
1. **Viber Number**
   - Optional text field
   - Max length: 100 characters
   - Hint: "Contact number for Viber"

2. **Mobile Number**
   - Optional text field
   - Max length: 50 characters
   - Hint: "Primary mobile contact number"

3. **Email Address**
   - Optional email field
   - Email format validation (frontend & backend)
   - Max length: 255 characters
   - Hint: "Primary email address for contact"

### User Interface
- Clean section separation with dividers
- Icon-based visual hierarchy
- Consistent with existing design patterns
- Form validation with helpful error messages
- Loading states during save operations
- Success/error notifications

## Database Structure

The contact information is stored using the existing key-value structure in the `company_settings` table:
- Key: `contact_viber`, Value: Viber number
- Key: `contact_mobile`, Value: Mobile number
- Key: `contact_email`, Value: Email address

**No migration required** - Uses the existing flexible key-value architecture.

## API Endpoints

### Get Company Settings
```
GET /api/company-settings
```
Returns all company settings including contact information.

### Update Company Settings
```
POST /api/company-settings
```
**Parameters:**
- `contact_viber` (optional, string, max: 100)
- `contact_mobile` (optional, string, max: 50)
- `contact_email` (optional, email, max: 255)

## Usage

### For Administrators
1. Navigate to **System Settings** from the admin menu
2. Select the **Company Settings** tab
3. Scroll to the **Contact Information** section
4. Enter the desired contact details:
   - Viber Number
   - Mobile Number
   - Email Address
5. Click **Save Changes**

### For Developers
Access contact information via the Company Settings API:

```javascript
// Frontend
import { companySettingService } from '@/services/companySettingService'

const settings = await companySettingService.getSettings()
console.log(settings.contact_viber)
console.log(settings.contact_mobile)
console.log(settings.contact_email)
```

```php
// Backend
use App\Models\CompanySetting;

$viber = CompanySetting::get('contact_viber');
$mobile = CompanySetting::get('contact_mobile');
$email = CompanySetting::get('contact_email');
```

## Validation

### Frontend
- Email format validation (regex pattern)
- All fields are optional
- Real-time validation feedback

### Backend
- Viber: max 100 characters
- Mobile: max 50 characters
- Email: valid email format, max 255 characters
- All fields are nullable

## Testing

### Manual Testing Steps
1. ✅ Load System Settings page - contact fields should load existing values
2. ✅ Enter new contact information and save - should save successfully
3. ✅ Reset form - should revert to saved values
4. ✅ Enter invalid email - should show validation error
5. ✅ Reload page - saved values should persist
6. ✅ Leave fields empty - should save as empty strings

## Future Enhancements

Potential improvements for future versions:
- Phone number format validation
- International phone number support
- Multiple contact methods per type
- Contact information display on public-facing pages
- Integration with booking notifications
- WhatsApp integration alongside Viber
- SMS integration for mobile number

## Related Files

### Backend
- `/app/Http/Controllers/Api/CompanySettingController.php`
- `/app/Models/CompanySetting.php`

### Frontend
- `/src/views/SystemSettings.vue`
- `/src/services/companySettingService.js`

## Migration

No database migration required. The existing `company_settings` table already supports key-value pairs, which is perfect for these new fields.

## Notes

- All contact fields are optional
- Empty values are stored as empty strings
- Contact information is accessible to admin users only for editing
- The information can be displayed publicly if needed (implementation specific)
- Follows the existing Company Settings architecture and patterns
- Maintains consistency with payment settings and other configuration options

---

**Date Implemented:** October 18, 2025
**Version:** 1.0
**Status:** Complete
