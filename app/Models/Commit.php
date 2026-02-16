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
        'branch',
        'pull_request_id',
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
     * Scope to filter by author with flexible name and email matching
     */
    public function scopeByAuthor($query, string $author)
    {
        return $query->where(function ($q) use ($author) {
            // Direct matches first (most common cases)
            $q->where('author_username', 'like', "%{$author}%")
              ->orWhere('author_raw', 'like', "%{$author}%")
              
              // Case-insensitive matching
              ->orWhereRaw('LOWER(author_raw) LIKE LOWER(?)', ["%{$author}%"]);
              
            // If searching by email, also extract the name part for broader matching
            if (filter_var($author, FILTER_VALIDATE_EMAIL)) {
                // Extract name before @ symbol and try variations
                $emailName = explode('@', $author)[0];
                $q->orWhere('author_raw', 'like', "%{$emailName}%");
                
                // Handle common email-to-name patterns (dots to spaces, etc)
                $displayName = str_replace('.', ' ', $emailName);
                $displayName = ucwords($displayName);
                $q->orWhere('author_raw', 'like', "%{$displayName}%");
            } else {
                // Handle common name variations for non-email searches
                $q->orWhere('author_raw', 'like', "%" . str_replace(' ', '', $author) . "%") // Remove spaces
                  ->orWhere('author_raw', 'like', "%" . str_replace('_', ' ', $author) . "%") // Underscore to space
                  ->orWhere('author_raw', 'like', "%" . str_replace(' ', '_', $author) . "%") // Space to underscore
                  ->orWhere('author_raw', 'like', "%" . str_replace('.', ' ', $author) . "%") // Dot to space
                  ->orWhere('author_raw', 'like', "%" . str_replace(' ', '.', $author) . "%"); // Space to dot
            }
        });
    }

    /**
     * Scope to filter by branch
     */
    public function scopeByBranch($query, string $branch)
    {
        return $query->where('branch', $branch);
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