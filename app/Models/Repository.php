<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
                    ->withPivot('is_primary')
                    ->withTimestamps();
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
