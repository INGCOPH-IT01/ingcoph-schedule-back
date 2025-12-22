<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add indexes to cart_transactions table for faster filtering and sorting
        Schema::table('cart_transactions', function (Blueprint $table) {
            // Index for filtering by approval status
            if (!$this->hasIndex('cart_transactions', 'cart_transactions_approval_status_index')) {
                $table->index('approval_status', 'cart_transactions_approval_status_index');
            }

            // Index for filtering by status
            if (!$this->hasIndex('cart_transactions', 'cart_transactions_status_index')) {
                $table->index('status', 'cart_transactions_status_index');
            }

            // Composite index for approval_status + created_at (common filter + sort combination)
            if (!$this->hasIndex('cart_transactions', 'cart_transactions_approval_status_created_at_index')) {
                $table->index(['approval_status', 'created_at'], 'cart_transactions_approval_status_created_at_index');
            }

            // Index for user_id (for user-specific queries)
            if (!$this->hasIndex('cart_transactions', 'cart_transactions_user_id_index')) {
                $table->index('user_id', 'cart_transactions_user_id_index');
            }

            // Index for payment filtering
            if (!$this->hasIndex('cart_transactions', 'cart_transactions_payment_method_index')) {
                $table->index('payment_method', 'cart_transactions_payment_method_index');
            }
        });

        // Add indexes to cart_items table for faster joins and filtering
        Schema::table('cart_items', function (Blueprint $table) {
            // Index for booking date filtering (very common in admin dashboard)
            if (!$this->hasIndex('cart_items', 'cart_items_booking_date_index')) {
                $table->index('booking_date', 'cart_items_booking_date_index');
            }

            // Composite index for cart_transaction_id + booking_date (for sorting by date)
            if (!$this->hasIndex('cart_items', 'cart_items_cart_transaction_id_booking_date_index')) {
                $table->index(['cart_transaction_id', 'booking_date'], 'cart_items_cart_transaction_id_booking_date_index');
            }

            // Index for status filtering
            if (!$this->hasIndex('cart_items', 'cart_items_status_index')) {
                $table->index('status', 'cart_items_status_index');
            }

            // Index for sport filtering
            if (!$this->hasIndex('cart_items', 'cart_items_sport_id_index')) {
                $table->index('sport_id', 'cart_items_sport_id_index');
            }

            // Index for booking_for_user_id (for user searches)
            if (!$this->hasIndex('cart_items', 'cart_items_booking_for_user_id_index')) {
                $table->index('booking_for_user_id', 'cart_items_booking_for_user_id_index');
            }

            // Index for waitlist filtering
            if (!$this->hasIndex('cart_items', 'cart_items_booking_waitlist_id_index')) {
                $table->index('booking_waitlist_id', 'cart_items_booking_waitlist_id_index');
            }

            // Composite index for common filters: status + booking_date
            if (!$this->hasIndex('cart_items', 'cart_items_status_booking_date_index')) {
                $table->index(['status', 'booking_date'], 'cart_items_status_booking_date_index');
            }
        });

        // Add indexes to users table for faster name/email searches
        Schema::table('users', function (Blueprint $table) {
            // Index for name search (used in admin dashboard filtering)
            if (!$this->hasIndex('users', 'users_name_index')) {
                $table->index('first_name', 'users_first_name_index');
                $table->index('last_name', 'users_last_name_index');
            }

            // Email is usually already indexed as unique, but verify
            if (!$this->hasIndex('users', 'users_email_index') && !$this->hasIndex('users', 'users_email_unique')) {
                $table->index('email', 'users_email_index');
            }
        });

        // Add indexes to booking_waitlists table
        Schema::table('booking_waitlists', function (Blueprint $table) {
            // Index for pending_booking_id (used to find waitlists for a booking)
            if (!$this->hasIndex('booking_waitlists', 'booking_waitlists_pending_booking_id_index')) {
                $table->index('pending_booking_id', 'booking_waitlists_pending_booking_id_index');
            }

            // Composite index for status + position (for getting next in queue)
            if (!$this->hasIndex('booking_waitlists', 'booking_waitlists_status_position_index')) {
                $table->index(['status', 'position'], 'booking_waitlists_status_position_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove indexes from cart_transactions
        Schema::table('cart_transactions', function (Blueprint $table) {
            $table->dropIndex(['cart_transactions_approval_status_index']);
            $table->dropIndex(['cart_transactions_status_index']);
            $table->dropIndex(['cart_transactions_approval_status_created_at_index']);
            $table->dropIndex(['cart_transactions_user_id_index']);
            $table->dropIndex(['cart_transactions_payment_method_index']);
        });

        // Remove indexes from cart_items
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex(['cart_items_booking_date_index']);
            $table->dropIndex(['cart_items_cart_transaction_id_booking_date_index']);
            $table->dropIndex(['cart_items_status_index']);
            $table->dropIndex(['cart_items_sport_id_index']);
            $table->dropIndex(['cart_items_booking_for_user_id_index']);
            $table->dropIndex(['cart_items_booking_waitlist_id_index']);
            $table->dropIndex(['cart_items_status_booking_date_index']);
        });

        // Remove indexes from users
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['users_name_index']);
            // Don't drop email index if it was created as unique constraint
            if ($this->hasIndex('users', 'users_email_index')) {
                $table->dropIndex(['users_email_index']);
            }
        });

        // Remove indexes from booking_waitlists
        Schema::table('booking_waitlists', function (Blueprint $table) {
            $table->dropIndex(['booking_waitlists_pending_booking_id_index']);
            $table->dropIndex(['booking_waitlists_status_position_index']);
        });
    }

    /**
     * Helper method to check if index exists using raw SQL
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            "SELECT COUNT(*) as count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$database, $table, $indexName]
        );

        return $result[0]->count > 0;
    }
};
