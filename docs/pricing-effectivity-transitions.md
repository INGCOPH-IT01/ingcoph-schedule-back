# Pricing Effectivity Date Transitions in Bookings

## Overview

The booking system now automatically handles pricing transitions when an effectivity date falls within a booking period. This means customers are charged accurately based on when pricing changes take effect, even if their booking spans across the transition.

## How It Works

### Example Scenario

**Current Pricing:**
- Peak Hours (6pm - 10pm): ₱200/hr
- Off-Peak (10pm - 6am): ₱100/hr

**Scheduled Pricing Change:**
- New Peak Hours pricing: ₱250/hr
- Effective Date: December 25, 2024 at 12:00 AM

**Customer Booking:**
- Date: December 24, 2024 8:00 PM - December 25, 2024 2:00 AM (6 hours)

### Price Calculation

The system will automatically split the calculation at the effectivity date:

**Period 1: Dec 24 8:00 PM - Dec 25 12:00 AM (4 hours)**
- Uses OLD pricing (before effectivity date)
- 8pm-10pm: ₱200/hr × 2 hours = ₱400
- 10pm-12am: ₱100/hr × 2 hours = ₱200
- **Subtotal: ₱600**

**Period 2: Dec 25 12:00 AM - Dec 25 2:00 AM (2 hours)**
- Uses NEW pricing (after effectivity date)
- 12am-2am: ₱100/hr × 2 hours = ₱200 (off-peak rate, no change in this example)
- **Subtotal: ₱200**

**Total Booking Cost: ₱800**

## Technical Implementation

### Key Methods

#### `calculatePriceForRange()`
Main method that orchestrates the price calculation:
1. Identifies all effectivity transitions within the booking period
2. Splits the booking into segments at each transition point
3. Calculates price for each segment separately
4. Sums all segments for the total price

#### `getEffectivityTransitions()`
Finds all pricing rule effectivity dates that fall within the booking timeframe:
- Only considers active pricing rules
- Only includes transitions that occur between start and end times
- Returns transitions sorted chronologically

#### `calculatePriceForSegment()`
Calculates the price for a continuous time segment (no transitions):
- Breaks down into hourly chunks
- Handles partial hours at the end
- Uses the appropriate pricing rule for each hour

#### `getPriceForDateTime()`
Determines which pricing rule applies at a specific moment:
- Checks if the rule's effective date has been reached
- Considers day of week and time of day
- Uses priority to resolve conflicts

## Benefits

### For Customers
- **Fair Pricing**: Pay the correct rate for when they actually use the facility
- **Transparent**: Clear breakdown showing old and new rates
- **No Surprises**: System automatically handles the transition

### For Administrators
- **Set and Forget**: Schedule pricing changes in advance
- **No Manual Intervention**: Pricing updates automatically at the specified time
- **Accurate Billing**: System ensures correct charges across transitions

## Edge Cases Handled

### Multiple Transitions
If multiple pricing rules have effectivity dates within a single booking:
```
Booking: 6:00 PM - 2:00 AM

Transitions:
- 8:00 PM: Holiday rate starts (₱300/hr)
- 12:00 AM: Late night discount starts (₱150/hr)

Result:
- 6pm-8pm: Regular rate
- 8pm-12am: Holiday rate
- 12am-2am: Late night discount rate
```

### Minute-Level Precision
Effectivity dates can be set to any minute:
```
Effectivity Date: Dec 25, 2024 12:30 AM

Booking: 12:00 AM - 1:00 AM
- 12:00am-12:30am: Old rate (30 minutes = 0.5 hour)
- 12:30am-1:00am: New rate (30 minutes = 0.5 hour)
```

### Overlapping Rules
When multiple rules apply to the same time:
- Higher priority rules take precedence
- Effective date is checked before applying any rule
- Only rules that have reached their effective date are considered

## Database Schema

### `sport_time_based_pricing` Table
```sql
effective_date DATETIME NULL
```

When `effective_date` is:
- **NULL**: Rule applies immediately when saved
- **Past date**: Rule is currently active
- **Future date**: Rule is scheduled, waiting to activate

## Usage Example

### Creating a Scheduled Pricing Change

```php
// Create a new pricing rule that starts on New Year
SportTimeBasedPricing::create([
    'sport_id' => $sportId,
    'name' => 'New Year Peak Pricing',
    'start_time' => '18:00',
    'end_time' => '22:00',
    'price_per_hour' => 300.00,
    'days_of_week' => [0, 5, 6], // Sunday, Friday, Saturday
    'effective_date' => '2025-01-01 00:00:00',
    'is_active' => true,
    'priority' => 10
]);
```

### Booking Calculation
```php
$sport = Sport::find($sportId);
$startTime = Carbon::parse('2024-12-31 20:00:00');
$endTime = Carbon::parse('2025-01-01 02:00:00');

$totalPrice = $sport->calculatePriceForRange($startTime, $endTime);
// Automatically handles the midnight transition
```

## Testing Scenarios

### Test 1: Booking Before Effectivity Date
- **Booking**: Dec 20 8pm - 10pm
- **Effectivity**: Dec 25 12am
- **Expected**: Uses old pricing entirely

### Test 2: Booking After Effectivity Date
- **Booking**: Dec 26 8pm - 10pm
- **Effectivity**: Dec 25 12am
- **Expected**: Uses new pricing entirely

### Test 3: Booking Across Effectivity Date
- **Booking**: Dec 24 11pm - Dec 25 1am
- **Effectivity**: Dec 25 12am
- **Expected**:
  - Dec 24 11pm-12am: Old pricing
  - Dec 25 12am-1am: New pricing

### Test 4: Multiple Effectivity Transitions
- **Booking**: Dec 20 6pm - Dec 26 6am
- **Effectivities**: Dec 23 12am, Dec 25 12am
- **Expected**: Split into 3 segments with appropriate pricing

## Price History

All pricing changes are automatically logged in the `sport_price_histories` table, including:
- What changed
- When it was scheduled
- When it became effective
- Who made the change

This provides a complete audit trail for pricing decisions.
