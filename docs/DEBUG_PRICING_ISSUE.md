# Debug Pricing Effectivity Issue

## Problem
Booking for November 24, 2025 is using "New Regular Hours" (₱350/hr) instead of "Regular Hours" (₱300/hr), even though "New Regular Hours" should have an effective date of December 1, 2025.

## Steps to Debug

### Step 1: Check Database Values

Run this SQL query to see all pricing rules and their effective dates:

```sql
SELECT
    id,
    sport_id,
    name,
    start_time,
    end_time,
    price_per_hour,
    priority,
    effective_date,
    UNIX_TIMESTAMP(effective_date) as effective_timestamp,
    is_active,
    created_at
FROM sport_time_based_pricing
WHERE sport_id = (SELECT id FROM sports WHERE name = 'Badminton')
ORDER BY priority DESC, created_at DESC;
```

**Expected Output:**
- "New Regular Hours" should have `effective_date = '2025-12-01 00:00:00'`
- "New Peak Hours" should have `effective_date = '2025-12-01 00:00:00'`
- "Regular Hours" should have `effective_date = NULL`
- "Peak Hours" should have `effective_date = NULL`

### Step 2: Run Debug Script

Run the test script from the project root:

```bash
cd /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back
php test-pricing-debug.php
```

This will show:
1. All pricing rules with their effective dates
2. Test scenarios for different booking dates
3. Which rule is being matched for each scenario

### Step 3: Check Laravel Logs

With the logging now added to `Sport.php`, try creating a booking and check the logs:

```bash
tail -f /Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back/storage/logs/laravel.log
```

Look for log entries like:
```
Checking effectivity: {
    "rule_name": "New Regular Hours",
    "booking_datetime": "2025-11-24 09:00:00",
    "effective_date": "2025-12-01 00:00:00",
    "booking_timestamp": 1732438800,
    "effective_timestamp": 1733011200,
    "should_skip": true
}
```

## Possible Causes

### Cause 1: Effective Date is NULL

**Check:**
```sql
SELECT name, effective_date
FROM sport_time_based_pricing
WHERE name IN ('New Regular Hours', 'New Peak Hours');
```

**Solution:** If effective_date is NULL, re-save the rules with the correct date.

### Cause 2: Effective Date is Wrong Date

**Check:** The effective_date value in the database

**Solution:** Update the effective dates:
```sql
UPDATE sport_time_based_pricing
SET effective_date = '2025-12-01 00:00:00'
WHERE name IN ('New Regular Hours', 'New Peak Hours');
```

### Cause 3: Timezone Issue

**Check:** Server timezone vs database timezone

```bash
# Check PHP timezone
php -r "echo date_default_timezone_get();"

# Check MySQL timezone
mysql -u root -p -e "SELECT @@global.time_zone, @@session.time_zone;"
```

**Solution:** Ensure consistent timezone in:
- `/Users/karloalfonso/Documents/GitHub/schedule/ingcoph-schedule-back/config/app.php` → `'timezone' => 'Asia/Manila'`
- MySQL configuration

### Cause 4: Carbon Comparison Issue

**Check:** Look at the debug logs for timestamp comparison

**Solution:** Already fixed in the code update - now comparing timestamps directly

### Cause 5: Frontend Not Sending Effective Date

**Check:** Look at the API request when creating the new pricing rules

```bash
# In browser console when creating a rule:
# Check Network tab → Payload
```

**Solution:** Verify the frontend is sending `effective_date` in the request body

## Quick Fix Commands

### Option 1: Update via SQL
```sql
-- Set correct effective dates
UPDATE sport_time_based_pricing
SET effective_date = '2025-12-01 00:00:00'
WHERE sport_id = (SELECT id FROM sports WHERE name = 'Badminton')
  AND name LIKE 'New%';

-- Verify
SELECT name, effective_date, priority
FROM sport_time_based_pricing
WHERE sport_id = (SELECT id FROM sports WHERE name = 'Badminton')
ORDER BY priority DESC;
```

### Option 2: Re-create Rules via UI
1. Delete "New Regular Hours" and "New Peak Hours"
2. Create them again with effective date: December 1, 2025 12:00 AM
3. Verify in the Pending Changes tab

## Verification

After fixing, test these scenarios:

1. **Booking Nov 24, 2025 9am-10am**
   - Expected: ₱300 (Regular Hours)
   - If wrong: Check logs

2. **Booking Dec 1, 2025 9am-10am**
   - Expected: ₱350 (New Regular Hours)
   - If wrong: Check effective date

3. **Booking Nov 30, 2025 11pm - Dec 1, 2025 1am**
   - Expected: Split pricing
     - Nov 30 11pm-12am: ₱350 (Peak Hours)
     - Dec 1 12am-1am: ₱400 (New Peak Hours) or default price
   - If wrong: Check transition logic

## Contact for Help

If issue persists after these steps, provide:
1. Output of the SQL query
2. Output of test-pricing-debug.php
3. Relevant lines from laravel.log
4. Screenshot of the booking screen
