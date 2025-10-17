# Price Per Hour Field Migration

## Overview
Successfully moved the `price_per_hour` field from the Court model to the Sport model. This change makes sense because pricing should be associated with the sport type rather than individual courts.

## Backend Changes

### Database Migrations
The following migrations were already in place:
- `2025_10_16_063451_add_price_per_hour_to_sports_table.php` - Adds `price_per_hour` to sports table
- `2025_10_16_065054_remove_price_per_hour_from_courts_table.php` - Removes `price_per_hour` from courts table

### Model Updates

#### Sport Model (`app/Models/Sport.php`)
- Already includes `price_per_hour` in fillable fields
- Casts `price_per_hour` as `decimal:2`

#### Court Model (`app/Models/Court.php`)
- `price_per_hour` is not in fillable fields (correct)
- Maintains relationship to Sport via `sport()` and `sports()` methods

### Controller Updates

#### BookingController (`app/Http/Controllers/Api/BookingController.php`)
Updated 4 references from `$court->price_per_hour` to `$court->sport->price_per_hour`:
- Line 147: Total price calculation for bookings
- Line 518: Available slots price calculation
- Line 536: Booking price calculation in time slots
- Line 560: Cart item price calculation

#### RecurringSchedule Model (`app/Models/RecurringSchedule.php`)
Updated 1 reference in the `calculateTotalPrice()` method:
- Line 201: Now uses `$court->sport->price_per_hour` with null checks

### Seeder Updates

#### CourtSeeder (`database/seeders/CourtSeeder.php`)
- Removed `price_per_hour` field from court creation

#### SportSeeder (`database/seeders/SportSeeder.php`)
- Already includes `price_per_hour` for all sports

## Frontend Changes

### Components Updated

1. **BookingCart.vue**
   - Line 421: Court list display
   - Line 1071: Price calculation in editing
   - Line 1126: Fallback price calculation

2. **CourtDialog.vue**
   - Removed `price_per_hour` input field from form
   - Removed from form initialization
   - Removed from resetForm function
   - Removed from populateForm function

3. **NewBookingDialog.vue**
   - Already correctly using `selectedSport.value?.price_per_hour`

4. **GlobalBookingDialog.vue**
   - 5 references updated to use `court.sport.price_per_hour`

5. **BookingDetailsDialog.vue**
   - 1 reference updated to use `booking.court?.sport?.price_per_hour`

### Views Updated

1. **Sports.vue**
   - Updated `getCourtPrice()` function to use `courts.value[0].sport.price_per_hour`
   - Template already correctly using `sport.price_per_hour`

2. **Bookings.vue**
   - 7 references updated to use `court.sport.price_per_hour`
   - Includes price display, calculations, and slot management

3. **Courts.vue**
   - 2 references updated to use `court.sport?.price_per_hour`
   - Card view and table view both updated

4. **Home.vue**
   - Updated `getCourtPrice()` function to use `courts.value[0].sport.price_per_hour`

5. **CourtDetails.vue**
   - 1 reference updated to use `court.sport?.price_per_hour`

6. **CourtDetail.vue**
   - 1 reference updated to use `court.sport?.price_per_hour`

7. **SportsManagement.vue**
   - Already correctly using `sport.price_per_hour`

## Migration Steps

To apply these changes to your database:

1. Run the migrations:
```bash
cd ingcoph-schedule-back
php artisan migrate
```

2. If you need to seed fresh data:
```bash
php artisan db:seed --class=SportSeeder
php artisan db:seed --class=CourtSeeder
```

## Important Notes

1. **Data Migration**: If you have existing courts with `price_per_hour` data, you should create a data migration to copy those values to their respective sports before running the removal migration.

2. **API Responses**: The backend API should ensure courts are always loaded with their sport relationship using `->with('sport')` to avoid null reference errors in the frontend.

3. **Null Safety**: All frontend references now use optional chaining (`court.sport?.price_per_hour`) to handle cases where the sport relationship might not be loaded.

## Testing Checklist

- [ ] Verify all bookings display correct prices
- [ ] Test creating new bookings with different sports
- [ ] Check cart functionality with price calculations
- [ ] Verify admin court management (no price field in court form)
- [ ] Test sports management (price field should be editable)
- [ ] Confirm recurring schedules calculate prices correctly
- [ ] Check all court listing pages display prices
- [ ] Test booking approval and QR code generation

## Rollback

If you need to rollback:

1. Rollback migrations:
```bash
php artisan migrate:rollback --step=2
```

2. Revert code changes using git:
```bash
git checkout HEAD -- .
```
