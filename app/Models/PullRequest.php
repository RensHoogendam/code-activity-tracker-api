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
     * Scope to filter by author with email and name cross-referencing
     */
    public function scopeByAuthor($query, string $author)
    {
        return $query->where(function ($q) use ($author) {
            // Direct display name matching
            $q->where('author_display_name', 'like', "%{$author}%");
              
            // If searching by email, cross-reference with commits
            if (filter_var($author, FILTER_VALIDATE_EMAIL)) {
                // Find commits with this email and get their display names
                try {
                    $commitDisplayNames = \App\Models\Commit::where('author_raw', 'like', "%{$author}%")
                        ->pluck('author_raw')
                        ->map(function ($authorRaw) {
                            if (preg_match('/^([^<]+)\s*</', $authorRaw, $matches)) {
                                return trim($matches[1]);
                            }
                            return null;
                        })
                        ->filter()
                        ->unique()
                        ->toArray();
                        
                    foreach ($commitDisplayNames as $displayName) {
                        $q->orWhere('author_display_name', 'like', "%{$displayName}%");
                    }
                } catch (\Exception $e) {
                    // Silently continue if cross-reference fails
                }
                
                // Convert email to potential display name (r.hoogendam@atabix.nl -> Rens Hoogendam)
                $emailName = explode('@', $author)[0];
                $displayName = str_replace('.', ' ', $emailName);
                $displayName = ucwords($displayName);
                $q->orWhere('author_display_name', 'like', "%{$displayName}%");
            } else {
                // For non-email searches, add common name variations
                $q->orWhere('author_display_name', 'like', "%" . str_replace(' ', '', $author) . "%")  // Remove spaces
                  ->orWhere('author_display_name', 'like', "%" . str_replace('_', ' ', $author) . "%")  // Underscore to space
                  ->orWhere('author_display_name', 'like', "%" . str_replace('.', ' ', $author) . "%"); // Dot to space
                
                // If searching by something like "RensHoogendam", also check for "Rens Hoogendam"
                if (!str_contains($author, ' ') && strlen($author) > 4) {
                    // Try to split camelCase: "RensHoogendam" -> "Rens Hoogendam"
                    $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $author);
                    if ($spaced !== $author) {
                        $q->orWhere('author_display_name', 'like', "%{$spaced}%");
                    }
                    
                    // Also try with just first part: "RensHoogendam" -> "Rens"
                    if (preg_match('/^([A-Z][a-z]+)/', $author, $matches)) {
                        $firstName = $matches[1];
                        $q->orWhere('author_display_name', 'like', "%{$firstName}%");
                    }
                }
                
                // Simple cross-reference: check if any commit authors contain this search term  
                // and then match PRs by the display names from those commits
                try {
                    $matchingDisplayNames = \App\Models\Commit::where('author_raw', 'like', "%{$author}%")
                        ->pluck('author_raw')
                        ->map(function ($authorRaw) {
                            if (preg_match('/^([^<]+)\s*</', $authorRaw, $matches)) {
                                return trim($matches[1]);
                            }
                            return null;
                        })
                        ->filter()
                        ->unique()
                        ->toArray();
                        
                    foreach ($matchingDisplayNames as $displayName) {
                        $q->orWhere('author_display_name', 'like', "%{$displayName}%");
                    }
                } catch (\Exception $e) {
                    // Silently continue if cross-reference fails
                }
            }
        });
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