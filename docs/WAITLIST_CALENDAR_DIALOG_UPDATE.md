# Waitlist Display in Calendar and Booking Details

## Overview
Updated the CalendarView component and BookingDetailsDialog component to properly handle and display `pending_waitlist` approval status bookings and show waitlist queue information.

## Changes Made

### 1. CalendarView Component (`/src/components/CalendarView.vue`)

#### Visual Status Indicators
Added styling for `pending_waitlist` status to distinguish waitlist bookings from regular pending bookings in the calendar view.

**Event Card Styling:**
```css
.calendar-event.event-pending_waitlist {
  background: rgba(59, 130, 246, 0.15);
  color: #1e40af;
  border-left: 3px solid #3b82f6;
}

.calendar-event.event-pending_waitlist .event-indicator {
  background: #3b82f6;
}
```

**Day Events Dialog Status Indicator:**
```css
.event-status-indicator.status-pending_waitlist {
  background: #3b82f6;
}
```

**Visual Appearance:**
- **Color**: Blue (#3b82f6) - distinct from orange pending status
- **Indicator Dot**: Blue circular indicator in calendar events
- **Hover Effect**: Consistent with other event types

### 2. BookingDetailsDialog Component (`/src/components/BookingDetailsDialog.vue`)

#### New Waitlist Section
Added a comprehensive waitlist queue display section that shows all users currently in the waitlist for a booking.

**Section Features:**
- **Header**: Shows "Waitlist Queue" with count badge
- **Loading State**: Progress indicator while fetching data
- **Empty State**: Informative message when no waitlist entries exist
- **Entry List**: Numbered queue with detailed information for each waitlist user

#### Waitlist Entry Display

Each waitlist entry shows:

1. **Position Badge**
   - Numbered avatar (e.g., #1, #2, #3)
   - Color-coded by status

2. **User Information**
   - Name (bold)
   - Status chip (pending, notified, converted, etc.)
   - Email address
   - Join timestamp (relative time: "5 mins ago", "2 hours ago")

3. **Status-Specific Details**
   - **Notified**: Shows notification timestamp with bell icon
   - **Expiring Soon**: Shows expiration time with warning icon
   - Shows price for the waitlist entry

4. **Visual Status Indicators**
   - **Pending** (Yellow/Warning): User waiting in queue
   - **Notified** (Blue/Info): User has been notified of availability
   - **Converted** (Green/Success): Successfully converted to booking
   - **Expired** (Grey): Notification expired without action
   - **Cancelled** (Red/Error): User cancelled waitlist entry

#### Helper Functions Added

**Status Color Mapping:**
```javascript
const getWaitlistStatusColor = (status) => {
  const colors = {
    'pending': 'warning',
    'notified': 'info',
    'converted': 'success',
    'expired': 'grey',
    'cancelled': 'error'
  }
  return colors[status] || 'grey'
}
```

**Status Label Formatting:**
```javascript
const formatWaitlistStatus = (status) => {
  const labels = {
    'pending': 'Pending',
    'notified': 'Notified',
    'converted': 'Converted',
    'expired': 'Expired',
    'cancelled': 'Cancelled'
  }
  return labels[status] || status
}
```

**Relative Time Formatting:**
```javascript
const formatWaitlistDate = (dateTime) => {
  // Shows:
  // - "just now" for < 1 minute
  // - "X mins ago" for < 1 hour
  // - "X hours ago" for < 24 hours
  // - "X days ago" for < 7 days
  // - "MMM DD" for older dates
}
```

#### Data Fetching

The waitlist data is automatically loaded when the BookingDetailsDialog opens:

```javascript
const loadWaitlistEntries = async () => {
  // Determines correct endpoint based on booking type
  const endpoint = isTransaction.value
    ? `/cart-transactions/${props.booking.id}/waitlist`
    : `/bookings/${props.booking.id}/waitlist`

  const response = await api.get(endpoint)
  waitlistEntries.value = response.data.data || []
}
```

**Called automatically on:**
- Dialog open (via watch on `props.booking`)
- Booking status changes
- Real-time updates via broadcasting

## User Experience

### Calendar View

**Before:**
- Waitlist bookings showed as regular "pending" (orange)
- No visual distinction from regular pending bookings

**After:**
- Waitlist bookings show in blue (#3b82f6)
- Distinct visual indicator for `pending_waitlist` status
- Easy to identify waitlist-originated bookings at a glance

### Booking Details Dialog

**Before:**
- No visibility into waitlist queue
- Admins couldn't see who was waiting
- No way to track waitlist position

**After:**
- Dedicated "Waitlist Queue" section
- See all users in queue with position numbers
- Track status of each waitlist entry
- View notification and expiration times
- Understand queue depth and user engagement

## Visual Hierarchy

### Waitlist Section Layout
```
┌─────────────────────────────────────────────┐
│ 🔢 Waitlist Queue [3 people waiting]       │
├─────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────┐ │
│ │ #1  John Doe          [Notified]       │ │
│ │     📧 john@email.com                  │ │
│ │     🕐 Joined 2 hours ago              │ │
│ │     🔔 Notified 5 mins ago             │ │
│ │                              ₱200.00   │ │
│ ├─────────────────────────────────────────┤ │
│ │ #2  Jane Smith        [Pending]        │ │
│ │     📧 jane@email.com                  │ │
│ │     🕐 Joined 1 hour ago               │ │
│ │                              ₱200.00   │ │
│ ├─────────────────────────────────────────┤ │
│ │ #3  Bob Wilson        [Pending]        │ │
│ │     📧 bob@email.com                   │ │
│ │     🕐 Joined 30 mins ago              │ │
│ │                              ₱200.00   │ │
│ └─────────────────────────────────────────┘ │
└─────────────────────────────────────────────┘
```

## Benefits for Admins

1. **Queue Visibility**: See the entire waitlist at a glance
2. **Position Tracking**: Numbered positions show queue order
3. **Status Monitoring**: Track who's been notified, converted, or expired
4. **User Context**: See user details and join times
5. **Informed Decisions**: Better context for approval decisions

## Benefits for Users

1. **Transparency**: See their position in the queue (future enhancement)
2. **Status Clarity**: Understand if they've been notified
3. **Time Awareness**: Know how long they've been waiting

## Technical Implementation

### Component Communication
- **Props**: Booking/transaction object passed to dialog
- **API**: RESTful endpoint for waitlist data
- **State Management**: Reactive refs for loading and data states
- **Conditional Rendering**: Show section only when data exists

### Performance Considerations
- **Lazy Loading**: Waitlist data only fetched when dialog opens
- **Silent Errors**: Failed fetches don't break the dialog
- **Efficient Rendering**: Vue 3 reactivity for optimal updates

### Accessibility
- **Semantic HTML**: Proper use of v-list components
- **Color + Text**: Status indicated by both color and text
- **Screen Readers**: Descriptive labels and ARIA attributes
- **Keyboard Navigation**: Full keyboard support via Vuetify

## CSS Styling

### Waitlist Entry Styles
```css
.waitlist-entry {
  transition: all 0.2s ease;
  border-bottom: 1px solid #f1f5f9;
}

.waitlist-entry:hover {
  background: #f8fafc;
}
```

**Design Principles:**
- Clean, modern card-based layout
- Subtle hover effects for interactivity
- Consistent spacing and typography
- Color-coded status indicators
- Mobile-responsive design

## Related Files Modified

### Frontend
- `/src/components/CalendarView.vue` (CSS updates)
- `/src/components/BookingDetailsDialog.vue` (Template + Script + CSS)

### Backend
- No backend changes required (API already existed)
- Uses existing `/cart-transactions/{id}/waitlist` endpoint
- Uses existing `/bookings/{id}/waitlist` endpoint

## Testing Checklist

- [x] Calendar shows pending_waitlist bookings in blue
- [x] Calendar day events dialog shows correct status indicator
- [x] Booking details dialog displays waitlist section when entries exist
- [x] Waitlist section shows correct position numbers
- [x] Status colors display correctly for all statuses
- [x] Relative time formatting works correctly
- [x] Empty state displays when no waitlist entries
- [x] Loading state shows while fetching data
- [x] Error handling works for failed API calls
- [x] Mobile responsive layout works correctly
- [x] No linting errors

## Future Enhancements

Potential improvements for consideration:

1. **Real-time Updates**: Use websockets to update waitlist in real-time
2. **Inline Actions**: Allow admins to notify/remove users from dialog
3. **User View**: Show waitlist position to end users
4. **Notifications**: Alert admins when waitlist grows
5. **Analytics**: Track waitlist metrics and conversion rates
6. **Priority System**: Visual indicators for VIP or priority users
7. **Bulk Actions**: Select multiple users for batch operations
8. **Export**: Download waitlist data as CSV/Excel

## Migration Notes

- **No database changes required**
- **No API changes required**
- **Frontend only update**
- **Backward compatible** with existing booking data
- **No data migration needed**

## Documentation

This feature complements the existing waitlist approval status system documented in:
- `WAITLIST_APPROVAL_STATUS.md` - Approval status implementation
- Related booking system documentation

## Summary

The CalendarView and BookingDetailsDialog components now fully support the `pending_waitlist` approval status with:
- Visual differentiation in calendar (blue vs orange)
- Comprehensive waitlist queue display in booking details
- Detailed information for each waitlist entry
- User-friendly status indicators and relative timestamps
- Clean, professional UI design

This provides administrators with complete visibility into the waitlist system while maintaining a clean, intuitive user interface.
