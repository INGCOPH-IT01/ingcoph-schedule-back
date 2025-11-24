# Effectivity Date Feature - Implementation Summary

## Feature Overview

The system now supports **scheduled pricing changes** with automatic transitions during bookings. When a booking spans across an effectivity date, the system automatically calculates the correct price for each portion of the booking.

---

## Key Components

### 1. Database Schema

#### `sport_time_based_pricing` Table
```sql
effective_date DATETIME NULL
```

#### `sport_price_histories` Table (audit trail)
```sql
- sport_id
- change_type
- changed_by
- old_value (JSON)
- new_value (JSON)
- effective_date
- description
- created_at
- updated_at
```

---

### 2. Backend Logic (Sport Model)

#### Enhanced Price Calculation
The `calculatePriceForRange()` method now:

1. **Detects Transitions**
   - Scans all active pricing rules
   - Identifies effectivity dates within the booking period
   - Sorts transitions chronologically

2. **Splits Calculation**
   - Divides booking into segments at each transition
   - Calculates each segment separately
   - Sums all segments for total price

3. **Accurate Pricing**
   - Before effectivity date: Uses old pricing
   - After effectivity date: Uses new pricing
   - Handles multiple transitions in one booking

#### Key Methods

```php
// Main calculation with transition handling
calculatePriceForRange(Carbon $startTime, Carbon $endTime): float

// Find all transitions in booking period
getEffectivityTransitions(Carbon $startTime, Carbon $endTime): array

// Calculate price for a segment without transitions
calculatePriceForSegment(Carbon $startTime, Carbon $endTime): float

// Get price at a specific datetime (checks effective date)
getPriceForDateTime(Carbon $dateTime): float
```

---

### 3. Price History Tracking

All pricing changes are automatically logged:

- **Default price updates**: When sport base price changes
- **Time-based pricing created**: New pricing rule added
- **Time-based pricing updated**: Existing rule modified
- **Time-based pricing deleted**: Rule removed

Each log includes:
- What changed (old vs new values)
- Who made the change
- When it was made
- When it takes effect
- Human-readable description

---

### 4. Frontend Features

#### Sports Management UI

**Three-Tab System:**

1. **Active Rules Tab**
   - Shows pricing currently in effect
   - Displays rules where effectivity date has passed (or no date set)
   - Badge: "ACTIVE NOW"

2. **Pending Changes Tab**
   - Shows rules scheduled for the future
   - Displays rules where effectivity date hasn't arrived yet
   - Badge: "PENDING"
   - Clear warning: "Will take effect on: [date]"

3. **All Rules Tab**
   - Shows everything with status indicators
   - Color-coded badges and icons

**Price History Viewer:**
- Timeline view of all pricing changes
- Shows who made changes and when
- Before/after comparison
- Effective dates clearly displayed

**User Guidance:**
- Info alerts explaining scheduled pricing
- Tips on creating vs editing rules
- Clear feedback on immediate vs scheduled changes

---

## Usage Examples

### Example 1: Simple Future Pricing

**Current Setup:**
- Regular rate: ₱100/hr (6am-10pm)

**Schedule Change:**
- Create new rule: ₱150/hr (6am-10pm)
- Effective date: December 25, 2024 12:00 AM

**Booking Made:**
- December 20: User books Dec 24 8pm-10pm
  - **Charges**: ₱100/hr × 2 = ₱200 (uses old rate)

- December 26: User books Dec 26 8pm-10pm
  - **Charges**: ₱150/hr × 2 = ₱300 (uses new rate)

### Example 2: Booking Across Transition

**Booking:**
- December 24, 2024 10:00 PM - December 25, 2024 2:00 AM

**Pricing:**
- Before Dec 25 12am: ₱100/hr
- After Dec 25 12am: ₱150/hr

**Calculation:**
```
Period 1 (Dec 24 10pm - Dec 25 12am): 2 hours × ₱100 = ₱200
Period 2 (Dec 25 12am - 2am):        2 hours × ₱150 = ₱300
Total:                                              = ₱500
```

### Example 3: Multiple Transitions

**Booking:**
- December 23, 2024 8:00 PM - December 26, 2024 8:00 AM

**Pricing Rules:**
- Regular: ₱100/hr
- Christmas Eve Special: ₱200/hr (effective Dec 24 12am)
- Christmas Day Premium: ₱250/hr (effective Dec 25 12am)
- Post-holiday: ₱150/hr (effective Dec 26 12am)

**Calculation:**
System automatically:
1. Detects 3 transitions (Dec 24, 25, 26 at midnight)
2. Splits booking into 4 segments
3. Applies correct rate to each segment
4. Sums for total price

---

## Benefits

### For Administrators

✅ **Set and Forget**
- Schedule pricing changes weeks in advance
- No need to be awake at midnight to update prices
- System handles everything automatically

✅ **Complete Audit Trail**
- Every change is logged
- Track who made what changes
- Review history anytime

✅ **Flexible Scheduling**
- Set exact date and time (down to the minute)
- Multiple future changes can coexist
- Easy to modify or cancel scheduled changes

### For Customers

✅ **Fair Pricing**
- Pay the correct rate for actual usage time
- No overpaying due to timing
- Transparent calculations

✅ **Predictable**
- Prices shown at booking time are accurate
- System automatically applies appropriate rates
- Clear breakdown if booking spans transitions

---

## API Endpoints

### Time-Based Pricing
```
GET    /api/sports/{sportId}/time-based-pricing
POST   /api/sports/{sportId}/time-based-pricing
PUT    /api/sports/{sportId}/time-based-pricing/{pricingId}
DELETE /api/sports/{sportId}/time-based-pricing/{pricingId}
```

### Price History
```
GET    /api/sports/{sportId}/price-history
```

---

## Best Practices

### Creating Scheduled Changes

**DO:**
- ✅ Create new rules with future effective dates
- ✅ Set clear, descriptive names (e.g., "Holiday Rate 2024")
- ✅ Test with sample date ranges
- ✅ Review in "Pending Changes" tab before effective date

**DON'T:**
- ❌ Edit existing active rules when scheduling changes (creates confusion)
- ❌ Set effectivity dates in the past
- ❌ Create overlapping rules without considering priority

### Workflow Recommendation

**For Immediate Changes:**
1. Edit existing rule or create new rule
2. Leave effectivity date empty
3. Save → Applies immediately

**For Scheduled Changes:**
1. Create NEW rule (don't edit existing)
2. Set future effectivity date
3. Appears in "Pending Changes" tab
4. Current pricing stays in "Active" tab
5. On effective date, new rule automatically takes over

---

## Testing Scenarios

See `docs/pricing-effectivity-transitions.md` for detailed test cases including:
- Bookings before effectivity date
- Bookings after effectivity date
- Bookings spanning effectivity date
- Multiple transitions in one booking
- Minute-level precision testing

---

## Technical Notes

### Performance
- Transitions are only checked for active pricing rules
- Efficient sorting and segmentation
- Minimal database queries

### Precision
- Supports minute-level effectivity dates
- Handles partial hours correctly
- Rounds final price to 2 decimal places

### Priority Handling
- Higher priority rules override lower priority
- Effective date checked before applying rule
- Day-of-week and time-of-day filters still apply

---

## Future Enhancements (Ideas)

- 📧 Email notifications when scheduled pricing takes effect
- 📊 Dashboard showing upcoming pricing changes
- 🔄 Bulk scheduling for multiple sports
- 📈 Price change analytics and reporting
- ⏰ Recurring effective dates (e.g., "every weekend")

---

## Support

For questions or issues, refer to:
- Technical details: `docs/pricing-effectivity-transitions.md`
- Price history schema: `database/migrations/2025_11_21_105620_create_sport_price_histories_table.php`
- Frontend implementation: `src/views/SportsManagement.vue`
