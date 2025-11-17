# Example: Data Consistency Seeder Output

## Example 1: Analysis Only (No Fixes)

```bash
$ php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Output:
```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

 Do you want to automatically fix issues? (yes/no) [no]:
 > no

 Enable verbose output? (yes/no) [yes]:
 > yes

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Booking Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #123: Status 'pending' but transaction is 'approved'
  ✗ Booking #124: Status 'pending' but transaction is 'approved'
  ✗ Booking #125: Status 'approved' but transaction is 'pending'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. Payment Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #126: Payment status 'unpaid' but transaction is 'paid'
  ✗ Booking #127: Marked as 'paid' but no paid_at timestamp
  ✗ Booking #128: Attendance 'showed_up' but payment status is 'unpaid'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. Waitlist Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Waitlist #45: Status 'converted' but no booking found
  ✗ Waitlist #46: Status 'notified' but no expires_at set
  ✗ Waitlist: Duplicate position 1 for court 3 at 2025-11-16 10:00:00

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. Cart Transaction & Cart Item Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Transaction #50: Approved but no bookings created (2 cart items)
  ✗ Cart Item #89: Status 'pending' but transaction status is 'approved'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
5. POS Sales Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ POS Sale #12: Total amount mismatch (Calculated: 250.00, Stored: 248.50)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
6. Attendance Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #130: Scan count (5) exceeds players (4)
  ✗ Booking #131: Status 'checked_in' but no checked_in_at timestamp

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
7. Foreign Key Integrity
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #132: References non-existent user #999

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
8. Orphaned Records
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #133: References non-existent cart transaction #888
  ✗ Cart Item #90: References non-existent cart transaction #777

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
9. Price Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Transaction #51: Booking amount mismatch (Calculated: 500.00, Stored: 450.00)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
10. Duplicate Bookings Detection
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Duplicate bookings found: Court 2, User 5, Time 2025-11-17 14:00:00 (3 bookings)


╔════════════════════════════════════════════════════════════╗
║                    SUMMARY REPORT                          ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 21

⚠ Issues detected. Run again with fix mode to attempt automatic repairs.

Analysis complete!
```

---

## Example 2: Analysis with Fixes

```bash
$ php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Output:
```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

 Do you want to automatically fix issues? (yes/no) [no]:
 > yes

 Enable verbose output? (yes/no) [yes]:
 > yes

⚠️  FIX MODE ENABLED - Changes will be made to the database

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Booking Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #123: Status 'pending' but transaction is 'approved'
  ✓ Fixed: Set booking status to 'approved'
  ✗ Booking #124: Status 'pending' but transaction is 'approved'
  ✓ Fixed: Set booking status to 'approved'
  ✗ Booking #125: Status 'approved' but transaction is 'pending'
  ✓ Fixed: Set booking status to 'pending'

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. Payment Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #126: Payment status 'unpaid' but transaction is 'paid'
  ✓ Fixed: Synced payment data from transaction
  ✗ Booking #127: Marked as 'paid' but no paid_at timestamp
  ✓ Fixed: Set paid_at to 2025-11-15 14:30:00
  ✗ Booking #128: Attendance 'showed_up' but payment status is 'unpaid'
  ✓ Fixed: Reset attendance_status (payment required first)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. Waitlist Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Waitlist #45: Status 'converted' but no booking found
  ✓ Fixed: Changed status to 'expired'
  ✗ Waitlist #46: Status 'notified' but no expires_at set
  ✓ Fixed: Set expires_at to 2025-11-16 15:00:00
  ✗ Waitlist: Duplicate position 1 for court 3 at 2025-11-16 10:00:00
  ✓ Fixed: Reordered 3 waitlist positions

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. Cart Transaction & Cart Item Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Transaction #50: Approved but no bookings created (2 cart items)
  ✓ Fixed: Created 2 booking(s)
  ✗ Cart Item #89: Status 'pending' but transaction status is 'approved'
  ✓ Fixed: Synced cart item status

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
5. POS Sales Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ POS Sale #12: Total amount mismatch (Calculated: 250.00, Stored: 248.50)
  ✓ Fixed: Recalculated totals

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
6. Attendance Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #130: Scan count (5) exceeds players (4)
  ✓ Fixed: Capped scan count to number of players
  ✗ Booking #131: Status 'checked_in' but no checked_in_at timestamp
  ✓ Fixed: Set checked_in_at timestamp

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
7. Foreign Key Integrity
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #132: References non-existent user #999
  ✓ Fixed: Assigned to admin user #1

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
8. Orphaned Records
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Booking #133: References non-existent cart transaction #888
  ⚠ Manual review required: Orphaned booking needs investigation
  ✗ Cart Item #90: References non-existent cart transaction #777
  ⚠ Manual review required: Orphaned cart item

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
9. Price Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Transaction #51: Booking amount mismatch (Calculated: 500.00, Stored: 450.00)
  ✓ Fixed: Recalculated booking and total amounts

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
10. Duplicate Bookings Detection
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
  ✗ Duplicate bookings found: Court 2, User 5, Time 2025-11-17 14:00:00 (3 bookings)
  ✓ Fixed: Kept booking #140, cancelled 2 duplicate(s)


╔════════════════════════════════════════════════════════════╗
║                    SUMMARY REPORT                          ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 21
Issues Fixed: 19
Issues Requiring Manual Review: 2

✓ Automated fixes have been applied.
⚠ Some issues require manual review. Please check the output above.

Analysis complete!
```

---

## Example 3: No Issues Found (Healthy Database)

```bash
$ php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Output:
```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

 Do you want to automatically fix issues? (yes/no) [no]:
 > no

 Enable verbose output? (yes/no) [yes]:
 > yes

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Booking Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. Payment Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. Waitlist Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
4. Cart Transaction & Cart Item Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
5. POS Sales Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
6. Attendance Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
7. Foreign Key Integrity
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
8. Orphaned Records
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
9. Price Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
10. Duplicate Bookings Detection
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━


╔════════════════════════════════════════════════════════════╗
║                    SUMMARY REPORT                          ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 0

✓ No data inconsistencies detected! Your database is in good shape.

Analysis complete!
```

---

## Example 4: Non-Verbose Mode

```bash
$ php artisan db:seed --class=DataConsistencyAnalyzerSeeder
```

### Output (Less Detailed):
```
╔════════════════════════════════════════════════════════════╗
║     DATA CONSISTENCY ANALYZER & FIXER                      ║
╚════════════════════════════════════════════════════════════╝

 Do you want to automatically fix issues? (yes/no) [no]:
 > yes

 Enable verbose output? (yes/no) [yes]:
 > no

⚠️  FIX MODE ENABLED - Changes will be made to the database

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Booking Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
2. Payment Status Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
3. Waitlist Data Consistency
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

...

╔════════════════════════════════════════════════════════════╗
║                    SUMMARY REPORT                          ║
╚════════════════════════════════════════════════════════════╝

Total Issues Found: 21
Issues Fixed: 19
Issues Requiring Manual Review: 2

✓ Automated fixes have been applied.
⚠ Some issues require manual review. Please check the output above.

Analysis complete!
```

---

## Interpreting Results

### Issue Severity

| Symbol | Meaning | Action |
|--------|---------|--------|
| ✗ | Issue detected | Review and decide on fix |
| ✓ | Successfully fixed | Verify the fix was correct |
| ⚠ | Needs manual review | Investigate and fix manually |

### Common Issue Patterns

1. **After Data Import**
   - Expect many foreign key issues
   - Status inconsistencies common
   - Price mismatches possible

2. **After Bug Fixes**
   - Specific to the bug fixed
   - Usually isolated to one category
   - Should decrease over time

3. **During Normal Operations**
   - Few issues expected
   - Mostly edge cases
   - Sign of good data hygiene if zero issues

### When to Be Concerned

- **High number of orphaned records**: Indicates deletion bugs
- **Many payment inconsistencies**: Possible payment processing issues
- **Duplicate bookings**: Concurrency or validation problems
- **Foreign key issues**: Data import or deletion logic problems

### Next Steps After Running

1. **If 0 issues**: Database is healthy! ✓
2. **If <10 issues**: Normal, run fixes and monitor
3. **If 10-50 issues**: Review pattern, fix, and investigate root cause
4. **If >50 issues**: Major data integrity problem, needs investigation

---

**Related Documents:**
- Full Documentation: `docs/DATA_CONSISTENCY_SEEDER.md`
- Quick Reference: `QUICK_REFERENCE_DATA_CONSISTENCY.md`
- Source Code: `database/seeders/DataConsistencyAnalyzerSeeder.php`
