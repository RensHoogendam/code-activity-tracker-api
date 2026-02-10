<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repository extends Model
{
    protected $fillable = [
        'name',
        'full_name',
        'workspace',
        'bitbucket_updated_on',
        'is_private',
        'description',
        'language',
        'is_active'
    ];

    protected $casts = [
        'bitbucket_updated_on' => 'datetime',
        'is_private' => 'boolean',
        'is_active' => 'boolean'
    ];

    /**
     * Users who work on this repository
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_repositories')
                    ->withPivot(['is_primary', 'is_enabled'])
                    ->withTimestamps();
    }

    /**
     * Get users who have this repository enabled
     */
    public function enabledUsers(): BelongsToMany
    {
        return $this->users()->wherePivot('is_enabled', true);
    }

    /**
     * Get all commits for this repository
     */
    public function commits(): HasMany
    {
        return $this->hasMany(Commit::class);
    }

    /**
     * Get all pull requests for this repository
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }

    /**
     * Scope for active repositories only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for repositories by workspace
     */
    public function scopeByWorkspace($query, string $workspace)
    {
        return $query->where('workspace', $workspace);
    }
}
