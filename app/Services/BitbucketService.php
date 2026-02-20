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
    protected ?Client $httpClient = null;

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
    public function fetchAllData(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null, bool $allowSyncRefresh = true): array
    {
        // Try to get data from local database first
        $localData = $this->fetchLocalData($maxDays, $selectedRepos, $authorFilter);
        
        // For now, prioritize local data to prevent timeouts
        // Only refresh if we have no local data at all AND sync refresh is allowed
        if (!empty($localData)) {
            \Log::info("Using local data (" . count($localData) . " items found)");
            return $localData;
        }
        
        if (!$allowSyncRefresh) {
            \Log::info("No local data found, but synchronous refresh is disabled. Returning empty results.");
            return [];
        }
        
        // Only check for API refresh if we have no local data
        \Log::info("No local data found, checking if API refresh is needed");
        $needsRefresh = $this->checkIfDataNeedsRefresh($selectedRepos, $maxDays);
        
        if ($needsRefresh) {
            \Log::info("Refreshing data from API synchronously");
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
        
        \Log::info("fetchLocalData called", ['maxDays' => $maxDays, 'selectedRepos' => $selectedRepos, 'authorFilter' => $authorFilter]);
        
        // Get repositories using the relationship approach (but with debug logging)
        $repositoriesQuery = Repository::active();
        if ($selectedRepos) {
            $repositoriesQuery->whereIn('full_name', $selectedRepos);
        }
        $repositories = $repositoriesQuery->get();
        
        \Log::info("Found repositories", ['count' => $repositories->count(), 'names' => $repositories->pluck('full_name')]);
        
        foreach ($repositories as $repository) {
            \Log::info("Processing repository: {$repository->full_name}");
            
            // Fetch commits from database using relationship
            $commitsQuery = $repository->commits()->recent($maxDays);
            if ($authorFilter) {
                $commitsQuery->byAuthor($authorFilter);
            }
            $commits = $commitsQuery->get();
            
            \Log::info("Found commits for {$repository->full_name}", ['count' => $commits->count()]);
            
            foreach ($commits as $commit) {
                $allData[] = [
                    'type' => 'commit',
                    'repository' => $repository->full_name,
                    'hash' => $commit->hash,
                    'date' => $commit->commit_date->toISOString(),
                    'message' => $commit->message,
                    'author_raw' => $commit->author_raw,
                    'author_username' => $commit->author_username,
                    'ticket' => $commit->ticket,
                    'branch' => $commit->branch,
                    'pull_request_id' => $commit->pull_request_id
                ];
            }
            
            // Fetch pull requests from database
            $prsQuery = $repository->pullRequests()->recent($maxDays);
            if ($authorFilter) {
                $prsQuery->byAuthor($authorFilter);
            }
            $pullRequests = $prsQuery->get();
            
            \Log::info("Found pull requests for {$repository->full_name}", ['count' => $pullRequests->count()]);
            
            foreach ($pullRequests as $pr) {
                $allData[] = [
                    'type' => 'pull_request',
                    'repository' => $repository->full_name,
                    'id' => $pr->bitbucket_id,
                    'hash' => null, // PRs don't have commit hashes
                    'date' => $pr->created_on->toISOString(), // Use created_on as primary date
                    'title' => $pr->title,
                    'message' => $pr->title, // Use title as message for consistency
                    'author' => $pr->author_display_name,
                    'author_raw' => $pr->author_display_name, // Use display name for consistency
                    'author_username' => null, // PRs don't have username
                    'branch' => $this->extractPRBranchInfo($pr) ?? 'Unknown branch', // Extract from stored PR data
                    'created_on' => $pr->created_on->toISOString(),
                    'updated_on' => $pr->updated_on->toISOString(),
                    'state' => $pr->state,
                    'ticket' => $pr->ticket,
                    'pull_request_id' => $pr->bitbucket_id // PRs reference themselves
                ];
            }
        }
        
        // Sort by date (newest first)
        usort($allData, function($a, $b) {
            $dateA = $a['type'] === 'commit' ? $a['date'] : $a['updated_on'];
            $dateB = $b['type'] === 'commit' ? $b['date'] : $b['updated_on'];
            
            return strcmp($dateB, $dateA);
        });
        
        \Log::info("fetchLocalData completed", ['total_items' => count($allData)]);
        
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
            // Check for stale data based on the latest commit's last_fetched_at
            $latestCommit = $repository->commits()
                ->orderBy('last_fetched_at', 'desc')
                ->first();
            
            if (!$latestCommit || !$latestCommit->last_fetched_at) {
                \Log::info("No fetch history for {$repository->full_name}, needs refresh");
                return true;
            }
            
            if ($latestCommit->last_fetched_at->addMinutes($maxAgeMinutes)->isPast()) {
                \Log::info("Data for {$repository->full_name} is stale (last fetched: {$latestCommit->last_fetched_at}), needs refresh");
                return true;
            }
        }
        
        \Log::info("Local data is fresh, no API refresh needed");
        return false;
    }

    /**
     * Refresh data from API for repositories that need it
     */
    public function refreshDataFromApi(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null, ?callable $progressCallback = null, int $maxExecutionTime = 110): void
    {
        $startTime = microtime(true);
        
        $repositoriesQuery = Repository::active();
        if ($selectedRepos) {
            $repositoriesQuery->whereIn('full_name', $selectedRepos);
            // For force refresh, still limit but allow more time
            $repositoriesQuery->limit(10); // Increased from 8 to 10
        } else {
            // Limit to prevent timeouts
            $repositoriesQuery->limit(10); // Increased from 5 to 10
        }
        
        // Priority order: put likely repositories with user commits first
        $priorityRepos = [
            'atabix/atabase-admin-vue',
            'atabix/atabase-accounts-vue', 
            'atabix/atabase-cases-vue',
            'atabix/javascript-packages'
        ];
        
        if ($selectedRepos) {
            // Reorder selected repos to prioritize user's likely repositories
            $repoNames = collect($selectedRepos);
            $prioritized = collect($priorityRepos)->intersect($repoNames);
            $others = $repoNames->diff($prioritized);
            $orderedRepos = $prioritized->merge($others)->values();
            
            $repositoriesQuery = Repository::active()->whereIn('full_name', $orderedRepos->toArray());
            $repositories = $repositoriesQuery->get()->sortBy(function($repo) use ($orderedRepos) {
                return $orderedRepos->search($repo->full_name);
            })->values();
        } else {
            $repositories = $repositoriesQuery->get();
        }
        
        \Log::info("Starting API refresh for " . count($repositories) . " repositories", [
            'repo_order' => $repositories->pluck('full_name'),
            'max_execution_time' => $maxExecutionTime
        ]);
        
        // Notify progress callback about start
        if ($progressCallback) {
            $progressCallback("Starting refresh of " . count($repositories) . " repositories...");
        }
        
        foreach ($repositories as $index => $repository) {
            // Check if we're approaching timeout limit
            $currentExecutionTime = microtime(true) - $startTime;
            if ($currentExecutionTime > $maxExecutionTime) {
                \Log::warning("API refresh timeout approaching, stopping after {$index} repositories", ['execution_time' => $currentExecutionTime]);
                break;
            }
            
            try {
                \Log::info("Refreshing data for repository: {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ")");
                
                // Notify progress
                if ($progressCallback) {
                    $progressCallback("Processing {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ") - Fetching pull requests...");
                }
                
                // First, fetch pull requests 
                $pullRequests = $this->fetchPullRequestsFromApi($repository->full_name, $maxDays);
                $this->storePullRequests($repository, $pullRequests);
                
                // Notify progress
                if ($progressCallback) {
                    $progressCallback("Processing {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ") - Fetching PR commits...");
                }
                
                // Then fetch commits from each pull request (captures feature branch commits)
                $prCommits = $this->fetchCommitsFromPullRequests($repository->full_name, $pullRequests, $maxDays, $authorFilter);
                $this->storeCommits($repository, $prCommits);
                
                // Notify progress
                if ($progressCallback) {
                    $progressCallback("Processing {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ") - Fetching main branch commits...");
                }
                
                // Fetch regular repository commits only from main branches since PR commits already cover feature branches
                // This drastically reduces API calls and prevents timeouts
                $mainBranches = ['main', 'master', 'develop', 'dev'];
                $repoCommits = $this->fetchCommitsFromApi($repository->full_name, $maxDays, $authorFilter, $mainBranches);
                $this->storeCommits($repository, $repoCommits);
                
                // If an author filter is provided, also fetch their commits across the ENTIRE repository
                // This captures feature branch activity that doesn't have a PR yet
                if (!empty($authorFilter)) {
                    if ($progressCallback) {
                        $progressCallback("Processing {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ") - Fetching author activity...");
                    }
                    $authorCommits = $this->fetchAuthorCommitsFromApi($repository->full_name, $maxDays, $authorFilter);
                    $this->storeCommits($repository, $authorCommits);
                }
                
                \Log::info("Refreshed {$repository->full_name}: " . count($prCommits) . " PR commits, " . count($repoCommits) . " repo commits, " . count($pullRequests) . " pull requests");
                
                // Notify completion of this repository
                if ($progressCallback) {
                    $progressCallback("Completed {$repository->full_name} (" . ($index + 1) . "/" . count($repositories) . ") - " . (count($prCommits) + count($repoCommits)) . " total commits, " . count($pullRequests) . " pull requests");
                }
                
            } catch (\Exception $e) {
                \Log::warning("Failed to refresh data for repository {$repository->full_name}: " . $e->getMessage());
                continue;
            }
        }
        
        $totalExecutionTime = microtime(true) - $startTime;
        \Log::info("API refresh completed in " . round($totalExecutionTime, 2) . " seconds");
        
        // Notify final completion
        if ($progressCallback) {
            $progressCallback("Refresh completed in " . round($totalExecutionTime, 2) . " seconds!");
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
    public function fetchCommitsForRepository(string $repoFullName, int $maxDays = 14, ?array $branches = null): array
    {
        if ($branches === null) {
            $branches = ['main', 'master', 'develop', 'dev'];
        }
        return $this->fetchCommitsFromApi($repoFullName, $maxDays, null, $branches);
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
    protected function fetchCommitsFromApi(string $repoFullName, int $maxDays, ?string $authorFilter = null, ?array $branches = null): array
    {
        return $this->fetchCommits($repoFullName, $maxDays, $authorFilter, $branches);
    }

    /**
     * Fetch author activity across all branches for a repository
     */
    public function fetchAuthorActivityForRepository(string $repoFullName, int $maxDays, string $authorFilter): array
    {
        return $this->fetchAuthorCommitsFromApi($repoFullName, $maxDays, $authorFilter);
    }

    /**
     * Fetch commits for a specific author across the entire repository (all branches)
     */
    protected function fetchAuthorCommitsFromApi(string $repoFullName, int $maxDays, string $authorFilter): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        
        \Log::info("Fetching author commits across all branches for {$repoFullName} since {$since} (Author: {$authorFilter})");
        
        $allCommits = [];
        
        // Escape quotes for safely embedding in the query
        $escapedFilter = str_replace('"', '\"', $authorFilter);
        
        // This query works on the base /commits endpoint to find activity across ALL branches
        $q = "date>=\"{$since}\" AND (author.raw ~ \"{$escapedFilter}\" OR author.user.username = \"{$escapedFilter}\")";
        
        $params = [
            'q' => $q,
            'sort' => '-date', 
            'pagelen' => 100,
            'fields' => 'values.hash,values.date,values.message,values.author.raw,values.author.user.username,next'
        ];
        
        try {
            $response = $this->makeRequest("repositories/{$repoFullName}/commits", $params);
            
            if (isset($response['values'])) {
                foreach ($response['values'] as $commit) {
                    $allCommits[] = [
                        'type' => 'commit',
                        'repository' => $repoFullName,
                        'hash' => $commit['hash'],
                        'date' => $commit['date'],
                        'message' => $commit['message'],
                        'author_raw' => $commit['author']['raw'] ?? null,
                        'author_username' => $commit['author']['user']['username'] ?? null,
                        'ticket' => $this->extractTicket($commit['message']),
                        'branch' => $this->extractBranchFromCommitMessage($commit['message']) ?? 'main'
                    ];
                }
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to fetch author commits across repo for {$repoFullName}: " . $e->getMessage());
        }
        
        \Log::info("Found " . count($allCommits) . " cross-branch commits for author in {$repoFullName}");

        // Local filtering to ensure we ONLY have commits from the correct author
        // Bitbucket's 'q' parameter can sometimes be broader than expected
        if (!empty($authorFilter) && !empty($allCommits)) {
            $originalCount = count($allCommits);
            $allCommits = array_filter($allCommits, function($commit) use ($authorFilter) {
                $authorRaw = $commit['author_raw'] ?? '';
                $authorUsername = $commit['author_username'] ?? '';
                return str_contains($authorRaw, $authorFilter) || str_contains($authorUsername, $authorFilter);
            });
            $allCommits = array_values($allCommits); // Reindex
            
            if (count($allCommits) !== $originalCount) {
                \Log::info("Locally filtered author commits for {$repoFullName}: {$originalCount} -> " . count($allCommits));
            }
        }
        
        return $allCommits;
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
        \Log::info("storeCommits called for {$repository->full_name}", ['commit_count' => count($commits)]);
        
        foreach ($commits as $index => $commitData) {
            try {
                $commit = Commit::updateOrCreate(
                    [
                        'repository_id' => $repository->id,
                        'hash' => $commitData['hash']
                    ],
                    [
                        'commit_date' => $commitData['date'],
                        'repository' => $repository->full_name, // Add this for debugging
                        'message' => $commitData['message'],
                        'author_raw' => $commitData['author_raw'],
                        'author_username' => $commitData['author_username'],
                        'ticket' => $commitData['ticket'],
                        'branch' => $commitData['branch'] ?? 'main',
                        'pull_request_id' => $commitData['from_pull_request'] ?? null,
                        'bitbucket_data' => $commitData,
                        'last_fetched_at' => now()
                    ]
                );
                
                if ($index < 3) { // Log first 3 commits for debugging
                    \Log::info("Stored commit for {$repository->full_name}", [
                        'hash' => $commitData['hash'],
                        'author' => $commitData['author_raw'],
                        'date' => $commitData['date'],
                        'saved_id' => $commit->id
                    ]);
                }
                
            } catch (\Exception $e) {
                \Log::error("Failed to store commit for {$repository->full_name}", [
                    'hash' => $commitData['hash'],
                    'error' => $e->getMessage(),
                    'commit_data' => $commitData
                ]);
            }
        }
        
        \Log::info("storeCommits completed for {$repository->full_name}", ['processed' => count($commits)]);
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
    /**
     * Fetch pull requests with pagination (like the old tool)
     */
    protected function fetchPullRequests(string $repoFullName, int $maxDays): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        
        \Log::info("Fetching pull requests for {$repoFullName} since {$since}");
        
        $allPullRequests = [];
        $currentUrl = "repositories/{$repoFullName}/pullrequests";
        $maxPages = 10; // Limit to prevent excessive API calls
        $pageCount = 0;
        
        while ($currentUrl && $pageCount < $maxPages) {
            $params = [
                'state' => 'OPEN,MERGED,DECLINED,SUPERSEDED',
                'sort' => '-updated_on',
                'q' => "updated_on>={$since}",
                'pagelen' => 50, // Higher limit to get more PRs
                'fields' => 'values.id,values.title,values.author.display_name,values.created_on,values.updated_on,values.state,values.source.branch.name,values.destination.branch.name,values.links.commits.href,next'
            ];
            
            $response = $this->makeRequest($currentUrl, $params);
            
            if (!isset($response['values'])) {
                break;
            }
            
            \Log::info("Found " . count($response['values']) . " pull requests on page " . ($pageCount + 1) . " for {$repoFullName}");
            
            foreach ($response['values'] as $pr) {
                $allPullRequests[] = [
                    'type' => 'pull_request',
                    'repository' => $repoFullName,
                    'bitbucket_id' => $pr['id'], // Store this for commits endpoint
                    'id' => $pr['id'],
                    'title' => $pr['title'],
                    'author' => $pr['author']['display_name'] ?? null,
                    'created_on' => $pr['created_on'],
                    'updated_on' => $pr['updated_on'], 
                    'state' => $pr['state'] ?? null,
                    'source_branch' => $pr['source']['branch']['name'] ?? null,
                    'destination_branch' => $pr['destination']['branch']['name'] ?? null,
                    'ticket' => $this->extractTicket($pr['title'])
                ];
            }
            
            // Check for next page
            $currentUrl = $response['next'] ?? null;
            if ($currentUrl) {
                // Extract just the path from the full URL for API consistency
                $currentUrl = parse_url($currentUrl, PHP_URL_PATH) . '?' . parse_url($currentUrl, PHP_URL_QUERY);
                $currentUrl = ltrim($currentUrl, '/2.0/');
            }
            
            $pageCount++;
        }
        
        \Log::info("Total pull requests fetched for {$repoFullName}: " . count($allPullRequests));
        
        return $allPullRequests;
    }

    /**
     * Fetch commits directly from repository with proper branch detection
     */
    protected function fetchCommits(string $repoFullName, int $maxDays, ?string $authorFilter = null, ?array $branches = null): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        
        \Log::info("Fetching commits for {$repoFullName} since {$since}");
        
        $allCommits = [];
        
        // First, get branches to fetch from
        if ($branches === null) {
            $branches = $this->getRepositoryBranches($repoFullName);
        } else {
            // If specific branches provided, filter to only those that actually exist
            $existingBranches = $this->getRepositoryBranches($repoFullName);
            $branches = array_intersect($branches, $existingBranches);
            if (empty($branches) && !empty($existingBranches)) {
                $branches = [$existingBranches[0]]; // Fallback to at least one branch
            }
        }
        
        foreach ($branches as $branch) {
            \Log::info("Fetching commits from branch '{$branch}' for {$repoFullName}");
            
            $currentUrl = "repositories/{$repoFullName}/commits/{$branch}";
            $maxPages = 2; // Limit per branch to prevent excessive API calls
            $pageCount = 0;
            
            while ($currentUrl && $pageCount < $maxPages) {
                // Build query with date and author filter
                $q = "date>={$since}";
                if (!empty($authorFilter)) {
                    // Escape quotes for safely embedding in the query
                    $escapedFilter = str_replace('"', '\"', $authorFilter);
                    $q .= " AND (author.raw ~ \"{$escapedFilter}\" OR author.user.username = \"{$escapedFilter}\")";
                }

                $params = [
                    'q' => $q,
                    'sort' => '-date', 
                    'pagelen' => 50,  // Smaller page size per branch
                    'fields' => 'values.hash,values.date,values.message,values.author.raw,values.author.user.username,values.repository.full_name,next'
                ];
                
                $response = $this->makeRequest($currentUrl, $params);
                
                if (!isset($response['values'])) {
                    break;
                }
                
                \Log::info("Found " . count($response['values']) . " commits on page " . ($pageCount + 1) . " for branch '{$branch}' in {$repoFullName}");
                
                foreach ($response['values'] as $commit) {
                    // Check if we already have this commit from another branch
                    $existingCommit = array_filter($allCommits, function($c) use ($commit) {
                        return $c['hash'] === $commit['hash'];
                    });
                    
                    if (!empty($existingCommit)) {
                        // If this commit exists from a feature branch, keep the feature branch
                        // If this commit exists from main/master/dev, update to more specific branch
                        $existing = array_values($existingCommit)[0];
                        $existingBranch = $existing['branch'];
                        
                        if (in_array($existingBranch, ['main', 'master', 'develop', 'dev']) && 
                            !in_array($branch, ['main', 'master', 'develop', 'dev'])) {
                            // Update to the more specific feature branch
                            $key = array_search($existing, $allCommits);
                            if ($key !== false) {
                                $allCommits[$key]['branch'] = $branch;
                            }
                        }
                        continue; // Skip duplicate commits
                    }
                    
                    $allCommits[] = [
                        'type' => 'commit',
                        'repository' => $repoFullName,
                        'hash' => $commit['hash'],
                        'date' => $commit['date'],
                        'message' => $commit['message'],
                        'author_raw' => $commit['author']['raw'] ?? null,
                        'author_username' => $commit['author']['user']['username'] ?? null,
                        'ticket' => $this->extractTicket($commit['message']),
                        'branch' => $branch  // Use actual branch name
                    ];
                }
                
                // Check for next page
                $currentUrl = $response['next'] ?? null;
                if ($currentUrl) {
                    // Extract just the path from the full URL for API consistency
                    $currentUrl = parse_url($currentUrl, PHP_URL_PATH) . '?' . parse_url($currentUrl, PHP_URL_QUERY);
                    $currentUrl = ltrim($currentUrl, '/2.0/');
                }
                
                $pageCount++;
            }
        }
        
        \Log::info("Total commits fetched for {$repoFullName}: " . count($allCommits));
        
        // Log date range for debugging
        $since = now()->subDays($maxDays)->format('Y-m-d');
        \Log::info("Date filter range: since {$since}, commits in date range: " . count($allCommits));
        
        // Filter by author if provided
        if (!empty($authorFilter)) {
            \Log::info("Filtering by author: {$authorFilter}");
            $originalCount = count($allCommits);
            $allCommits = array_filter($allCommits, function($commit) use ($authorFilter) {
                $authorRaw = $commit['author_raw'] ?? '';
                $authorUsername = $commit['author_username'] ?? '';
                
                // Log first few commits to debug the format
                static $debugCount = 0;
                if ($debugCount < 5) {
                    \Log::info("Commit author debug", [
                        'debug_count' => $debugCount + 1,
                        'author_raw' => $authorRaw,
                        'author_username' => $authorUsername,
                        'filter' => $authorFilter,
                        'raw_match' => str_contains($authorRaw, $authorFilter),
                        'username_match' => str_contains($authorUsername, $authorFilter)
                    ]);
                    $debugCount++;
                }
                
                return str_contains($authorRaw, $authorFilter) || str_contains($authorUsername, $authorFilter);
            });
            \Log::info("After filtering: " . count($allCommits) . " commits for {$repoFullName} (filtered {$originalCount} -> " . count($allCommits) . ")");
        }
        
        return $allCommits;
    }

    /**
     * Fetch commits from pull requests (captures feature branch commits)
     */
    protected function fetchCommitsFromPullRequests(string $repoFullName, array $pullRequests, int $maxDays, ?string $authorFilter = null): array
    {
        $since = now()->subDays($maxDays)->format('Y-m-d');
        $allCommits = [];
        
        \Log::info("Fetching commits from " . count($pullRequests) . " pull requests for {$repoFullName}");
        
        foreach ($pullRequests as $pullRequest) {
            try {
                // Build query for this PR's commits
                $q = "date>={$since}";
                if (!empty($authorFilter)) {
                    $escapedFilter = str_replace('"', '\"', $authorFilter);
                    $q .= " AND (author.raw ~ \"{$escapedFilter}\" OR author.user.username = \"{$escapedFilter}\")";
                }

                // Get commits for this pull request with filtering
                $response = $this->makeRequest("repositories/{$repoFullName}/pullrequests/{$pullRequest['bitbucket_id']}/commits", [
                    'q' => $q,
                    'pagelen' => 100,
                    'fields' => 'values.hash,values.date,values.message,values.author.raw,values.author.user.username,next'
                ]);
                
                if (!isset($response['values']) || empty($response['values'])) {
                    continue;
                }
                
                // Use actual source branch from PR data, with fallbacks
                $branchName = $pullRequest['source_branch'] ?? 
                             $this->extractBranchFromPRTitle($pullRequest['title']) ?? 
                             $this->extractBranchFromCommitMessage($pullRequest['title']) ?? 'main';
                
                foreach ($response['values'] as $commit) {
                    $commitData = [
                        'type' => 'commit',
                        'repository' => $repoFullName,
                        'hash' => $commit['hash'],
                        'date' => $commit['date'],
                        'message' => $commit['message'],
                        'author_raw' => $commit['author']['raw'] ?? null,
                        'author_username' => $commit['author']['user']['username'] ?? null,
                        'ticket' => $this->extractTicket($commit['message']),
                        'branch' => $branchName,
                        'from_pull_request' => $pullRequest['bitbucket_id'],
                        'pr_source_branch' => $pullRequest['source_branch'] ?? null,
                        'pr_destination_branch' => $pullRequest['destination_branch'] ?? null
                    ];
                    
                    $allCommits[] = $commitData;
                }
                
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch commits for PR {$pullRequest['bitbucket_id']} in {$repoFullName}: " . $e->getMessage());
                continue;
            }
        }
        
        \Log::info("Fetched " . count($allCommits) . " commits from pull requests for {$repoFullName}");
        
        return $allCommits;
    }

    /**
     * Extract branch name from PR title (often contains branch names)
     */
    protected function extractBranchFromPRTitle(string $title): ?string
    {
        // PR titles often start with the branch name
        $patterns = [
            '/^(vue3\/[^\s\-]+)/i',           // vue3/feature-name
            '/^(feature\/[^\s\-]+)/i',       // feature/ASUITE-123
            '/^(hotfix\/[^\s\-]+)/i',        // hotfix/something
            '/^(bugfix\/[^\s\-]+)/i',        // bugfix/something
            '/^(release\/[^\s\-]+)/i',       // release/something
            '/^([A-Z]+-\d+[^\s]*)/i',        // ASUITE-123 or ASUITE-123-description
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
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
        
        if (!$this->httpClient) {
            $this->httpClient = new Client([
                'auth' => [$this->config['username'], $this->config['token']],
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => 'Hours-Laravel-API/1.0'
                ],
                'timeout' => 20, // Increased slightly from 15 to 20 seconds
                'connect_timeout' => 5,
                'verify' => true
            ]);
        }
        
        try {
            $response = $this->httpClient->get($url, ['query' => $params]);
            
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

    /**
     * Extract branch name from commit message
     */
    protected function extractBranchFromCommitMessage(string $message): ?string
    {
        // Common patterns for branch names in commit messages
        $patterns = [
            // Merge commit patterns
            '/Merge branch \'([^\']+)\'/i',
            '/Merge branch "([^"]+)"/i', 
            '/Merge branch ([^\s]+)/i',
            
            // Pull request merge patterns
            '/Merged in ([^\/\s]+)/i', // Bitbucket style
            '/Merge pull request .* from ([^\s]+)/i', // GitHub style
            
            // Feature branch patterns in commit messages
            '/\b(feature\/[^\s\)]+)/i',
            '/\b(hotfix\/[^\s\)]+)/i', 
            '/\b(bugfix\/[^\s\)]+)/i',
            '/\b(release\/[^\s\)]+)/i',
            '/\b(vue3\/[^\s\)]+)/i', // Your vue3 branches
            
            // JIRA ticket branches
            '/\b([A-Z]+-\d+[^\s\)]*)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                $branch = trim($matches[1]);
                
                // Clean up common branch name artifacts
                $branch = rtrim($branch, '.,;:');
                $branch = str_replace(' into ', '', $branch);
                
                // Skip if it's just a ticket number without branch context
                if (preg_match('/^[A-Z]+-\d+$/', $branch) && !str_contains($message, 'branch')) {
                    continue;
                }
                
                // Return the first valid branch name found
                if (strlen($branch) > 0 && strlen($branch) < 100) { // Reasonable length
                    return $branch;
                }
            }
        }
        
        return null; // No branch detected
    }

    /**
     * Get the primary branch from a list of branches (optimized) 
     */
    protected function getPrimaryBranch(array $branches): string
    {
        if (empty($branches)) {
            return 'main';
        }
        
        // Priority order for common branch names
        $priority = ['main', 'master', 'develop', 'dev', 'development'];
        
        foreach ($priority as $priorityBranch) {
            if (in_array($priorityBranch, $branches)) {
                return $priorityBranch;
            }
        }
        
        // If no priority branch found, return the first one
        return $branches[0];
    }

    /**
     * Get repository branches
     */
    protected function getRepositoryBranches(string $repoFullName): array
    {
        $cacheKey = "repo_branches:{$repoFullName}";
        
        return Cache::remember($cacheKey, 60 * 60 * 4, function() use ($repoFullName) { // Cache for 4 hours
            try {
                \Log::info("Fetching branches for {$repoFullName}");
                
                $response = $this->makeRequest("repositories/{$repoFullName}/refs/branches", [
                    'pagelen' => 100,
                    'sort' => '-target.date',  // Sort by most recent commits
                    'fields' => 'values.name,values.target.date'
                ]);
                
                if (!isset($response['values'])) {
                    return ['main']; // fallback
                }
                
                $branches = array_map(function($branch) {
                    return $branch['name'];
                }, $response['values']);
                
                // Limit to most important branches to avoid too many API calls
                $priorityBranches = [];
                $otherBranches = [];
                
                foreach ($branches as $branch) {
                    if (in_array($branch, ['main', 'master', 'develop', 'dev', 'staging', 'production']) ||
                        preg_match('/^(feature|hotfix|bugfix|release|vue3)\//i', $branch)) {
                        $priorityBranches[] = $branch;
                    } else {
                        $otherBranches[] = $branch;
                    }
                }
                
                // Return priority branches + up to 5 other recent branches
                $result = array_merge($priorityBranches, array_slice($otherBranches, 0, 5));
                
                \Log::info("Found " . count($result) . " branches for {$repoFullName}: " . implode(', ', $result));
                
                return empty($result) ? ['main'] : $result;
                
            } catch (\Exception $e) {
                \Log::warning("Failed to fetch branches for {$repoFullName}: " . $e->getMessage());
                return ['main', 'develop', 'dev']; // Common fallbacks
            }
        });
    }
    
    /**
     * Get main branch name for repository (simplified)
     */
    protected function getMainBranch(string $repoFullName): string
    {
        $branches = $this->getRepositoryBranches($repoFullName);
        
        // Find the most likely main branch
        $mainBranchCandidates = ['main', 'master', 'develop', 'dev'];
        
        foreach ($mainBranchCandidates as $candidate) {
            if (in_array($candidate, $branches)) {
                return $candidate;
            }
        }
        
        // If no standard main branch found, return the first branch
        return $branches[0] ?? 'main';
    }

    /**
     * Extract branch information from pull request data
     */
    protected function extractPRBranchInfo($pr): ?string
    {
        // Try to get branch info from the Bitbucket data first
        if (isset($pr->bitbucket_data) && is_array($pr->bitbucket_data)) {
            $data = $pr->bitbucket_data;
            
            // Return source branch if available (this is the main field we should have)
            if (isset($data['source_branch']) && !empty($data['source_branch'])) {
                return $data['source_branch'];
            }
            
            // Try alternative field names for backwards compatibility
            if (isset($data['pr_source_branch']) && !empty($data['pr_source_branch'])) {
                return $data['pr_source_branch'];
            }
            
            // Handle old data format where full PR data might be stored
            if (isset($data['source'], $data['source']['branch'], $data['source']['branch']['name'])) {
                return $data['source']['branch']['name'];
            }
        }
        
        // Try to extract from PR title as fallback
        if (isset($pr->title)) {
            $branchFromTitle = $this->extractBranchFromPRTitle($pr->title);
            if ($branchFromTitle) {
                return $branchFromTitle;
            }
        }
        
        // Log when we can't find branch info for debugging
        \Log::warning("Could not extract branch info for PR: {$pr->title} (ID: {$pr->bitbucket_id})");
        
        // Final fallback - return null to indicate we don't know
        return null;
    }
}