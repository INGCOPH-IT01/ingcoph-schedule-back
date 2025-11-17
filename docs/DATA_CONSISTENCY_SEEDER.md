# Data Consistency Analyzer & Fixer Seeder

## Overview

The `DataConsistencyAnalyzerSeeder` is a comprehensive database maintenance tool that analyzes and fixes inconsistent data across all booking, waitlist, and POS-related tables. It performs 10 different types of consistency checks and can automatically fix most issues.

## Features

This seeder checks and fixes:

### 1. **Booking Status Consistency**
- Ensures booking status matches cart transaction approval_status
- Fixes approved transactions with pending/rejected bookings
- Fixes rejected transactions with non-rejected bookings
- Fixes pending transactions with approved bookings

### 2. **Payment Consistency**
- Syncs payment status between transactions and bookings
- Sets `paid_at` timestamps for paid bookings
- Validates that `showed_up` attendance requires paid status
- Fixes bookings with payment method but no payment status

### 3. **Waitlist Data Consistency**
- Validates converted waitlist entries have corresponding bookings
- Ensures notified waitlist entries have `expires_at` set
- Marks expired waitlist entries correctly
- Fixes duplicate waitlist positions
- Reorders waitlist positions sequentially

### 4. **Cart Transaction & Cart Item Consistency**
- Creates missing bookings for approved transactions
- Syncs cart item status with transaction approval_status
- Validates waitlist cart items
- Ensures all cart data is properly linked

### 5. **POS Sales Consistency**
- Validates POS sale references to bookings
- Recalculates and fixes total amount mismatches
- Validates subtotal calculations
- Ensures all POS items are properly accounted for

### 6. **Attendance Data Consistency**
- Prevents attendance scan count from exceeding number of players
- Ensures checked-in bookings have `checked_in_at` timestamp
- Updates status for bookings with scan counts
- Validates attendance_status logic

### 7. **Foreign Key Integrity**
- Validates user_id references
- Validates court_id references
- Validates sport_id references
- Attempts to fix broken foreign key relationships

### 8. **Orphaned Records Detection**
- Finds bookings pointing to non-existent cart transactions
- Finds cart items pointing to non-existent transactions
- Identifies records requiring manual review

### 9. **Price Consistency**
- Validates cart transaction total_price calculations
- Ensures booking_amount matches sum of cart items
- Detects zero or negative prices
- Recalculates totals including POS amounts

### 10. **Duplicate Bookings Detection**
- Finds duplicate bookings (same court, time, user)
- Keeps the first booking and cancels duplicates
- Adds admin notes to cancelled duplicates

## Usage

### Run Analysis Only (Safe Mode)

To analyze data without making any changes:

```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

When prompted:
- **Fix mode**: Choose `no` to only analyze
- **Verbose output**: Choose `yes` to see detailed information

### Run with Auto-Fix

To analyze and automatically fix issues:

```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

When prompted:
- **Fix mode**: Choose `yes` to enable automatic fixes
- **Verbose output**: Choose `yes` to see what's being fixed

## Output

The seeder provides:

1. **Detailed Section Reports**: Shows issues found in each category
2. **Fix Confirmation**: Indicates which issues were fixed
3. **Manual Review Alerts**: Flags issues that require manual intervention
4. **Summary Report**: Final count of issues found and fixed

### Example Output

```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

Do you want to automatically fix issues? (yes/no) [no]: yes
Enable verbose output? (yes/no) [yes]: yes

⚠️  FIX MODE ENABLED - Changes will be made to the database

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Booking Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #123: Status 'pending' but transaction is 'approved'
  ✓ Fixed: Set booking status to 'approved'
  ✗ Booking #124: Status 'pending' but transaction is 'approved'
  ✓ Fixed: Set booking status to 'approved'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. Payment Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #125: Payment status 'unpaid' but transaction is 'paid'
  ✓ Fixed: Synced payment data from transaction

...

╔════════════════════════════════════════════════════════════╗
║                    SUMMARY REPORT                          ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 47
Issues Fixed: 45
Issues Requiring Manual Review: 2

✓ Automated fixes have been applied.
⚠ Some issues require manual review. Please check the output above.

Analysis complete!
```

## When to Run

### Recommended Scenarios

1. **After Data Import**: When importing data from external sources
2. **After System Updates**: After major version upgrades or migrations
3. **Periodic Maintenance**: Run monthly as part of database maintenance
4. **After Bug Fixes**: When bugs affecting data integrity are fixed
5. **Before Audits**: Before financial or operational audits
6. **When Inconsistencies Suspected**: If users report data issues

### Integration with Maintenance Schedule

You can schedule this seeder to run automatically:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    // Run analysis weekly (no fixes, just report)
    $schedule->command('db:seed', [
        '--class' => 'DataConsistencyAnalyzerSeeder'
    ])->weeklyOn(1, '01:00'); // Every Monday at 1 AM
}
```

## Safety Features

1. **Analysis Mode**: Default mode only analyzes without making changes
2. **Confirmation Required**: Fix mode requires explicit confirmation
3. **Transaction Safety**: Uses database transactions where applicable
4. **Manual Review Flags**: Identifies issues that need human review
5. **Detailed Logging**: Verbose output shows exactly what's being changed
6. **Non-Destructive**: Never deletes data, only updates or cancels

## Manual Review Required For

Some issues cannot be automatically fixed and require manual intervention:

- **Orphaned Bookings**: Bookings pointing to deleted transactions
- **Invalid Court References**: Bookings with non-existent courts
- **Zero/Negative Prices**: Cannot determine correct pricing automatically
- **Complex Duplicates**: Multiple bookings with different characteristics

## Best Practices

1. **Backup First**: Always backup database before running in fix mode
2. **Test in Development**: Run on a copy of production data first
3. **Review Verbose Output**: Check what's being changed
4. **Run Analysis First**: Always run analysis mode before fix mode
5. **Document Changes**: Save output logs for audit trails
6. **Follow Up**: Manually review items flagged for manual review

## Integration with Existing Commands

This seeder complements the existing `status:check-consistency` command:

```bash
# Quick status check (command)
php artisan status:check-consistency

# Quick status check with fixes (command)
php artisan status:check-consistency --fix

# Comprehensive analysis (seeder)
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

**Differences:**
- The command focuses on status-related consistency
- The seeder is more comprehensive and includes pricing, attendance, waitlist, etc.
- The seeder provides more detailed output and analysis

## Technical Details

### Models Analyzed
- `Booking`
- `CartTransaction`
- `CartItem`
- `BookingWaitlist`
- `WaitlistCartTransaction`
- `WaitlistCartItem`
- `PosSale`
- `PosSaleItem`
- `Court`
- `Sport`
- `User`

### Database Tables Affected
When in fix mode, the seeder may update:
- `bookings`
- `cart_transactions`
- `cart_items`
- `booking_waitlists`
- `waitlist_cart_transactions`
- `waitlist_cart_items`
- `pos_sales`

### Performance Considerations
- Large databases may take several minutes to analyze
- Uses eager loading to minimize queries
- Processes records in batches where possible
- Memory-efficient for large datasets

## Troubleshooting

### Issue: Seeder Takes Too Long
**Solution**: This is normal for large databases. Consider:
- Running during off-peak hours
- Increasing PHP memory limit
- Running specific checks separately

### Issue: Too Many Manual Review Items
**Solution**: Some issues genuinely need human review:
- Review orphaned records individually
- Verify pricing discrepancies with business rules
- Check foreign key issues against source data

### Issue: Fixes Not Applied
**Solution**: Ensure:
- Fix mode is enabled when prompted
- You have database write permissions
- No foreign key constraints are preventing updates

## Support

For issues or questions:
1. Check the verbose output for detailed error messages
2. Review the documentation for specific checks
3. Consult the development team for manual review items
4. Report bugs with the output log attached

## Changelog

### Version 1.0.0
- Initial release with 10 consistency checks
- Support for bookings, waitlist, and POS data
- Interactive mode with fix confirmation
- Comprehensive reporting and logging
