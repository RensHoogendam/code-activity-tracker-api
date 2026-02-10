<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PullRequest extends Model
{
    protected $fillable = [
        'repository_id',
        'bitbucket_id',
        'title',
        'author_display_name',
        'created_on',
        'updated_on',
        'state',
        'ticket',
        'bitbucket_data',
        'last_fetched_at'
    ];

    protected $casts = [
        'created_on' => 'datetime',
        'updated_on' => 'datetime',
        'bitbucket_data' => 'array',
        'last_fetched_at' => 'datetime'
    ];

    /**
     * Get the repository this pull request belongs to
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
        return $query->where('author_display_name', 'like', "%{$author}%");
    }

    /**
     * Scope to get pull requests from the last X days
     */
    public function scopeRecent($query, int $days = 14)
    {
        return $query->where('updated_on', '>=', now()->subDays($days));
    }

    /**
     * Scope to filter by state
     */
    public function scopeByState($query, string $state)
    {
        return $query->where('state', $state);
    }

    /**
     * Scope to get open pull requests
     */
    public function scopeOpen($query)
    {
        return $query->where('state', 'OPEN');
    }

    /**
     * Scope to get merged pull requests
     */
    public function scopeMerged($query)
    {
        return $query->where('state', 'MERGED');
    }

    /**
     * Scope to get pull requests that need refreshing
     */
    public function scopeNeedsUpdate($query, int $maxAgeMinutes = 30)
    {
        return $query->where(function ($q) use ($maxAgeMinutes) {
            $q->whereNull('last_fetched_at')
              ->orWhere('last_fetched_at', '<', now()->subMinutes($maxAgeMinutes));
        });
    }

    /**
     * Mark this pull request as fetched from API
     */
    public function markFetched()
    {
        $this->update(['last_fetched_at' => now()]);
    }
}