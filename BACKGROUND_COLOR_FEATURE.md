# Background Color Management Feature - Backend Implementation

## Summary
Added ability for admins to customize background gradient colors through Company Settings.

## Files Changed

### 1. Migration (NEW)
**File**: `database/migrations/2025_10_17_000000_add_background_colors_to_company_settings_table.php`

Creates default background color settings:
- `bg_primary_color`: `#FFFFFF` (white)
- `bg_secondary_color`: `#FFEBEE` (light red)
- `bg_accent_color`: `#FFCDD2` (red tint)
- `bg_overlay_color`: `rgba(183, 28, 28, 0.08)`
- `bg_pattern_color`: `rgba(183, 28, 28, 0.03)`

### 2. Model Update
**File**: `app/Models/CompanySetting.php`

Added to `$fillable` array:
```php
'bg_primary_color',
'bg_secondary_color',
'bg_accent_color',
'bg_overlay_color',
'bg_pattern_color',
```

### 3. Controller Update
**File**: `app/Http/Controllers/Api/CompanySettingController.php`

**Changes**:
- Added default values in `index()` method
- Added validation rules in `update()` method
- Added save logic for background colors
- Return background colors in response

## API Response

### GET /api/company-settings
```json
{
  "success": true,
  "data": {
    "company_name": "Perfect Smash",
    "bg_primary_color": "#FFFFFF",
    "bg_secondary_color": "#FFEBEE",
    "bg_accent_color": "#FFCDD2",
    "bg_overlay_color": "rgba(183, 28, 28, 0.08)",
    "bg_pattern_color": "rgba(183, 28, 28, 0.03)",
    ...
  }
}
```

### POST /api/company-settings
**Request**:
```json
{
  "company_name": "Perfect Smash",
  "bg_primary_color": "#FFFFFF",
  "bg_secondary_color": "#E3F2FD",
  "bg_accent_color": "#BBDEFB"
}
```

**Response**:
```json
{
  "success": true,
  "message": "Company settings updated successfully",
  "data": {
    "company_name": "Perfect Smash",
    "bg_primary_color": "#FFFFFF",
    "bg_secondary_color": "#E3F2FD",
    "bg_accent_color": "#BBDEFB",
    ...
  }
}
```

## Database Structure

**Table**: `company_settings`

New entries (key-value pairs):
```
| id | key                  | value                        | created_at | updated_at |
|----|----------------------|------------------------------|------------|------------|
| .. | bg_primary_color     | #FFFFFF                      | ...        | ...        |
| .. | bg_secondary_color   | #FFEBEE                      | ...        | ...        |
| .. | bg_accent_color      | #FFCDD2                      | ...        | ...        |
| .. | bg_overlay_color     | rgba(183, 28, 28, 0.08)      | ...        | ...        |
| .. | bg_pattern_color     | rgba(183, 28, 28, 0.03)      | ...        | ...        |
```

## Validation Rules

```php
'bg_primary_color' => 'nullable|string|max:50',
'bg_secondary_color' => 'nullable|string|max:50',
'bg_accent_color' => 'nullable|string|max:50',
'bg_overlay_color' => 'nullable|string|max:100',
'bg_pattern_color' => 'nullable|string|max:100',
```

## Permissions
- Only **admin** users can update colors
- Handled by `AdminMiddleware`

## Installation

1. Run migration:
```bash
php artisan migrate
```

2. Settings will be created with default Perfect Smash red & white colors

## Testing

### Test Endpoints
```bash
# Get settings
curl -X GET http://localhost:8000/api/company-settings \
  -H "Authorization: Bearer {token}"

# Update colors
curl -X POST http://localhost:8000/api/company-settings \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "Perfect Smash",
    "bg_primary_color": "#FFFFFF",
    "bg_secondary_color": "#E3F2FD",
    "bg_accent_color": "#BBDEFB"
  }'
```

## Integration with Frontend

Frontend will:
1. Load colors from `/api/company-settings`
2. Apply colors to background gradients
3. Update colors via POST request
4. Emit event when colors change

---

**Status**: ✅ Implemented and Tested
**Version**: 1.0.0
**Date**: October 17, 2025

