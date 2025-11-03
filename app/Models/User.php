<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['name'];

    /**
     * Get the user's full name.
     *
     * @return string
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Set the user's name (for backward compatibility).
     * Splits the name into first_name and last_name.
     *
     * @param string $value
     * @return void
     */
    public function setNameAttribute(string $value): void
    {
        $parts = explode(' ', $value, 2);
        $this->attributes['first_name'] = $parts[0] ?? '';
        $this->attributes['last_name'] = $parts[1] ?? '';
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is regular user
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Check if user is staff/scanner
     */
    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    /**
     * Check if user is cashier
     */
    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    /**
     * Check if user can scan QR codes (staff or admin)
     */
    public function canScanQr(): bool
    {
        return $this->role === 'staff' || $this->role === 'admin';
    }

    /**
     * Check if user is admin or staff
     */
    public function isAdminOrStaff(): bool
    {
        return $this->role === 'admin' || $this->role === 'staff';
    }

    /**
     * Check if user can access POS (admin, staff, or cashier)
     */
    public function canAccessPos(): bool
    {
        return $this->role === 'admin' || $this->role === 'staff' || $this->role === 'cashier';
    }
}
