# Data Consistency Analyzer & Fixer

> A comprehensive seeder that analyzes and fixes inconsistent data across bookings, waitlist, and POS systems.

## 🎯 What It Does

Analyzes and fixes 10 types of data inconsistencies:

1. ✅ **Booking Status** - Syncs booking status with cart transaction approval
2. 💰 **Payment Data** - Ensures payment info is consistent across records
3. ⏳ **Waitlist** - Validates waitlist entries and positions
4. 🛒 **Cart Items** - Syncs cart items with transactions
5. 🏪 **POS Sales** - Validates POS calculations and references
6. 👥 **Attendance** - Fixes scan counts and check-in data
7. 🔗 **Foreign Keys** - Validates relationships between tables
8. 🔍 **Orphaned Data** - Finds records with broken references
9. 💵 **Pricing** - Recalculates totals and validates amounts
10. 🔄 **Duplicates** - Detects and removes duplicate bookings

## 🚀 Quick Start

### Run Analysis (Safe, No Changes)
```bash
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```
Choose `no` when asked about fix mode.

### Run with Automatic Fixes
```bash
# 1. Backup first!
mysqldump -u user -p database > backup.sql

# 2. Run seeder
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```
Choose `yes` when asked about fix mode.

## 📊 What You'll See

```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 21
Issues Fixed: 19
Issues Requiring Manual Review: 2

✓ Automated fixes have been applied.
```

## 📖 Documentation

- **Full Documentation**: [docs/DATA_CONSISTENCY_SEEDER.md](docs/DATA_CONSISTENCY_SEEDER.md)
- **Quick Reference**: [QUICK_REFERENCE_DATA_CONSISTENCY.md](QUICK_REFERENCE_DATA_CONSISTENCY.md)
- **Example Output**: [docs/EXAMPLE_SEEDER_OUTPUT.md](docs/EXAMPLE_SEEDER_OUTPUT.md)
- **Source Code**: [database/seeders/DataConsistencyAnalyzerSeeder.php](database/seeders/DataConsistencyAnalyzerSeeder.php)

## 🔧 Common Use Cases

### Scenario 1: Weekly Maintenance
```bash
# Every week, check for issues
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: no (analysis only)
```

### Scenario 2: After Data Import
```bash
# Import data...
# Then analyze and fix
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
# Select: yes (fix mode)
```

### Scenario 3: Before Important Events
```bash
# Ensure data is clean before audits/reports
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

## 🛡️ Safety Features

- ✅ Analysis-only mode (default)
- ✅ Requires confirmation before fixes
- ✅ Non-destructive (updates, never deletes)
- ✅ Detailed logging of all changes
- ✅ Identifies issues needing manual review

## 📋 Checks Performed

### Booking Status Consistency
- ✓ Approved transactions have approved bookings
- ✓ Rejected transactions have rejected bookings
- ✓ Status matches transaction approval_status

### Payment Consistency
- ✓ Paid transactions have paid bookings
- ✓ All paid bookings have paid_at timestamp
- ✓ Attendance requires payment completion
- ✓ Payment method and status are set

### Waitlist Consistency
- ✓ Converted waitlist entries have bookings
- ✓ Notified entries have expiration times
- ✓ Expired entries are marked correctly
- ✓ Positions are sequential and unique

### Cart & Transaction Consistency
- ✓ Approved transactions have bookings created
- ✓ Cart item status matches transaction
- ✓ All relationships are properly linked

### POS Sales Consistency
- ✓ Sale references point to valid bookings
- ✓ Total amounts match item calculations
- ✓ Subtotals are correct

### Attendance Consistency
- ✓ Scan counts don't exceed player count
- ✓ Checked-in bookings have timestamps
- ✓ Status reflects scan activity

### Foreign Key Integrity
- ✓ User IDs reference existing users
- ✓ Court IDs reference existing courts
- ✓ Sport IDs reference existing sports

### Orphaned Records
- ✓ Bookings link to valid transactions
- ✓ Cart items link to valid transactions
- ✓ No broken relationships

### Price Consistency
- ✓ Transaction totals match cart items
- ✓ Booking amounts are calculated correctly
- ✓ POS amounts are included in totals
- ✓ No zero or negative prices

### Duplicate Detection
- ✓ Same court/time/user bookings
- ✓ Keeps first, cancels duplicates
- ✓ Adds admin notes to cancelled bookings

## ⚙️ Integration

### With Existing Commands
Works alongside existing consistency tools:

```bash
# Quick check (lighter)
php artisan status:check-consistency

# Comprehensive check (thorough)
php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Scheduled Automation (Optional)
Add to `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule)
{
    // Weekly analysis report
    $schedule->command('db:seed', [
        '--class' => 'DataConsistencyAnalyzerSeeder'
    ])->weeklyOn(1, '01:00');
}
```

## 🔍 Output Legend

| Symbol | Meaning |
|--------|---------|
| ✗ | Issue found |
| ✓ | Successfully fixed |
| ⚠ | Needs manual review |

## 📈 When to Run

### Required
- ✅ After data imports
- ✅ After major system updates
- ✅ Before audits or reports

### Recommended
- ✅ Weekly maintenance checks
- ✅ After bug fixes affecting data
- ✅ When users report inconsistencies

### Optional
- ✅ Daily automated checks (analysis only)
- ✅ Before/after major events
- ✅ As part of CI/CD pipeline

## 🎓 Best Practices

1. **Always backup before running fix mode**
   ```bash
   mysqldump -u user -p database > backup_$(date +%Y%m%d).sql
   ```

2. **Run analysis first, then fixes**
   ```bash
   # Step 1: Analyze
   php artisan db:seed --class=DataConsistencyAnalyzerSeeder
   # (choose 'no' for fix mode)

   # Step 2: Review output

   # Step 3: Fix
   php artisan db:seed --class=DataConsistencyAnalyzerSeeder
   # (choose 'yes' for fix mode)
   ```

3. **Save output for records**
   ```bash
   php artisan db:seed --class=DataConsistencyAnalyzerSeeder > logs/consistency_$(date +%Y%m%d).log 2>&1
   ```

4. **Test on staging first**
   - Copy production data to staging
   - Run seeder on staging
   - Verify results
   - Apply to production

5. **Monitor patterns**
   - Track issue types over time
   - Identify recurring problems
   - Fix root causes

## 🐛 Troubleshooting

### Seeder Takes Too Long
- Normal for large databases
- Run during off-peak hours
- Increase PHP memory limit if needed

### Many Manual Review Items
- Some issues need human judgment
- Review each case individually
- Document decisions

### Fixes Don't Apply
- Check database permissions
- Verify foreign key constraints
- Review error messages

## 📞 Support

Need help?
1. Check verbose output for details
2. Review documentation files
3. Contact development team
4. Report bugs with output logs

## 📝 Version

**Current Version**: 1.0.0
**Last Updated**: 2025-11-16
**Status**: Production Ready

## 🤝 Contributing

Found an issue or have a suggestion?
1. Document the issue with examples
2. Propose the fix logic
3. Test on sample data
4. Submit for review

## 📄 License

Part of the INGCOPH Schedule Management System.

---

## Quick Reference Card

```bash
# Analysis only (safe)
php artisan db:seed --class=DataConsistencyAnalyzerSeeder

# With fixes (backup first!)
php artisan db:seed --class=DataConsistencyAnalyzerSeeder

# Save output to file
php artisan db:seed --class=DataConsistencyAnalyzerSeeder > output.log 2>&1

# Check existing tool
php artisan status:check-consistency

# Fix with existing tool
php artisan status:check-consistency --fix
```

**Remember**: Always backup before running in fix mode! 🔐
