# System Settings Module

## Overview
The System Settings module is a comprehensive feature that allows administrators to customize the application's theme, dashboard content, and company information from a single centralized interface.

## Features

### 1. **Company Information**
- Upload and manage company logo
- Update company name
- Real-time preview of changes
- Logo removal functionality

### 2. **Theme Customization**
- **Theme Mode**: Switch between Light and Dark modes
- **Primary Color**: Customize the main brand color with:
  - Color picker for precise selection
  - Text input for hex values
  - Predefined color palette (Red, Blue, Green, Purple, Orange, Teal)
- **Secondary Color**: Customize the secondary brand color with:
  - Color picker for precise selection
  - Text input for hex values
  - Predefined color palette (Gray, Dark Gray, Blue Gray, Brown, Cyan, Indigo)
- **Live Theme Preview**: See changes before applying

### 3. **Dashboard Content**
- **Welcome Message**: Customizable greeting message (max 500 characters)
- **Announcement**: Important updates or notifications (max 1000 characters)
- **Display Options**:
  - Toggle Statistics Cards visibility
  - Toggle Recent Bookings visibility
- **Preview Panel**: See how dashboard content will appear

## Technical Implementation

### Backend (Laravel)

#### Updated Files:
1. **`app/Http/Controllers/Api/CompanySettingController.php`**
   - Added support for theme settings:
     - `theme_primary_color`
     - `theme_secondary_color`
     - `theme_mode` (light/dark)
   - Added support for dashboard settings:
     - `dashboard_welcome_message`
     - `dashboard_announcement`
     - `dashboard_show_stats`
     - `dashboard_show_recent_bookings`
   - Enhanced validation rules
   - Returns default values if settings are not configured

#### Database:
- Uses existing `company_settings` table with key-value pairs
- No migration needed - leverages flexible key-value structure

#### API Endpoints:
- **GET** `/api/company-settings` - Get all settings (public)
- **PUT** `/api/admin/company-settings` - Update settings (admin only)
- **DELETE** `/api/admin/company-settings/logo` - Delete logo (admin only)

### Frontend (Vue.js + Vuetify)

#### New Files:
1. **`src/views/SystemSettings.vue`**
   - Tabbed interface with three sections:
     - Company Info
     - Theme Settings
     - Dashboard Content
   - Form validation
   - Real-time previews
   - Success/error notifications
   - Responsive design

2. **Updated `src/plugins/vuetify.js`**
   - Dynamic theme loading from localStorage
   - Real-time theme updates via event listeners
   - Support for both light and dark modes
   - Persistent theme preferences

3. **Updated `src/services/companySettingService.js`**
   - Enhanced to handle theme and dashboard settings
   - Supports FormData for file uploads
   - Handles boolean conversions for Laravel

4. **Updated `src/router/index.js`**
   - Added `/admin/system-settings` route
   - Admin-only access with authentication guard

5. **Updated `src/App.vue`**
   - Added "System Settings" navigation item
   - Positioned under "Company Settings"
   - Admin-only visibility

## Usage

### For Administrators:

1. **Access System Settings**
   - Navigate to Admin menu
   - Click on "System Settings"

2. **Update Company Info**
   - Upload a new logo (JPEG, PNG, GIF, SVG, WEBP - max 2MB)
   - Update company name
   - Click "Save Changes"

3. **Customize Theme**
   - Choose Light or Dark mode
   - Select primary color from palette or use color picker
   - Select secondary color from palette or use color picker
   - Preview the theme
   - Click "Apply Theme" to save
   - **Note**: Page refresh may be needed to see all changes

4. **Configure Dashboard**
   - Enter a welcome message
   - Add announcements
   - Toggle statistics visibility
   - Toggle recent bookings visibility
   - Preview changes
   - Click "Save Changes"

## Theme Persistence

The theme settings are stored in two places:
1. **Backend Database**: Company settings table (persistent across sessions)
2. **Frontend LocalStorage**: `system_theme_settings` key (for instant loading)

When a user loads the application:
1. Theme is loaded from localStorage for instant application
2. Backend settings are fetched and synced
3. Any changes are saved to both locations

## Event System

The module uses custom events for real-time updates:

### Frontend Events:
- **`theme-updated`**: Dispatched when theme is changed
  - Payload: `{ primary, secondary, mode }`
  - Listeners: Vuetify plugin, App.vue

- **`company-settings-updated`**: Dispatched when company info is updated
  - Listeners: App.vue (updates navbar logo and title)

- **`dashboard-settings-updated`**: Dispatched when dashboard settings change
  - Listeners: Dashboard components (future implementation)

## Default Values

### Theme:
- Primary Color: `#B71C1C` (Perfect Smash Red)
- Secondary Color: `#5F6368` (Gray)
- Theme Mode: `light`

### Dashboard:
- Welcome Message: Empty
- Announcement: Empty
- Show Stats: `true`
- Show Recent Bookings: `true`

## Validation Rules

### Company Name:
- Required
- Maximum 255 characters

### Logo:
- Optional
- Formats: JPEG, JPG, PNG, GIF, SVG, WEBP
- Maximum size: 2MB

### Theme Colors:
- Must be valid hex color format (#RRGGBB)
- Example: #B71C1C

### Welcome Message:
- Optional
- Maximum 500 characters

### Announcement:
- Optional
- Maximum 1000 characters

## Security

- All settings endpoints require admin authentication
- File uploads are validated for type and size
- Old logos are automatically deleted when replaced
- XSS protection on all text inputs

## Responsive Design

The System Settings module is fully responsive:
- **Desktop**: Three-column tabbed layout
- **Tablet**: Two-column layout
- **Mobile**: Single-column stacked layout
- Touch-friendly controls
- Optimized for all screen sizes

## Browser Support

- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## Future Enhancements

Potential features for future versions:
- Font customization
- Additional color scheme presets
- Logo position settings
- Dashboard layout customization
- Multi-language support
- Theme preview without saving
- Bulk settings import/export
- Theme scheduling (different themes for different times)

## Troubleshooting

### Theme doesn't update immediately
- Click "Apply Theme" button
- Refresh the page (F5)
- Clear browser cache if needed

### Logo upload fails
- Check file size (must be under 2MB)
- Verify file format (JPEG, PNG, GIF, SVG, WEBP only)
- Ensure storage permissions are correct on server

### Settings not saving
- Verify admin authentication
- Check browser console for errors
- Verify API endpoint is accessible
- Check server logs for backend errors

## Development Notes

### Adding New Settings:

1. **Backend**:
   - Add validation rule to `CompanySettingController@update`
   - Add setting save logic
   - Add to response data with default value

2. **Frontend**:
   - Add field to `SystemSettings.vue` form
   - Add to `formData` reactive object
   - Add to appropriate save method
   - Update service if needed

### Theme Integration:

To use theme settings in new components:
```javascript
// Access current theme
import { useTheme } from 'vuetify'

const theme = useTheme()
const primaryColor = theme.current.value.colors.primary

// Listen for theme changes
window.addEventListener('theme-updated', (event) => {
  const { primary, secondary, mode } = event.detail
  // Handle theme update
})
```

## Version History

- **v1.0.0** (October 16, 2025)
  - Initial release
  - Company info management
  - Theme customization (colors + mode)
  - Dashboard content configuration
  - Real-time previews
  - Event-based updates

