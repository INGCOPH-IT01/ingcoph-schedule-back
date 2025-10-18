# Laravel Reverb Quick Start Guide

## Quick Setup (Development)

### 1. Backend Configuration

Add to your `.env` file:
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=1
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### 2. Start Services (4 terminals)

**Terminal 1 - Laravel Server:**
```bash
cd ingcoph-schedule-back
php artisan serve
```

**Terminal 2 - Reverb WebSocket Server:**
```bash
cd ingcoph-schedule-back
php artisan reverb:start
```

**Terminal 3 - Queue Worker:**
```bash
cd ingcoph-schedule-back
php artisan queue:work
```

**Terminal 4 - Frontend:**
```bash
cd ingcoph-schedule-front
npm run dev
```

### 3. Frontend Configuration

Create `ingcoph-schedule-front/.env`:
```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

**Important:** Restart the frontend dev server after changing `.env` files!

## Testing Real-Time Updates

1. Open the app in your browser and login
2. Open browser DevTools Console
3. Create a new booking - you should see:
   - Console log: "📱 New booking created event received"
   - Toast notification in top-right corner
   - Bookings list refreshes automatically

4. As admin, approve/reject a booking - the user should see:
   - Real-time notification
   - Status updates instantly

## Production Setup

### Backend `.env`:
```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=production-id
REVERB_APP_KEY=production-key-here
REVERB_APP_SECRET=production-secret-here
REVERB_HOST=bschedule.m4d8q2.com
REVERB_PORT=443
REVERB_SCHEME=https

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Frontend `.env`:
```env
VITE_REVERB_APP_KEY=production-key-here
VITE_REVERB_HOST=bschedule.m4d8q2.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
```

### Run Reverb as a Service (Supervisor)

Create `/etc/supervisor/conf.d/reverb.conf`:
```ini
[program:reverb]
command=php /var/www/html/ingcoph-schedule-back/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/html/ingcoph-schedule-back
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/ingcoph-schedule-back/storage/logs/reverb.log
```

Then:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start reverb
```

## Troubleshooting

### "WebSocket connection failed"
- Check Reverb server is running: `php artisan reverb:start`
- Check REVERB_PORT is not blocked by firewall
- Verify REVERB_HOST matches your domain/IP

### "Events not received"
- Check queue worker is running: `php artisan queue:work`
- Check Laravel logs: `tail -f storage/logs/laravel.log`
- Verify user is authenticated (token present)

### "Authentication failed for channel"
- Check token is valid in localStorage
- Verify channels.php has correct authorization
- Check API endpoint `/api/broadcasting/auth` is accessible

## Events Available

1. **booking.created** - New booking created
2. **booking.status.changed** - Booking status updated (approved, rejected, cancelled, etc.)

Both events are broadcast to:
- Public channel: `bookings` (all authenticated users)
- Private channel: `user.{userId}` (specific user only)

## Components with Real-Time Updates

- ✅ Bookings.vue - User bookings list
- ✅ AdminDashboard.vue (can be added)
- ✅ StaffDashboard.vue (can be added)
- ✅ UserManagement.vue (can be added)

## Need Help?

See `REVERB_SETUP.md` for detailed documentation.

