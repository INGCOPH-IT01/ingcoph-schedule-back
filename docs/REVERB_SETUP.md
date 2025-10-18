# Laravel Reverb Real-Time Setup Guide

This document explains how to set up and use Laravel Reverb for real-time booking updates in the ingcoph-schedule project.

## Overview

Laravel Reverb is a first-party WebSocket server for Laravel that enables real-time communication between your application and clients. In this project, it's used to broadcast booking status changes and new bookings in real-time.

## Backend Setup (Laravel)

### 1. Installation

Reverb has already been installed via:
```bash
composer require laravel/reverb
php artisan reverb:install
```

### 2. Environment Configuration

Add these environment variables to your `.env` file:

```env
# Broadcasting Configuration
BROADCAST_CONNECTION=reverb

# Reverb Configuration
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

# For frontend to connect
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

**Generate secure keys:**
```bash
# Generate random keys for production
php artisan reverb:install
```

### 3. Start the Reverb Server

```bash
php artisan reverb:start
```

For production with SSL:
```bash
php artisan reverb:start --host=0.0.0.0 --port=8080
```

### 4. Queue Configuration

Reverb broadcasts work best with queues. Make sure your queue worker is running:

```bash
php artisan queue:work
```

## Frontend Setup (Vue.js)

### 1. Installation

Laravel Echo and Pusher JS have already been installed:
```bash
npm install --save laravel-echo pusher-js
```

### 2. Environment Configuration

Create or update `.env` file in the frontend directory:

```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=bschedule.m4d8q2.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

### 3. Echo Service

The Echo service is automatically initialized in `src/services/echo.js` and connects when a user logs in.

## Features Implemented

### Real-Time Events

1. **Booking Created** (`booking.created`)
   - Broadcast when a new booking is created
   - Sent to: `bookings` channel (public) and `user.{userId}` channel (private)

2. **Booking Status Changed** (`booking.status.changed`)
   - Broadcast when booking status changes (approved, rejected, cancelled, checked-in, completed)
   - Sent to: `bookings` channel (public) and `user.{userId}` channel (private)
   - Includes: old status, new status, booking data

### Broadcasting Channels

1. **Public Channel:** `bookings`
   - All authenticated users can listen
   - Receives all booking updates for admin dashboards

2. **Private Channel:** `user.{userId}`
   - Only the specific user can listen
   - Receives updates for their own bookings

## Usage in Components

### Using the Composable

The `useBookingRealtime` composable makes it easy to listen to real-time events:

```javascript
import { useBookingRealtime } from '../composables/useBookingRealtime'

// In your setup function
useBookingRealtime({
  onBookingCreated: (data) => {
    console.log('New booking:', data.booking)
    // Refresh bookings list
    fetchBookings()
  },
  onBookingStatusChanged: (data) => {
    console.log('Status changed:', data.old_status, '->', data.new_status)
    // Show notification
    showNotification(`Booking ${data.new_status}`)
    // Refresh bookings list
    fetchBookings()
  },
  onOwnBookingCreated: (data) => {
    console.log('Your booking created:', data.booking)
  },
  onOwnBookingStatusChanged: (data) => {
    console.log('Your booking status changed:', data)
  }
})
```

### Manual Echo Usage

```javascript
import { getEcho } from '../services/echo'

const echo = getEcho()

// Listen to public channel
echo.channel('bookings')
  .listen('.booking.created', (data) => {
    console.log('Booking created:', data)
  })

// Listen to private channel
const userId = user.value.id
echo.private(`user.${userId}`)
  .listen('.booking.status.changed', (data) => {
    console.log('Your booking updated:', data)
  })
```

## Testing Real-Time Features

### 1. Start Services

Terminal 1 - Laravel Server:
```bash
cd ingcoph-schedule-back
php artisan serve
```

Terminal 2 - Reverb Server:
```bash
cd ingcoph-schedule-back
php artisan reverb:start
```

Terminal 3 - Queue Worker:
```bash
cd ingcoph-schedule-back
php artisan queue:work
```

Terminal 4 - Frontend:
```bash
cd ingcoph-schedule-front
npm run dev
```

### 2. Test Scenarios

1. **Create a Booking:**
   - Open browser as User A
   - Create a new booking
   - Check if real-time notification appears

2. **Approve/Reject Booking:**
   - Open browser as Admin
   - Approve or reject a booking
   - Check if User A receives real-time notification

3. **Multiple Users:**
   - Open multiple browser windows with different users
   - Create/update bookings
   - Verify all users receive appropriate updates

## Production Deployment

### Backend (Laravel)

1. **Environment Variables:**
   ```env
   BROADCAST_CONNECTION=reverb
   REVERB_HOST=your-domain.com
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```

2. **Supervisor Configuration:**
   Create `/etc/supervisor/conf.d/reverb.conf`:
   ```ini
   [program:reverb]
   command=php /path/to/your/app/artisan reverb:start --host=0.0.0.0 --port=8080
   directory=/path/to/your/app
   autostart=true
   autorestart=true
   user=www-data
   redirect_stderr=true
   stdout_logfile=/path/to/your/app/storage/logs/reverb.log
   ```

3. **Nginx Configuration:**
   ```nginx
   location /app/ {
       proxy_pass http://localhost:8080;
       proxy_http_version 1.1;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_set_header Host $host;
       proxy_cache_bypass $http_upgrade;
   }
   ```

### Frontend (Vue.js)

Update production environment variables:
```env
VITE_REVERB_APP_KEY=production-key
VITE_REVERB_HOST=your-domain.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

## Troubleshooting

### Connection Issues

1. **Check Reverb is running:**
   ```bash
   php artisan reverb:status
   ```

2. **Check Laravel logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

3. **Check browser console:**
   - Open browser DevTools
   - Check for WebSocket connection errors
   - Verify Echo initialization logs

### Authentication Issues

1. **Verify token is present:**
   ```javascript
   console.log(localStorage.getItem('token'))
   ```

2. **Check broadcasting auth endpoint:**
   ```bash
   curl -X POST https://your-api.com/api/broadcasting/auth \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -H "Accept: application/json" \
     -d "channel_name=private-user.1"
   ```

### Events Not Firing

1. **Check queue is running:**
   ```bash
   php artisan queue:work
   ```

2. **Test event manually:**
   ```php
   use App\Events\BookingStatusChanged;
   use App\Models\Booking;
   
   $booking = Booking::find(1);
   broadcast(new BookingStatusChanged($booking, 'pending', 'approved'));
   ```

3. **Verify channel subscriptions:**
   - Check browser console for channel subscription logs
   - Verify user is authenticated

## Security Considerations

1. **Use HTTPS in production** (`REVERB_SCHEME=https`)
2. **Set strong APP_KEY** for encryption
3. **Implement proper channel authorization** in `routes/channels.php`
4. **Use environment-specific keys** (different for dev/staging/production)
5. **Rate limit WebSocket connections** in production

## Additional Resources

- [Laravel Broadcasting Documentation](https://laravel.com/docs/broadcasting)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)
- [Laravel Echo Documentation](https://laravel.com/docs/broadcasting#client-side-installation)

## Support

For issues or questions, please check:
1. Laravel logs: `storage/logs/laravel.log`
2. Browser console for frontend errors
3. Reverb server logs
4. Queue worker logs

