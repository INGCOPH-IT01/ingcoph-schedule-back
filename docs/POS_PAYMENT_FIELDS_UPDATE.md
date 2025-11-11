# POS Payment Fields Update

## Overview
Added payment details (Payment Method, Payment Reference Number, Proof of Payment) to POS Reports data table and Excel exports.

## Changes Made

### Backend Changes

#### 1. Database Migration
**File:** `database/migrations/2025_11_10_000001_add_proof_of_payment_to_pos_sales_table.php`
- Added `proof_of_payment` field (LONGTEXT) to `pos_sales` table
- Field stores base64-encoded images/files as JSON array

#### 2. PosSale Model
**File:** `app/Models/PosSale.php`
- Added `proof_of_payment` to `$fillable` array

#### 3. PosSaleController
**File:** `app/Http/Controllers/PosSaleController.php`
- Updated validation in `store()` method to accept proof_of_payment file uploads
- Added file processing to convert uploaded files to base64 and store as JSON
- Stores multiple proof of payment files in format: `["data:image/jpeg;base64,...", ...]`

### Frontend Changes

#### 1. PosReports.vue
**File:** `src/views/PosReports.vue`

**Data Table Headers:**
- Added "Payment Method" column
- Added "Payment Ref" column (using `payment_reference` field)
- Added "Proof of Payment" column (Yes/No indicator)

**Data Table Display:**
- Payment Method: Displayed as colored chip (green for cash, blue for others)
- Payment Reference: Displayed as small text (shows "-" if empty)
- Proof of Payment: Displayed as colored chip (green "Yes" if file exists, gray "No" otherwise)

**Excel Export - Sales Summary Sheet:**
- Added Payment Method column
- Added Payment Ref column
- Added Proof of Payment column (Yes/No)

**Excel Export - Detailed Sheet:**
- Added Payment Method column
- Added Payment Ref column
- Added Proof of Payment column (Yes/No)

## Field Mappings

### Database Fields
- `payment_method` - Stores payment type (cash, gcash, card, etc.)
- `payment_reference` - Stores reference number for non-cash payments
- `proof_of_payment` - Stores base64-encoded images as JSON array

### Frontend Display
- Uses `payment_method` for method display
- Uses `payment_reference` for reference number (NOT `payment_reference_number`)
- Uses `proof_of_payment` for file existence check (NOT `proof_of_payment_url`)

## Migration Status
✅ Migration successfully applied on November 10, 2025

## File Upload Support
The POS system now supports uploading proof of payment files when creating sales:
- Supported formats: JPG, JPEG, PNG, PDF
- Maximum file size: 5MB per file
- Multiple files can be uploaded
- Files are stored as base64-encoded strings in JSON format

## Testing Checklist
- [ ] Create new POS sale with payment details
- [ ] Upload proof of payment files
- [ ] Verify payment details appear in reports data table
- [ ] Export sales report to Excel and verify payment columns
- [ ] Export product report (should not be affected)
- [ ] Test with different payment methods
- [ ] Test with and without proof of payment
- [ ] Verify role-based profit visibility still works
- [ ] Test detailed export sheet includes payment info

## Notes
- The proof_of_payment field stores files as base64-encoded JSON array
- This matches the pattern used in cart_transactions for consistency
- The field shows "Yes"/"No" in reports rather than actual file content
- Admins can view full proof of payment in the sale details dialog
