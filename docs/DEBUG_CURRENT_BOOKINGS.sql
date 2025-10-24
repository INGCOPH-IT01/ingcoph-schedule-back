-- ============================================
-- DEBUG: Check Current Bookings and Related Records
-- ============================================

-- STEP 1: Find the two bookings you mentioned
-- ============================================
SELECT
    b.id as booking_id,
    b.user_id,
    u.name as user_name,
    u.email,
    b.court_id,
    c.name as court_name,
    b.start_time,
    b.end_time,
    b.status as booking_status,
    b.notes,
    b.cart_transaction_id,
    b.created_at,
    b.updated_at
FROM bookings b
JOIN users u ON b.user_id = u.id
JOIN courts c ON b.court_id = c.id
ORDER BY b.created_at DESC
LIMIT 10;

-- STEP 2: Check waitlist entries linked to these bookings
-- ============================================
SELECT
    w.id as waitlist_id,
    w.user_id,
    u.name as waitlist_user_name,
    w.pending_booking_id,
    w.status as waitlist_status,
    w.court_id,
    w.start_time,
    w.end_time,
    w.notified_at,
    w.expires_at,
    w.created_at,
    w.updated_at
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
ORDER BY w.created_at DESC
LIMIT 10;

-- STEP 3: Find waitlists linked to specific bookings
-- Replace [BOOKING_ID_1] and [BOOKING_ID_2] with actual IDs
-- ============================================
SELECT
    'Waitlist for Booking' as type,
    w.id,
    w.pending_booking_id,
    w.status,
    w.user_id,
    u.name as user_name,
    w.updated_at
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
WHERE w.pending_booking_id IN ([BOOKING_ID_1], [BOOKING_ID_2]);

-- STEP 4: Check if there are auto-created bookings for waitlisted users
-- ============================================
SELECT
    b.id as auto_booking_id,
    b.user_id,
    u.name as user_name,
    b.status,
    b.notes,
    b.cart_transaction_id,
    b.start_time,
    b.end_time,
    b.created_at,
    b.updated_at
FROM bookings b
JOIN users u ON b.user_id = u.id
WHERE b.notes LIKE '%Auto-created from waitlist%'
ORDER BY b.created_at DESC
LIMIT 10;

-- STEP 5: Check cart transactions related to these bookings
-- ============================================
SELECT
    ct.id as transaction_id,
    ct.user_id,
    u.name as user_name,
    ct.approval_status,
    ct.payment_status,
    ct.rejection_reason,
    ct.total_price,
    ct.created_at,
    ct.updated_at
FROM cart_transactions ct
JOIN users u ON ct.user_id = u.id
WHERE ct.id IN (
    SELECT DISTINCT cart_transaction_id
    FROM bookings
    WHERE cart_transaction_id IS NOT NULL
    ORDER BY created_at DESC
    LIMIT 10
)
ORDER BY ct.created_at DESC;

-- STEP 6: Check cart items
-- ============================================
SELECT
    ci.id as cart_item_id,
    ci.cart_transaction_id,
    ci.status as item_status,
    ci.notes,
    ci.court_id,
    c.name as court_name,
    ci.booking_date,
    ci.start_time,
    ci.end_time,
    ci.price,
    ci.created_at,
    ci.updated_at
FROM cart_items ci
JOIN courts c ON ci.court_id = c.id
WHERE ci.cart_transaction_id IN (
    SELECT DISTINCT cart_transaction_id
    FROM bookings
    WHERE cart_transaction_id IS NOT NULL
    ORDER BY created_at DESC
    LIMIT 10
)
ORDER BY ci.created_at DESC;

-- STEP 7: Complete relationship check for a specific booking
-- Replace [PARENT_BOOKING_ID] with the parent booking that was approved
-- ============================================
SET @parent_booking_id = [PARENT_BOOKING_ID];

SELECT 'PARENT BOOKING' as record_type,
    b.id,
    b.status,
    b.user_id,
    u.name as user_name,
    b.court_id,
    c.name as court_name,
    b.start_time,
    b.end_time,
    b.updated_at
FROM bookings b
JOIN users u ON b.user_id = u.id
JOIN courts c ON b.court_id = c.id
WHERE b.id = @parent_booking_id

UNION ALL

SELECT 'WAITLIST ENTRY' as record_type,
    w.id,
    w.status,
    w.user_id,
    u.name as user_name,
    w.court_id,
    c.name as court_name,
    w.start_time,
    w.end_time,
    w.updated_at
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
JOIN courts c ON w.court_id = c.id
WHERE w.pending_booking_id = @parent_booking_id

UNION ALL

SELECT 'AUTO-CREATED BOOKING' as record_type,
    b2.id,
    b2.status,
    b2.user_id,
    u2.name as user_name,
    b2.court_id,
    c2.name as court_name,
    b2.start_time,
    b2.end_time,
    b2.updated_at
FROM bookings b2
JOIN users u2 ON b2.user_id = u2.id
JOIN courts c2 ON b2.court_id = c2.id
WHERE b2.notes LIKE '%Auto-created from waitlist%'
AND b2.user_id IN (
    SELECT user_id FROM booking_waitlists WHERE pending_booking_id = @parent_booking_id
)
AND b2.court_id = (SELECT court_id FROM bookings WHERE id = @parent_booking_id)
AND b2.start_time = (SELECT start_time FROM bookings WHERE id = @parent_booking_id)
AND b2.end_time = (SELECT end_time FROM bookings WHERE id = @parent_booking_id);

-- STEP 8: Check for orphaned records that should have been cancelled
-- ============================================
SELECT
    'PROBLEM: Waitlist cancelled but booking NOT rejected' as issue,
    w.id as waitlist_id,
    w.status as waitlist_status,
    b.id as booking_id,
    b.status as booking_status,
    b.notes
FROM booking_waitlists w
JOIN bookings b ON (
    b.user_id = w.user_id
    AND b.court_id = w.court_id
    AND b.start_time = w.start_time
    AND b.end_time = w.end_time
    AND b.notes LIKE '%Auto-created from waitlist%'
)
WHERE w.status = 'cancelled'
AND b.status NOT IN ('rejected', 'cancelled')
ORDER BY w.updated_at DESC
LIMIT 10;

-- STEP 9: Check recent log-worthy activity
-- ============================================
SELECT
    'Recent Approvals' as activity_type,
    b.id,
    b.status,
    b.updated_at,
    u.name as user_name
FROM bookings b
JOIN users u ON b.user_id = u.id
WHERE b.status = 'approved'
AND b.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)

UNION ALL

SELECT
    'Recent Waitlist Cancellations' as activity_type,
    w.id,
    w.status,
    w.updated_at,
    u.name as user_name
FROM booking_waitlists w
JOIN users u ON w.user_id = u.id
WHERE w.status = 'cancelled'
AND w.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)

UNION ALL

SELECT
    'Recent Booking Rejections' as activity_type,
    b.id,
    b.status,
    b.updated_at,
    u.name as user_name
FROM bookings b
JOIN users u ON b.user_id = u.id
WHERE b.status = 'rejected'
AND b.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)

ORDER BY updated_at DESC;
