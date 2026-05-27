<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'status', 'is_admin'];

    /** Numbers this user owns. */
    public function numbers(): HasMany
    {
        return $this->hasMany(Number::class);
    }

    /** Delegation grants where this user is an assistant. */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegate::class, 'assistant_user_id');
    }

    /**
     * All numbers this user can manage: owned + delegated.
     * Returns a collection of Number models.
     */
    public function accessibleNumbers()
    {
        $ownIds = $this->numbers()->pluck('id');
        $delegatedIds = $this->delegations()->pluck('number_id');
        $allIds = $ownIds->merge($delegatedIds)->unique()->values();

        return Number::whereIn('id', $allIds)->get();
    }

    public function accessibleNumberIds()
    {
        $ownIds = $this->numbers()->pluck('id');
        $delegatedIds = $this->delegations()->pluck('number_id');

        return $ownIds->merge($delegatedIds)->unique()->values();
    }

    protected $hidden = ['password', 'remember_token'];

    protected $casts = ['email_verified_at' => 'datetime'];
}
