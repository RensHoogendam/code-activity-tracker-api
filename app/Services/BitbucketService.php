<?php

namespace App\Services;

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
     * Fetch all commits and pull requests
     */
    public function fetchAllData(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null): array
    {
        $repositories = $this->getRepositories();
        
        if ($selectedRepos) {
            $repositories = array_filter($repositories, fn($repo) => 
                in_array($repo['full_name'], $selectedRepos)
            );
        } else {
            // Limit to most recently updated repositories to prevent timeouts
            $repositories = array_slice($repositories, 0, 10);
        }

        $allData = [];
        
        foreach ($repositories as $repo) {
            try {
                // Fetch pull requests
                $pullRequests = $this->fetchPullRequests($repo['full_name'], $maxDays);
                $allData = array_merge($allData, $pullRequests);
                
                // Fetch commits directly from repository
                $commits = $this->fetchCommits($repo['full_name'], $maxDays, $authorFilter);
                $allData = array_merge($allData, $commits);
            } catch (\Exception $e) {
                // Log error but continue with other repositories
                \Log::warning("Failed to fetch data from repository {$repo['full_name']}: " . $e->getMessage());
                continue;
            }
        }

        // Deduplicate and sort by date
        $allData = $this->deduplicateAndSort($allData);
        
        return $allData;
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