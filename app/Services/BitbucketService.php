<?php

namespace App\Services;

use App\Models\Repository;
use App\Models\Commit;
use App\Models\PullRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

class BitbucketService
{
    protected array $config;
    protected string $baseUrl = 'https://api.bitbucket.org/2.0';

    public function __construct()
    {
        $this->config = [
            'username' => config('services.bitbucket.username'),
            'token' => config('services.bitbucket.token'),
            'workspaces' => array_filter(explode(',', config('services.bitbucket.workspaces', ''))),
            'author_display_name' => config('services.bitbucket.author_display_name'),
            'author_email' => config('services.bitbucket.author_email'),
        ];
        
        // Validate configuration
        if (empty($this->config['username']) || empty($this->config['token'])) {
            throw new \Exception('Bitbucket credentials not configured. Please set BITBUCKET_USERNAME and BITBUCKET_TOKEN in .env file.');
        }
    }

    /**
     * Fetch all commits and pull requests (using local data when possible)
     */
    public function fetchAllData(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null): array
    {
        // Try to get data from local database first
        $localData = $this->fetchLocalData($maxDays, $selectedRepos, $authorFilter);
        
        // For now, prioritize local data to prevent timeouts
        // Only refresh if we have no local data at all
        if (!empty($localData)) {
            \Log::info("Using local data (" . count($localData) . " items found)");
            return $localData;
        }
        
        // Only check for API refresh if we have no local data
        \Log::info("No local data found, checking if API refresh is needed");
        $needsRefresh = $this->checkIfDataNeedsRefresh($selectedRepos, $maxDays);
        
        if ($needsRefresh) {
            \Log::info("Refreshing data from API");
            // Refresh data from API for repositories that need it
            $this->refreshDataFromApi($maxDays, $selectedRepos, $authorFilter);
            // Re-fetch local data after refresh
            $localData = $this->fetchLocalData($maxDays, $selectedRepos, $authorFilter); 
        }
        
        return $localData;
    }

    /**
     * Fetch data from local database
     */
    protected function fetchLocalData(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null): array
    {
        $allData = [];
        
        // Get repositories
        $repositoriesQuery = Repository::active();
        if ($selectedRepos) {
            $repositoriesQuery->whereIn('full_name', $selectedRepos);
        }
        $repositories = $repositoriesQuery->get();
        
        foreach ($repositories as $repository) {
            // Fetch commits from database
            $commitsQuery = $repository->commits()->recent($maxDays);
            if ($authorFilter) {
                $commitsQuery->byAuthor($authorFilter);
            }
            $commits = $commitsQuery->get();
            
            foreach ($commits as $commit) {
                $allData[] = [
                    'type' => 'commit',
                    'repository' => $repository->full_name,
                    'hash' => $commit->hash,
                    'date' => $commit->commit_date->toISOString(),
                    'message' => $commit->message,
                    'author_raw' => $commit->author_raw,
                    'author_username' => $commit->author_username,
                    'ticket' => $commit->ticket
                ];
            }
            
            // Fetch pull requests from database
            $prsQuery = $repository->pullRequests()->recent($maxDays);
            if ($authorFilter) {
                $prsQuery->byAuthor($authorFilter);
            }
            $pullRequests = $prsQuery->get();
            
            foreach ($pullRequests as $pr) {
                $allData[] = [
                    'type' => 'pull_request',
                    'repository' => $repository->full_name,
                    'id' => $pr->bitbucket_id,
                    'title' => $pr->title,
                    'author' => $pr->author_display_name,
                    'created_on' => $pr->created_on->toISOString(),
                    'updated_on' => $pr->updated_on->toISOString(),
                    'state' => $pr->state,
                    'ticket' => $pr->ticket
                ];
            }
        }
        
        // Sort by date (newest first)
        usort($allData, function($a, $b) {
            $dateA = $a['type'] === 'commit' ? $a['date'] : $a['updated_on'];
            $dateB = $b['type'] === 'commit' ? $b['date'] : $b['updated_on'];
            
            return strcmp($dateB, $dateA);
        });
        
        return $allData;
    }

    /**
     * Check if local data needs refreshing from API
     */
    protected function checkIfDataNeedsRefresh(?array $selectedRepos = null, int $maxDays = 14, int $maxAgeMinutes = 30): bool
    {
        // If we have recent local data, don't refresh from API
        $repositoriesQuery = Repository::active();
        if ($selectedRepos) {
            $repositoriesQuery->whereIn('full_name', $selectedRepos);
        }
        $repositories = $repositoriesQuery->get();
        
        if ($repositories->isEmpty()) {
            return true; // No matching repositories, need to refresh
        }
        
        foreach ($repositories as $repository) {
            // Check if we have any recent commits for this repository
            $recentCommits = $repository->commits()
                ->recent($maxDays)
                ->count();
            
            // Check if we have any recent pull requests for this repository  
            $recentPrs = $repository->pullRequests()
                ->recent($maxDays)
                ->count();
            
            // If we have no recent data at all for this repo, we need to refresh
            if ($recentCommits === 0 && $recentPrs === 0) {
                \Log::info("No recent data for {$repository->full_name}, needs refresh");
                return true;
            }
            
            // Check if any data is too old
            $staleCommits = $repository->commits()
                ->recent($maxDays)
                ->needsUpdate($maxAgeMinutes)
                ->count();
            
            if ($staleCommits > 0) {
                \Log::info("Stale commits for {$repository->full_name}, needs refresh");
                return true;
            }
        }
        
        \Log::info("Local data is fresh, no API refresh needed");
        return false;
    }

    /**
     * Refresh data from API for repositories that need it
     */
    protected function refreshDataFromApi(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null): void
    {
        $repositoriesQuery = Repository::active();
        if ($selectedRepos) {
            $repositoriesQuery->whereIn('full_name', $selectedRepos);
        } else {
            // Limit to prevent timeouts
            $repositoriesQuery->limit(10);
        }
        $repositories = $repositoriesQuery->get();
        
        foreach ($repositories as $repository) {
            try {
                // Refresh commits
                $commits = $this->fetchCommitsFromApi($repository->full_name, $maxDays, $authorFilter);
                $this->storeCommits($repository, $commits);
                
                // Refresh pull requests
                $pullRequests = $this->fetchPullRequestsFromApi($repository->full_name, $maxDays);
                $this->storePullRequests($repository, $pullRequests);
                
            } catch (\Exception $e) {
                \Log::warning("Failed to refresh data for repository {$repository->full_name}: " . $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Get available repositories
     */
    public function getRepositories(): array
    {
        $repositories = [];
        
        foreach ($this->config['workspaces'] as $workspace) {
            $workspace = trim($workspace);
            if (empty($workspace)) continue;
            
            // Start with first page
            $url = "repositories/{$workspace}";
            $params = [
                'role' => 'member',  // Changed from 'contributor' to 'member' to get more repos
                'sort' => 'updated_on',
                'pagelen' => 100  // Max page size
            ];
            $pageCount = 0;
            $maxPages = 1000; // Very high safety limit to prevent infinite loops
            
            do {
                $pageCount++;
                \Log::info("Fetching page {$pageCount} for workspace {$workspace}");
                
                $response = $this->makeRequest($url, $params);
                
                if (isset($response['values'])) {
                    $workspaceRepos = array_map(function($repo) {
                        return [
                            'name' => $repo['name'],
                            'full_name' => $repo['full_name'],
                            'workspace' => $repo['workspace']['slug'],
                            'updated_on' => $repo['updated_on'],
                            'is_private' => $repo['is_private'],
                            'description' => $repo['description'] ?? null,
                            'language' => $repo['language'] ?? null,
                        ];
                    }, $response['values']);
                    
                    $repositories = array_merge($repositories, $workspaceRepos);
                    \Log::info("Page {$pageCount}: Added " . count($workspaceRepos) . " repositories (total: " . count($repositories) . ")");
                }
                
                // Check if there's a next page (with safety limit to prevent infinite loops)
                if (isset($response['next']) && $pageCount < $maxPages) {
                    $url = $response['next']; // Use full URL from next
                    $params = []; // Clear params since they're in the URL
                } else {
                    $url = null;
                    if ($pageCount >= $maxPages) {
                        \Log::warning("Reached maximum page limit ({$maxPages}) for workspace {$workspace}. This might indicate an infinite loop.");
                    }
                }
                
            } while ($url);
        }
        
        \Log::info("Total repositories fetched: " . count($repositories));
        return $repositories;
    }

    /**
     * Get repositories in chunks for better performance
     */
    public function getRepositoriesChunk(int $page = 1, int $perPage = 50): array
    {
        $repositories = [];
        
        foreach ($this->config['workspaces'] as $workspace) {
            $workspace = trim($workspace);
            if (empty($workspace)) continue;
            
            $url = "repositories/{$workspace}";
            $params = [
                'role' => 'member',
                'sort' => 'updated_on',
                'pagelen' => $perPage,
                'page' => $page
            ];
            
            \Log::info("Fetching chunk page {$page} for workspace {$workspace}");
            
            $response = $this->makeRequest($url, $params);
            
            if (isset($response['values'])) {
                $workspaceRepos = array_map(function($repo) {
                    return [
                        'name' => $repo['name'],
                        'full_name' => $repo['full_name'],
                        'workspace' => $repo['workspace']['slug'],
                        'updated_on' => $repo['updated_on'],
                        'is_private' => $repo['is_private'],
                        'description' => $repo['description'] ?? null,
                        'language' => $repo['language'] ?? null,
                    ];
                }, $response['values']);
                
                $repositories = array_merge($repositories, $workspaceRepos);
                \Log::info("Page {$page}: Added " . count($workspaceRepos) . " repositories");
            }
        }
        
        return $repositories;
    }

    /**
     * Fetch commits for a specific repository (for sync commands)
     */
    public function fetchCommitsForRepository(string $repoFullName, int $maxDays = 14): array
    {
        return $this->fetchCommitsFromApi($repoFullName, $maxDays);
    }

    /**
     * Fetch pull requests for a specific repository (for sync commands)
     */
    public function fetchPullRequestsForRepository(string $repoFullName, int $maxDays = 14): array
    {
        return $this->fetchPullRequestsFromApi($repoFullName, $maxDays);
    }

    /**
     * Fetch commits from Bitbucket API
     */
    protected function fetchCommitsFromApi(string $repoFullName, int $maxDays, ?string $authorFilter = null): array
    {
        return $this->fetchCommits($repoFullName, $maxDays, $authorFilter);
    }

    /**
     * Fetch pull requests from Bitbucket API
     */
    protected function fetchPullRequestsFromApi(string $repoFullName, int $maxDays): array
    {
        return $this->fetchPullRequests($repoFullName, $maxDays);
    }

    /**
     * Store commits in local database
     */
    protected function storeCommits(Repository $repository, array $commits): void
    {
        foreach ($commits as $commitData) {
            Commit::updateOrCreate(
                [
                    'repository_id' => $repository->id,
                    'hash' => $commitData['hash']
                ],
                [
                    'commit_date' => $commitData['date'],
                    'message' => $commitData['message'],
                    'author_raw' => $commitData['author_raw'],
                    'author_username' => $commitData['author_username'],
                    'ticket' => $commitData['ticket'],
                    'bitbucket_data' => $commitData,
                    'last_fetched_at' => now()
                ]
            );
        }
    }

    /**
     * Store pull requests in local database
     */
    protected function storePullRequests(Repository $repository, array $pullRequests): void
    {
        foreach ($pullRequests as $prData) {
            PullRequest::updateOrCreate(
                [
                    'repository_id' => $repository->id,
                    'bitbucket_id' => $prData['id']
                ],
                [
                    'title' => $prData['title'],
                    'author_display_name' => $prData['author'],
                    'created_on' => $prData['created_on'],
                    'updated_on' => $prData['updated_on'],
                    'state' => $prData['state'],
                    'ticket' => $prData['ticket'],
                    'bitbucket_data' => $prData,
                    'last_fetched_at' => now()
                ]
            );
        }
    }

    /**
     * Fetch pull requests for a repository
     */
    protected function fetchPullRequests(string $repoFullName, int $maxDays): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        
        $response = $this->makeRequest("repositories/{$repoFullName}/pullrequests", [
            'state' => 'OPEN,MERGED,DECLINED,SUPERSEDED',
            'sort' => '-updated_on',
            'q' => "updated_on>={$since}",
            'fields' => 'values.id,values.title,values.author.display_name,values.created_on,values.updated_on,values.state,values.links.commits.href'
        ]);
        
        if (!isset($response['values'])) {
            return [];
        }
        
        return array_map(function($pr) use ($repoFullName) {
            return [
                'type' => 'pull_request',
                'repository' => $repoFullName,
                'id' => $pr['id'],
                'title' => $pr['title'],
                'author' => $pr['author']['display_name'] ?? null,
                'created_on' => $pr['created_on'],
                'updated_on' => $pr['updated_on'],
                'state' => $pr['state'] ?? null,
                'ticket' => $this->extractTicket($pr['title'])
            ];
        }, array_filter($response['values'], function($pr) {
            return ($pr['author']['display_name'] ?? '') === $this->config['author_display_name'];
        }));
    }

    /**
     * Fetch commits directly from repository
     */
    protected function fetchCommits(string $repoFullName, int $maxDays, ?string $authorFilter = null): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        
        \Log::info("Fetching commits for {$repoFullName} since {$since}");
        
        // Use server-side filtering by username with pagination limit
        $response = $this->makeRequest("repositories/{$repoFullName}/commits", [
            'q' => "date>={$since}",
            'sort' => '-date',
            'pagelen' => 25,  // Limit results to prevent timeouts
            'fields' => 'values.hash,values.date,values.message,values.author.raw,values.author.user.username'
        ]);
        
        if (!isset($response['values'])) {
            \Log::info("No commits found for {$repoFullName}");
            return [];
        }
        
        \Log::info("Found " . count($response['values']) . " total commits for {$repoFullName}");
        
        $commits = array_map(function($commit) use ($repoFullName) {
            return [
                'type' => 'commit',
                'repository' => $repoFullName,
                'hash' => $commit['hash'],
                'date' => $commit['date'],
                'message' => $commit['message'],
                'author_raw' => $commit['author']['raw'] ?? null,
                'author_username' => $commit['author']['user']['username'] ?? null,
                'ticket' => $this->extractTicket($commit['message'])
            ];
        }, $response['values']);
        
        // Filter by author if provided
        if (!empty($authorFilter)) {
            \Log::info("Filtering by author: {$authorFilter}");
            $commits = array_filter($commits, function($commit) use ($authorFilter) {
                $authorName = $commit['author_username'] ?? '';
                $authorEmail = $commit['author_raw'] ?? '';
                return str_contains($authorName, $authorFilter) || str_contains($authorEmail, $authorFilter);
            });
            \Log::info("After filtering: " . count($commits) . " commits for {$repoFullName}");
        }
        
        return $commits;
    }

    /**
     * Test authentication with Bitbucket
     */
    public function testAuthentication(): array
    {
        try {
            $response = $this->makeRequest('user');
            
            return [
                'success' => true,
                'message' => 'Authentication successful',
                'user' => [
                    'username' => $response['username'] ?? null,
                    'display_name' => $response['display_name'] ?? null,
                    'account_id' => $response['account_id'] ?? null,
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Authentication failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Make HTTP request to Bitbucket API
     */
    protected function makeRequest(string $endpoint, array $params = []): array
    {
        // Check if endpoint is already a full URL (for pagination)
        if (str_starts_with($endpoint, 'http')) {
            $url = $endpoint;
        } else {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        }
        
        $client = new Client([
            'auth' => [$this->config['username'], $this->config['token']],
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'Hours-Laravel-API/1.0'
            ],
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify' => true
        ]);
        
        try {
            $response = $client->get($url, ['query' => $params]);
            
            if ($response->getStatusCode() !== 200) {
                throw new \Exception("HTTP {$response->getStatusCode()}: {$response->getReasonPhrase()}");
            }
            
            return json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $message = $e->getMessage();
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $message = "HTTP {$statusCode}: " . $e->getResponse()->getReasonPhrase();
            }
            throw new \Exception("Bitbucket API request failed: " . $message);
        } catch (\Exception $e) {
            throw new \Exception("Bitbucket API request failed: " . $e->getMessage());
        }
    }

    /**
     * Extract ticket reference from text
     */
    protected function extractTicket(string $text): ?string
    {
        // Match patterns like PROJ-123, ABC-456, etc.
        if (preg_match('/([A-Z]{2,10}-\d+)/', $text, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Deduplicate and sort data by date
     */
    protected function deduplicateAndSort(array $data): array
    {
        $seen = [];
        $deduplicated = [];
        
        foreach ($data as $item) {
            $key = $item['type'] === 'commit' 
                ? "commit:{$item['repository']}:{$item['hash']}"
                : "pr:{$item['repository']}:{$item['id']}";
                
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $deduplicated[] = $item;
            }
        }
        
        // Sort by date (newest first)
        usort($deduplicated, function($a, $b) {
            $dateA = $a['type'] === 'commit' ? $a['date'] : $a['updated_on'];
            $dateB = $b['type'] === 'commit' ? $b['date'] : $b['updated_on'];
            
            return strcmp($dateB, $dateA);
        });
        
        return $deduplicated;
    }
}