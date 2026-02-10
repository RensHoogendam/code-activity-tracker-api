<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
     * Repositories this user works on
     */
    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class, 'user_repositories')
                    ->withPivot(['is_primary', 'is_enabled'])
                    ->withTimestamps();
    }

    /**
     * Get user's primary repositories
     */
    public function primaryRepositories(): BelongsToMany
    {
        return $this->repositories()->wherePivot('is_primary', true);
    }

    /**
     * Get user's enabled repositories
     */
    public function enabledRepositories(): BelongsToMany
    {
        return $this->repositories()->wherePivot('is_enabled', true);
    }

    /**
     * Get user's enabled primary repositories
     */
    public function enabledPrimaryRepositories(): BelongsToMany
    {
        return $this->repositories()
                    ->wherePivot('is_primary', true)
                    ->wherePivot('is_enabled', true);
    }
}
