# Module Titles Feature Implementation - Complete

## Overview
This document describes the implementation of the module titles customization feature, which allows admins to customize the title text, title color, and badge color for each module in the system including the Admin Panel.

## Changes Made

### 1. Backend Updates

#### Database Migration
- **File**: `database/migrations/2025_10_17_011304_add_module_titles_to_company_settings_table.php`
- **Changes**: Added Admin Panel module title settings
  - `module_admin_text` (default: "Admin Panel")
  - `module_admin_color` (default: "#B71C1C")
  - `module_admin_badge_color` (default: "#D32F2F")

#### Controller Updates
- **File**: `app/Http/Controllers/Api/CompanySettingController.php`
- **Changes**:
  - Added default values for admin module in `index()` method
  - Added validation rules for admin module in `updateModuleTitles()` method
  - Added save logic for admin module settings
  - Added admin module data to response

### 2. Frontend Updates

#### Admin Dashboard
- **File**: `src/views/AdminDashboard.vue`
- **Changes**:
  - Fixed header styling to match other modules (red gradient badge instead of white)
  - Added reactive module title variables (`moduleTitle`, `titleColor`, `badgeColor`)
  - Added dynamic color binding to header badge and title
  - Added `loadModuleTitles()` function to load from localStorage
  - Added event listener for `module-titles-updated` event
  - Module title now changes dynamically when updated in System Settings

#### System Settings
- **File**: `src/views/SystemSettings.vue`
- **Changes**:
  - Added new "Module Titles" tab between Theme Settings and Dashboard Settings
  - Created comprehensive UI for customizing all 5 modules:
    1. Admin Panel
    2. Courts
    3. Sports
    4. Bookings
    5. Users
  - Each module has:
    - Text field for module title
    - Color picker with hex input for title color
    - Color picker with hex input for badge color
  - Added reactive data for module titles
  - Added `saveModuleTitles()` function to save to backend and localStorage
  - Added `resetModuleTitles()` function to revert changes
  - Added success/error message handling
  - Dispatches `module-titles-updated` event to update all modules in real-time

#### App.vue
- **File**: `src/App.vue`
- **Changes**:
  - Added admin module to the moduleTitles object in settings loading
  - Ensures admin module titles are synced with other modules

#### Company Settings Service
- **File**: `src/services/companySettingService.js`
- **Status**: Already had `updateModuleTitles()` method - no changes needed

## Features

### Module Title Customization
- **Admin Panel**: Customizable title text, title color, and badge color
- **Courts**: Customizable title text, title color, and badge color
- **Sports**: Customizable title text, title color, and badge color
- **Bookings**: Customizable title text, title color, and badge color
- **Users**: Customizable title text, title color, and badge color

### Real-time Updates
- Changes are immediately applied across all open pages
- Uses localStorage for instant synchronization
- Backend persistence ensures settings survive page refreshes

### User Experience
- Color pickers with visual preview
- Hex input fields for precise color control
- Save and Reset buttons for easy management
- Success/error feedback messages
- Responsive design for all screen sizes

## Default Colors
All modules use the Perfect Smash red theme by default:
- **Title Color**: `#B71C1C` (Deep Red)
- **Badge Color**: `#D32F2F` (Lighter Red)

## API Endpoints

### Get All Settings
```
GET /api/company-settings
```
Returns all company settings including module titles.

### Update Module Titles
```
POST /api/admin/company-settings/module-titles
```
Request body:
```json
{
  "admin": {
    "text": "Admin Panel",
    "color": "#B71C1C",
    "badgeColor": "#D32F2F"
  },
  "courts": {
    "text": "Manage Courts",
    "color": "#B71C1C",
    "badgeColor": "#D32F2F"
  },
  "sports": {
    "text": "Manage Sports",
    "color": "#B71C1C",
    "badgeColor": "#D32F2F"
  },
  "bookings": {
    "text": "My Bookings",
    "color": "#B71C1C",
    "badgeColor": "#D32F2F"
  },
  "users": {
    "text": "Manage Users",
    "color": "#B71C1C",
    "badgeColor": "#D32F2F"
  }
}
```

## How to Use

### For Administrators
1. Navigate to **System Settings** (in the sidebar)
2. Click on the **Module Titles** tab
3. Customize the text and colors for each module
4. Click **Save Changes** to apply
5. Changes will be immediately reflected across all modules

### For Developers
To add module title support to a new view:

1. **Add reactive refs in setup()**:
```javascript
const moduleTitle = ref('Your Default Title')
const titleColor = ref('#B71C1C')
const badgeColor = ref('#D32F2F')
```

2. **Add load function**:
```javascript
const loadModuleTitles = () => {
  const savedTitles = localStorage.getItem('moduleTitles')
  if (savedTitles) {
    try {
      const titles = JSON.parse(savedTitles)
      if (titles.yourModule) {
        moduleTitle.value = titles.yourModule.text
        titleColor.value = titles.yourModule.color
        badgeColor.value = titles.yourModule.badgeColor
      }
    } catch (error) {
      console.error('Error parsing module titles:', error)
    }
  }
}
```

3. **Add event listener in onMounted()**:
```javascript
onMounted(() => {
  loadModuleTitles()
  window.addEventListener('module-titles-updated', handleModuleTitlesUpdate)
})
```

4. **Use in template**:
```vue
<div class="header-badge" :style="{ background: `linear-gradient(135deg, ${badgeColor} 0%, ${titleColor} 100%)` }">
  <v-icon color="white">mdi-your-icon</v-icon>
  Badge Text
</div>
<h1 :style="{ color: titleColor }">{{ moduleTitle }}</h1>
```

## Files Modified

### Backend
1. `database/migrations/2025_10_17_011304_add_module_titles_to_company_settings_table.php`
2. `app/Http/Controllers/Api/CompanySettingController.php`

### Frontend
1. `src/views/AdminDashboard.vue`
2. `src/views/SystemSettings.vue`
3. `src/App.vue`

## Testing
- ✅ Admin Panel header styling now matches other modules
- ✅ Module titles can be customized in System Settings
- ✅ Colors can be changed for title and badge
- ✅ Changes persist after page refresh
- ✅ Changes apply in real-time across all pages
- ✅ Reset button restores saved values
- ✅ Migration runs successfully
- ✅ No linter errors

## Notes
- The Admin Panel was previously using a white badge with transparent background, now it uses the same red gradient as other modules
- All module titles are stored in the `company_settings` table as key-value pairs
- Settings are versioned to trigger automatic updates in the frontend
- LocalStorage is used for immediate UI updates, with backend as the source of truth

## Future Enhancements
Potential improvements could include:
- Add more modules to the customization system
- Add icon customization alongside text and colors
- Add preview panel to see changes before saving
- Add color themes/presets for quick application
- Add export/import functionality for module settings

