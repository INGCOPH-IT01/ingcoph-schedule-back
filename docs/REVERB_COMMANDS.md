# Laravel Reverb - Command Reference

## Development Commands

### Start All Services (4 Terminals)

**Terminal 1 - Laravel API Server:**
```bash
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back
php artisan serve
# Runs on: http://localhost:8000
```

**Terminal 2 - Reverb WebSocket Server:**
```bash
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back
php artisan reverb:start
# Runs on: ws://localhost:8080
```

**Terminal 3 - Queue Worker:**
```bash
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back
php artisan queue:work
# Processes broadcast events
```

**Terminal 4 - Frontend Dev Server:**
```bash
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-front
npm run dev
# Runs on: http://localhost:5173
```

## Configuration Commands

### Backend Setup
```bash
# Install Reverb
cd ingcoph-schedule-back
composer require laravel/reverb

# Publish config files
php artisan reverb:install
php artisan config:publish broadcasting

# Clear and cache config
php artisan config:clear
php artisan config:cache

# Check Reverb status
php artisan reverb:status
```

### Frontend Setup
```bash
# Install dependencies
cd ingcoph-schedule-front
npm install --save laravel-echo pusher-js

# Create .env file (copy from example)
copy .env.example .env

# Restart dev server after .env changes
npm run dev
```

## Testing Commands

### Test Event Broadcasting (Tinker)
```bash
cd ingcoph-schedule-back
php artisan tinker
```

Then in Tinker:
```php
// Test BookingCreated event
$booking = App\Models\Booking::first();
broadcast(new App\Events\BookingCreated($booking));

// Test BookingStatusChanged event
$booking = App\Models\Booking::first();
broadcast(new App\Events\BookingStatusChanged($booking, 'pending', 'approved'));
```

### Check Queue
```bash
# View queue status
php artisan queue:work --verbose

# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

### Check Logs
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Reverb logs (if using Supervisor)
tail -f storage/logs/reverb.log
```

## Database Commands

### Migrations
```bash
# Run migrations
php artisan migrate

# Rollback
php artisan migrate:rollback

# Fresh migration (WARNING: Deletes all data)
php artisan migrate:fresh
```

## Production Commands

### Using Supervisor (Linux)

**Create config:** `/etc/supervisor/conf.d/reverb.conf`
```bash
sudo nano /etc/supervisor/conf.d/reverb.conf
```

**Manage service:**
```bash
# Reload config
sudo supervisorctl reread
sudo supervisorctl update

# Start/Stop/Restart
sudo supervisorctl start reverb
sudo supervisorctl stop reverb
sudo supervisorctl restart reverb

# Check status
sudo supervisorctl status reverb

# View logs
sudo supervisorctl tail -f reverb
```

### Using PM2 (Alternative)

```bash
# Install PM2
npm install -g pm2

# Start Reverb
pm2 start "php artisan reverb:start" --name reverb

# Start Queue
pm2 start "php artisan queue:work" --name queue

# Save configuration
pm2 save

# Setup auto-start
pm2 startup
```

## Environment Variables

### Required Backend .env Variables
```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=1
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

### Required Frontend .env Variables
```env
VITE_REVERB_APP_KEY=your-app-key
VITE_REVERB_HOST=localhost
VITE_REVERB_PORT=8080
VITE_REVERB_SCHEME=http
```

## Debugging Commands

### Check WebSocket Connection
```javascript
// In Browser Console
Echo.connector.pusher.connection.state
// Should show: "connected"

// Check channels
Echo.connector.pusher.allChannels()
```

### Test API Endpoint
```bash
# Test broadcasting auth endpoint
curl -X POST http://localhost:8000/api/broadcasting/auth \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -d "channel_name=private-user.1"
```

## Quick Fixes

### "Port already in use"
```bash
# Find process using port 8080
netstat -ano | findstr :8080

# Kill process (Windows)
taskkill /PID <PID> /F
```

### "WebSocket connection failed"
```bash
# Restart Reverb
# Terminal 2
Ctrl+C
php artisan reverb:start
```

### "Events not broadcasting"
```bash
# Restart queue worker
# Terminal 3
Ctrl+C
php artisan queue:work
```

### Clear all caches
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
```

## Useful Shortcuts

### Windows PowerShell - Run all in separate windows
```powershell
# Create a start script: start-dev.ps1
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back; php artisan serve"
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back; php artisan reverb:start"
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back; php artisan queue:work"
Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd C:\Users\admin\Desktop\Github\ingcoph-schedule-front; npm run dev"
```

### Stop all services
```bash
# Press Ctrl+C in each terminal window
```

## Monitoring

### Watch Queue in Real-time
```bash
php artisan queue:work --verbose
```

### Monitor Reverb Connections
```bash
php artisan reverb:start --debug
```

### Check Application Health
```bash
# Health check endpoint
curl http://localhost:8000/up
```

## Generate New Keys

### Generate Secure Keys
```bash
# Use OpenSSL
openssl rand -base64 32

# Or PHP
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

## Maintenance Mode

```bash
# Enable maintenance
php artisan down

# Disable maintenance
php artisan up

# Allow specific IPs during maintenance
php artisan down --secret="maintenance-bypass-token"
```

## Performance

### Optimize for Production
```bash
# Cache everything
php artisan optimize

# Individual optimizations
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Clear Optimization
```bash
php artisan optimize:clear
```

---

## Quick Start (Copy-Paste)

**Development Setup:**
```bash
# Terminal 1
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back && php artisan serve

# Terminal 2 (new window)
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back && php artisan reverb:start

# Terminal 3 (new window)
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-back && php artisan queue:work

# Terminal 4 (new window)
cd C:\Users\admin\Desktop\Github\ingcoph-schedule-front && npm run dev
```

That's it! Visit http://localhost:5173 in your browser! 🚀

