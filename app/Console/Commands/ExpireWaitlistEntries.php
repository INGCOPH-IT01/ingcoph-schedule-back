<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BookingWaitlist;
use Carbon\Carbon;

class ExpireWaitlistEntries extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'waitlist:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire waitlist entries that have passed their expiration time';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for expired waitlist entries...');

        // Find all notified waitlist entries that have expired
        $expiredEntries = BookingWaitlist::where('status', BookingWaitlist::STATUS_NOTIFIED)
            ->where('expires_at', '<=', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expiredEntries as $entry) {
            try {
                $entry->markAsExpired();
                $count++;
            } catch (\Exception $e) {
                // Continue processing other entries
            }
        }

        $this->info("Expired {$count} waitlist entries.");

        // Also notify next users in waitlist if slots are still available
        $this->info('Checking for next waitlist users to notify...');
        $this->notifyNextWaitlistUsers();

        return 0;
    }

    /**
     * Notify the next waitlist users for slots that are now available
     */
    private function notifyNextWaitlistUsers()
    {
        // This can be enhanced to automatically notify the next person in line
        // when someone's waitlist expires, but for now we'll keep it simple
        // The admin will need to reject/approve to trigger notifications

        $this->info('Next waitlist notification logic not yet implemented.');
    }
}
