# Quick Reference: Data Consistency Seeder

## Quick Start

### Run Analysis (Safe, No Changes)
```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```
- Choose `no` when asked about fix mode
- This only analyzes and reports issues

### Run with Automatic Fixes
```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```
- Choose `yes` when asked about fix mode
- ⚠️ **Backup database first!**

## Common Scenarios

### Scenario 1: After Importing Data
```bash
# 1. Backup database
php artisan backup:database  # or your backup method

# 2. Run analysis
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: no (analysis only)

# 3. Review output, then run fixes
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: yes (fix mode)
```

### Scenario 2: Weekly Maintenance Check
```bash
# Just run analysis to check for issues
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: no, yes (analysis only, verbose output)

# If issues found, review and then fix
```

### Scenario 3: Before Important Events/Audits
```bash
# 1. Run comprehensive analysis
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: no, yes

# 2. If issues found, backup and fix
mysqldump -u user -p database > backup_$(date +%Y%m%d).sql
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: yes, yes

# 3. Verify fixes
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: no, yes (should show 0 issues)
```

### Scenario 4: After Bug Fixes
```bash
# Fix data affected by the bug
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: yes, yes

# Save the output log
php artisan db:seed --class=DataConsistencyAnalyzerSeeder > consistency_fix_$(date +%Y%m%d).log
```

## What Gets Checked?

| Category | Examples |
|----------|----------|
| **Booking Status** | Approved transactions with pending bookings |
| **Payment Data** | Paid transactions with unpaid bookings |
| **Waitlist** | Converted waitlist without bookings |
| **Cart Items** | Cart items status mismatch with transaction |
| **POS Sales** | Total amount calculation errors |
| **Attendance** | Scan counts exceeding player count |
| **Foreign Keys** | Bookings referencing deleted users/courts |
| **Orphaned Data** | Records pointing to non-existent parents |
| **Pricing** | Transaction totals not matching items |
| **Duplicates** | Same court, time, user booked multiple times |

## Understanding the Output

### ✗ Red (Error)
```
✗ Booking #123: Status 'pending' but transaction is 'approved'
```
Issue found that can be fixed.

### ✓ Green (Success)
```
✓ Fixed: Set booking status to 'approved'
```
Issue was automatically fixed.

### ⚠ Yellow (Warning)
```
⚠ Manual review required: Orphaned booking needs investigation
```
Issue needs human review, cannot auto-fix.

### → Gray (Info)
Detailed information about the fix process.

## Summary Report Interpretation

```
Total Issues Found: 47
Issues Fixed: 45
Issues Requiring Manual Review: 2
```

- **Total Issues Found**: All inconsistencies detected
- **Issues Fixed**: Automatically repaired issues
- **Manual Review**: Issues requiring human intervention

## Manual Review Items

These require investigation:

1. **Orphaned Bookings**: Check if transaction was deleted intentionally
2. **Invalid Court References**: Verify court IDs against source data
3. **Zero Prices**: Confirm with business rules what price should be
4. **Complex Duplicates**: Decide which booking to keep based on payment status

## Safety Tips

✅ **DO:**
- Backup before running fix mode
- Run analysis first
- Review verbose output
- Save output logs
- Run during low-traffic periods

❌ **DON'T:**
- Run fix mode without backup
- Skip the analysis step
- Run during peak hours without testing
- Ignore manual review items

## Troubleshooting

### "Too many issues found"
- This is common after major updates
- Run analysis to review them first
- Fix in batches if needed

### "Seeder is slow"
- Normal for large databases
- Run during off-peak hours
- Consider increasing PHP memory

### "Some fixes didn't work"
- Check database permissions
- Review foreign key constraints
- Some issues need manual fixes

## Integration with Existing Tools

### Use with Status Check Command
```bash
# Quick check (lighter)
php artisan status:check-consistency

# Comprehensive check (more thorough)
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Automation (Optional)
Add to your cron/scheduler for weekly reports:
```php
// app/Console/Kernel.php
$schedule->command('db:seed', [
    '--class' => 'DataConsistencyAnalyzerSeeder'
])->weeklyOn(1, '01:00');
```

## Output Logs

### Save Output to File
```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder > logs/consistency_$(date +%Y%m%d_%H%M%S).log 2>&1
```

### Search Logs for Specific Issues
```bash
# Find all booking status issues
grep "Booking #" logs/consistency_*.log

# Find all payment issues
grep -i "payment" logs/consistency_*.log

# Count issues by type
grep "✗" logs/consistency_*.log | wc -l
```

## Best Practices

### Monthly Routine
1. **Week 1**: Run analysis only
2. **Week 2**: Review any issues found
3. **Week 3**: Backup and run fixes
4. **Week 4**: Verify all issues resolved

### Before Production Deploy
```bash
# 1. Test on staging with production data copy
# 2. Run analysis
# 3. Document issues found
# 4. Fix on production with backup
# 5. Verify fixes worked
```

### After Major Features
New features involving bookings, payments, or waitlist:
1. Test feature thoroughly
2. Run consistency check after testing
3. Document any new data patterns
4. Update seeder if needed

## Need Help?

1. **Check Documentation**: `docs/DATA_CONSISTENCY_SEEDER.md`
2. **Review Code**: `database/seeders/DataConsistencyAnalyzerSeeder.php`
3. **Check Existing Commands**: `php artisan status:check-consistency`
4. **Contact Development Team**: For manual review items

## Related Commands

```bash
# Other consistency tools
php artisan status:check-consistency          # Quick status check
php artisan status:check-consistency --fix    # Quick fix

# Database operations
php artisan migrate:fresh --seed              # Reset database
php artisan db:seed                           # Run all seeders

# Maintenance
php artisan cache:clear                       # Clear cache
php artisan queue:restart                     # Restart queue workers
```

## Version History

- **v1.0.0** (Current): Initial release with 10 consistency checks
- Supports: Bookings, Waitlist, POS, Cart Transactions, Payments, Attendance

---

**Last Updated**: 2025-11-16
**Maintainer**: Development Team
**Status**: Production Ready
