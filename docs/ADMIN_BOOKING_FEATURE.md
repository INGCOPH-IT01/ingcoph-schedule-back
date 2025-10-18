# Admin Booking Feature Documentation

## Overview
Admins can now create bookings on behalf of other users, either selecting from existing registered users or typing custom names for walk-in customers.

## Frontend Changes

### NewBookingDialog.vue

#### 1. New Fields Added
```javascript
const bookingForUser = ref(null)  // Can be user object or string
const adminNotes = ref('')         // Internal admin notes
const userNames = ref([])          // List of users from database
```

#### 2. User Combo Box
```vue
<v-combobox
  v-model="bookingForUser"
  :items="userNames"
  label="Booking for (Select or Type User Name)"
  placeholder="Select from list or type a name"
  hint="Select existing user or type a custom name (e.g., walk-in customer)"
  clearable
  return-object
>
```

**Features:**
- ✅ Select from dropdown of existing users
- ✅ Type custom name for walk-in customers
- ✅ Shows user avatars and emails for existing users
- ✅ Shows orange "+" icon for custom names
- ✅ Supports both user objects and plain text strings

#### 3. Data Submission
Both `submitBookingWithGCash()` and `submitBooking()` functions now include:

```javascript
const adminBookingData = {}
if (isAdmin.value && bookingForUser.value) {
  // If bookingForUser is an object (existing user)
  if (typeof bookingForUser.value === 'object' && bookingForUser.value.id) {
    adminBookingData.booking_for_user_id = bookingForUser.value.id
    adminBookingData.booking_for_user_name = bookingForUser.value.name
  } 
  // If bookingForUser is a string (custom name)
  else {
    adminBookingData.booking_for_user_name = bookingForUser.value
  }
  
  if (adminNotes.value) {
    adminBookingData.admin_notes = adminNotes.value
  }
}
```

## Backend Changes

### 1. Database Migration
**File:** `2025_10_16_005518_add_admin_booking_fields_to_bookings_table.php`

```php
Schema::table('bookings', function (Blueprint $table) {
    $table->foreignId('booking_for_user_id')
        ->nullable()
        ->after('user_id')
        ->constrained('users')
        ->onDelete('set null');
    
    $table->string('booking_for_user_name')
        ->nullable()
        ->after('booking_for_user_id');
    
    $table->text('admin_notes')
        ->nullable()
        ->after('notes');
});
```

### 2. Booking Model Updates
**File:** `app/Models/Booking.php`

**Fillable Fields Added:**
```php
'booking_for_user_id',
'booking_for_user_name',
'admin_notes',
```

**New Relationship:**
```php
public function bookingForUser(): BelongsTo
{
    return $this->belongsTo(User::class, 'booking_for_user_id');
}
```

## Database Schema

### bookings Table Fields

| Field | Type | Nullable | Description |
|-------|------|----------|-------------|
| `user_id` | bigint | No | Admin who created the booking |
| `booking_for_user_id` | bigint | Yes | Registered user ID (if selected from dropdown) |
| `booking_for_user_name` | string | Yes | User name (from dropdown or custom typed) |
| `admin_notes` | text | Yes | Internal admin notes |

## Use Cases

### 1. Booking for Registered User
**Admin Action:** Select "John Doe" from dropdown

**Data Stored:**
```php
[
    'user_id' => 1,                    // Admin ID
    'booking_for_user_id' => 5,        // John Doe's ID
    'booking_for_user_name' => 'John Doe',
    'admin_notes' => 'VIP customer',
    // ... other booking fields
]
```

### 2. Booking for Walk-in Customer
**Admin Action:** Type "Walk-in Customer - Jane Smith"

**Data Stored:**
```php
[
    'user_id' => 1,                    // Admin ID
    'booking_for_user_id' => null,     // No user ID
    'booking_for_user_name' => 'Walk-in Customer - Jane Smith',
    'admin_notes' => 'Paid cash',
    // ... other booking fields
]
```

### 3. Booking for Self
**Admin Action:** Leave "Booking for" field empty

**Data Stored:**
```php
[
    'user_id' => 1,                    // Admin ID
    'booking_for_user_id' => null,
    'booking_for_user_name' => null,
    'admin_notes' => null,
    // ... other booking fields
]
```

## API Endpoints

### GET /api/admin/users
Fetches list of all users for the combo box dropdown.

**Response:**
```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "John Doe",
            "email": "john@example.com",
            "role": "user"
        },
        {
            "id": 2,
            "name": "Jane Smith",
            "email": "jane@example.com",
            "role": "user"
        }
    ]
}
```

## Frontend Payload Examples

### GCash Checkout
```javascript
{
    payment_method: 'gcash',
    gcash_reference: 'GC123456789',
    proof_of_payment: 'data:image/jpeg;base64,...',
    selected_items: [1, 2, 3],
    booking_for_user_id: 5,           // Optional
    booking_for_user_name: 'John Doe', // Optional
    admin_notes: 'VIP customer'        // Optional
}
```

### Direct Booking
```javascript
{
    court_id: 1,
    start_time: '2025-10-16 10:00:00',
    end_time: '2025-10-16 11:00:00',
    booking_for_user_id: 5,           // Optional
    booking_for_user_name: 'John Doe', // Optional
    admin_notes: 'VIP customer'        // Optional
}
```

## Visual Indicators

### Combo Box Display

**Existing User:**
```
[👤 Blue Avatar] John Doe
```

**Custom Name:**
```
[➕ Orange Icon] Walk-in Customer
```

## Security

- ✅ Only users with `role === 'admin'` can see these fields
- ✅ Admin booking fields are only shown when `isAdmin.value === true`
- ✅ Fields are optional - admins can still book for themselves
- ✅ User ID validation via foreign key constraint
- ✅ Separate fields for user ID (validated) and name (flexible)

## Benefits

1. **Flexibility:** Handle both registered and walk-in customers
2. **Traceability:** Track which admin created the booking
3. **Customer Service:** Better support for phone/in-person bookings
4. **Data Integrity:** Maintain relationship to registered users when available
5. **Notes:** Internal documentation for special cases

## Testing Checklist

- [ ] Admin can select existing user from dropdown
- [ ] Admin can type custom name
- [ ] Admin can leave field empty (booking for self)
- [ ] Booking is created with correct data
- [ ] User avatars display correctly
- [ ] Custom names show orange icon
- [ ] Admin notes are saved
- [ ] Non-admin users don't see these fields
- [ ] Database relationships work correctly
- [ ] Foreign key constraint handles user deletion gracefully (set null)

