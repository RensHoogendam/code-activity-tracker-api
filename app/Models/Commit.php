<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commit extends Model
{
    protected $fillable = [
        'repository_id',
        'hash',
        'commit_date',
        'message',
        'author_raw',
        'author_username',
        'ticket',
        'bitbucket_data',
        'last_fetched_at'
    ];

    protected $casts = [
        'commit_date' => 'datetime',
        'bitbucket_data' => 'array',
        'last_fetched_at' => 'datetime'
    ];

    /**
     * Get the repository this commit belongs to
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Scope to filter by repository name or full name
     */
    public function scopeForRepository($query, string $repositoryName)
    {
        return $query->whereHas('repository', function ($q) use ($repositoryName) {
            $q->where('full_name', $repositoryName)
              ->orWhere('name', $repositoryName);
        });
    }

    /**
     * Scope to filter by author
     */
    public function scopeByAuthor($query, string $author)
    {
        return $query->where(function ($q) use ($author) {
            $q->where('author_username', 'like', "%{$author}%")
              ->orWhere('author_raw', 'like', "%{$author}%");
        });
    }

    /**
     * Scope to get commits from the last X days
     */
    public function scopeRecent($query, int $days = 14)
    {
        return $query->where('commit_date', '>=', now()->subDays($days));
    }

    /**
     * Scope to get commits that need refreshing
     */
    public function scopeNeedsUpdate($query, int $maxAgeMinutes = 30)
    {
        return $query->where(function ($q) use ($maxAgeMinutes) {
            $q->whereNull('last_fetched_at')
              ->orWhere('last_fetched_at', '<', now()->subMinutes($maxAgeMinutes));
        });
    }

    /**
     * Mark this commit as fetched from API
     */
    public function markFetched()
    {
        $this->update(['last_fetched_at' => now()]);
    }
}