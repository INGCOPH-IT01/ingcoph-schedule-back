# Booking Display Logic for Admin-Created Bookings

## Backend Implementation ✅

### Database Structure
The `bookings` table now has:
- `user_id` - The admin/user who created the booking
- `booking_for_user_id` - ID of registered user booking is for (nullable)
- `booking_for_user_name` - Name of user booking is for (nullable, can be custom text)
- `admin_notes` - Internal admin notes (nullable)

### Booking Model Attributes

#### 1. `display_name` Attribute
Returns the name to display for the booking:
```php
public function getDisplayNameAttribute(): string
{
    if ($this->booking_for_user_name) {
        return $this->booking_for_user_name;
    }
    return $this->user ? $this->user->name : 'Unknown';
}
```

**Usage in API responses:**
```json
{
    "id": 123,
    "user_id": 1,  // Admin ID
    "booking_for_user_id": 5,  // Actual customer ID
    "booking_for_user_name": "John Doe",
    "display_name": "John Doe",  // Auto-appended
    ...
}
```

#### 2. `effective_user` Attribute
Returns the full user object for the booking:
```php
public function getEffectiveUserAttribute()
{
    if ($this->booking_for_user_id && $this->bookingForUser) {
        return $this->bookingForUser;
    }
    return $this->user;
}
```

**Usage in API responses:**
```json
{
    "id": 123,
    "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com"
    },
    "booking_for_user": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com"
    },
    "effective_user": {  // Auto-appended
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com"
    },
    ...
}
```

### BookingController Updates

#### Updated `index()` Method
Now loads `bookingForUser` relationship and filters correctly:

```php
$query = Booking::with(['user', 'bookingForUser', 'court.sport', ...]);

// For regular users, show bookings where they are EITHER:
// 1. The user who created it (user_id) OR
// 2. The user the booking was made for (booking_for_user_id)
if (!$request->user()->isAdmin()) {
    $query->where(function($q) use ($request) {
        $q->where('user_id', $request->user()->id)
          ->orWhere('booking_for_user_id', $request->user()->id);
    });
}
```

**What this means:**
- If admin creates booking for John Doe, John Doe will see it in "My Bookings"
- Admin will also see the booking they created
- Walk-in customers (no user_id) won't see bookings, but they'll be visible to admin

## Frontend Implementation 📝

### How to Display Booking Information

When displaying bookings in the frontend, you have **3 options**:

#### Option 1: Use `display_name` (Simplest)
```vue
<template>
  <div>
    <!-- Shows "John Doe" if admin booked for John, otherwise shows creator's name -->
    <span>{{ booking.display_name }}</span>
  </div>
</template>
```

#### Option 2: Use `effective_user` (Full Object)
```vue
<template>
  <div>
    <!-- Access full user object -->
    <span>{{ booking.effective_user.name }}</span>
    <span>{{ booking.effective_user.email }}</span>
  </div>
</template>
```

#### Option 3: Manual Check (Most Control)
```vue
<template>
  <div>
    <!-- Manual logic -->
    <span v-if="booking.booking_for_user_name">
      {{ booking.booking_for_user_name }}
      <v-chip size="small" color="info">Booked by Admin</v-chip>
    </span>
    <span v-else>
      {{ booking.user.name }}
    </span>
  </div>
</template>
```

### Example Implementation in Bookings.vue

```vue
<template>
  <!-- Transaction Card -->
  <v-card class="transaction-card">
    <v-card-title>
      <!-- Show the actual customer name, not the admin -->
      <div class="customer-info">
        <v-avatar size="32" color="primary">
          <span class="text-white">
            {{ booking.display_name.charAt(0).toUpperCase() }}
          </span>
        </v-avatar>
        <span class="ml-2">{{ booking.display_name }}</span>
        
        <!-- Optional: Show admin badge if booked by admin -->
        <v-chip 
          v-if="booking.booking_for_user_name" 
          size="small" 
          color="orange" 
          variant="outlined"
          class="ml-2"
        >
          <v-icon start size="small">mdi-account-cog</v-icon>
          Admin Booking
        </v-chip>
      </div>
    </v-card-title>
    
    <v-card-text>
      <!-- Show admin notes if admin user is viewing -->
      <div v-if="isAdmin && booking.admin_notes" class="admin-notes">
        <v-alert type="info" variant="tonal" density="compact">
          <template v-slot:prepend>
            <v-icon>mdi-note-text</v-icon>
          </template>
          <strong>Admin Notes:</strong> {{ booking.admin_notes }}
        </v-alert>
      </div>
      
      <!-- Other booking details -->
      <div class="booking-details">
        <p>Court: {{ booking.court.name }}</p>
        <p>Date: {{ formatDate(booking.start_time) }}</p>
        <p>Time: {{ formatTime(booking.start_time) }} - {{ formatTime(booking.end_time) }}</p>
      </div>
    </v-card-text>
  </v-card>
</template>

<script>
export default {
  computed: {
    isAdmin() {
      const user = JSON.parse(localStorage.getItem('user') || '{}')
      return user.role === 'admin'
    }
  }
}
</script>
```

## Booking Visibility Examples

### Example 1: Admin Books for Registered User
```
Admin (ID: 1) creates booking for John Doe (ID: 5)

Database:
- user_id: 1 (Admin)
- booking_for_user_id: 5 (John Doe)
- booking_for_user_name: "John Doe"

Visibility:
✅ Admin sees it (created by them)
✅ John Doe sees it (booked for them)
❌ Other users don't see it

Display:
- Name shown: "John Doe" (not "Admin")
- Badge: "Admin Booking" (optional)
```

### Example 2: Admin Books for Walk-in Customer
```
Admin (ID: 1) creates booking for "Walk-in Customer - Jane"

Database:
- user_id: 1 (Admin)
- booking_for_user_id: NULL
- booking_for_user_name: "Walk-in Customer - Jane"

Visibility:
✅ Admin sees it (created by them)
❌ No user account exists for Jane

Display:
- Name shown: "Walk-in Customer - Jane"
- Badge: "Admin Booking" (optional)
```

### Example 3: User Books for Themselves
```
John Doe (ID: 5) creates booking for themselves

Database:
- user_id: 5 (John Doe)
- booking_for_user_id: NULL
- booking_for_user_name: NULL

Visibility:
✅ John Doe sees it
✅ Admin sees it (sees all bookings)

Display:
- Name shown: "John Doe"
- No admin badge
```

## API Response Format

### Full Booking Object
```json
{
    "id": 123,
    "user_id": 1,
    "booking_for_user_id": 5,
    "booking_for_user_name": "John Doe",
    "admin_notes": "VIP customer, early check-in requested",
    "court_id": 2,
    "start_time": "2025-10-16 10:00:00",
    "end_time": "2025-10-16 11:00:00",
    "status": "approved",
    "user": {
        "id": 1,
        "name": "Admin User",
        "email": "admin@example.com",
        "role": "admin"
    },
    "booking_for_user": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "user"
    },
    "court": {
        "id": 2,
        "name": "Court A",
        "sport": {
            "id": 1,
            "name": "Badminton"
        }
    },
    "display_name": "John Doe",
    "effective_user": {
        "id": 5,
        "name": "John Doe",
        "email": "john@example.com",
        "role": "user"
    }
}
```

## Recommended Frontend Updates

### 1. Update Bookings Display
Replace all instances of `booking.user.name` with `booking.display_name`

### 2. Add Admin Badge
Show a badge when `booking.booking_for_user_name` is present to indicate admin booking

### 3. Show Admin Notes (Admin Only)
Display `booking.admin_notes` when current user is admin

### 4. Update User Filter
If you have user filters, consider filtering by effective_user instead of just user_id

### 5. Transaction Grouping
Group transactions by effective_user for better organization

## Testing Checklist

Backend:
- [ ] Admin creates booking for registered user - user sees it
- [ ] Admin creates booking for walk-in - only admin sees it  
- [ ] User creates booking - only they and admin see it
- [ ] API returns display_name correctly
- [ ] API returns effective_user correctly
- [ ] Relationships load properly (bookingForUser)

Frontend:
- [ ] Booking displays correct name (not admin name)
- [ ] Admin badge shows for admin bookings
- [ ] Admin notes visible to admins only
- [ ] User can see bookings created for them by admin
- [ ] Walk-in bookings show custom name correctly

