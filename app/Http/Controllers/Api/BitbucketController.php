<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshBitbucketDataJob;
use App\Models\User;
use App\Services\BitbucketService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BitbucketController extends Controller
{
    protected BitbucketService $bitbucketService;

    public function __construct(BitbucketService $bitbucketService)
    {
        $this->bitbucketService = $bitbucketService;
    }

    /**
     * Get all commits and pull requests for time tracking
     */
    public function getCommitsAndPullRequests(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'integer|min:1|max:365',
            'repositories' => 'array',
            'repositories.*' => 'string',
            'author' => 'string|nullable',
            'force_refresh' => 'sometimes|in:true,false,1,0,"true","false","1","0"'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 400);
        }

        $days = $request->input('days', 14);
        $repositories = $request->input('repositories');
        $author = $request->input('author');
        $forceRefresh = filter_var($request->input('force_refresh', false), FILTER_VALIDATE_BOOLEAN);

        // Set max execution time for force refresh to prevent server timeout
        if ($forceRefresh) {
            set_time_limit(60); // Max 60 seconds for force refresh
        }

        // If no repositories specified, load user's enabled repositories
        if (empty($repositories)) {
            $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
            
            if ($user) {
                $repositories = $user->repositories()
                                   ->wherePivot('is_enabled', true)
                                   ->pluck('full_name')
                                   ->toArray();
                                   
                if (empty($repositories)) {
                    return response()->json([
                        'error' => 'No enabled repositories found for user',
                        'message' => 'Please enable some repositories first via PATCH /api/repositories/user'
                    ], 400);
                }
            } else {
                return response()->json([
                    'error' => 'User not found',
                    'message' => 'Please setup your repositories first via PATCH /api/repositories/user'
                ], 404);
            }
        }

        $cacheKey = 'bitbucket_data_' . md5(json_encode([
            'days' => $days,
            'repositories' => $repositories,
            'author' => $author
        ]));

        if (!$forceRefresh && Cache::has($cacheKey)) {
            return response()->json([
                'data' => Cache::get($cacheKey),
                'cached' => true,
                'cache_expires_at' => Cache::get($cacheKey . '_expires'),
                'repositories_used' => $repositories,
                'refresh_status' => $this->getRefreshStatus()
            ]);
        }

        try {
            Log::info("Starting fetchAllData", [
                'days' => $days, 
                'repositories_count' => count($repositories), 
                'author' => $author, 
                'force_refresh' => $forceRefresh
            ]);
            
            if ($forceRefresh) {
                // Start background job for refresh
                $jobId = uniqid('refresh_', true);
                
                // Set initial status before dispatching job
                $initialStatus = [
                    'status' => 'started',
                    'message' => 'Refresh job queued for processing',
                    'updated_at' => now()->toISOString(),
                    'job_id' => $jobId
                ];
                Cache::put("refresh_job_status:{$jobId}", $initialStatus, now()->addHour());
                Cache::put('latest_refresh_status', $initialStatus, now()->addHour());
                
                $job = new RefreshBitbucketDataJob($days, $repositories, $author, $jobId);
                dispatch($job);
                
                Log::info("Started background refresh job", [
                    'job_id' => $jobId,
                    'days' => $days,
                    'repositories' => $repositories,
                    'author' => $author
                ]);
                
                // Return current data immediately with refresh status
                $currentData = $this->bitbucketService->fetchAllData($days, $repositories, $author, false);
                
                return response()->json([
                    'data' => $currentData,
                    'cached' => false,
                    'refresh_status' => [
                        'status' => 'started',
                        'message' => 'Background refresh started - fresh data will be available shortly',
                        'job_id' => $jobId,
                        'started_at' => now()->toISOString(),
                        'status_url' => url("api/bitbucket/refresh-status/{$jobId}")
                    ],
                    'repositories_used' => $repositories
                ]);
            }
            
            $data = $this->bitbucketService->fetchAllData($days, $repositories, $author, false);
            
            Log::info("fetchAllData completed", ['data_count' => count($data)]);
            
            // Cache for 30 minutes
            $expiresAt = now()->addMinutes(30);
            Cache::put($cacheKey, $data, $expiresAt);
            Cache::put($cacheKey . '_expires', $expiresAt->toISOString(), $expiresAt);

            return response()->json([
                'data' => $data,
                'cached' => false,
                'cache_expires_at' => $expiresAt->toISOString(),
                'repositories_used' => $repositories,
                'refresh_status' => $this->getRefreshStatus()
            ]);

        } catch (\Exception $e) {
            Log::error("fetchAllData failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'days' => $days,
                'repositories_count' => count($repositories),
                'force_refresh' => $forceRefresh
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch Bitbucket data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current refresh status from cache
     */
    public function getLatestRefreshStatus(): JsonResponse
    {
        $status = Cache::get('latest_refresh_status');
        
        if (!$status) {
            return response()->json([
                'message' => 'No recent refresh jobs found',
                'status' => 'none',
                'refresh_status' => null
            ]);
        }
        
        // Calculate how long ago the status was updated
        $updatedAt = isset($status['updated_at']) ? new \DateTime($status['updated_at']) : null;
        $now = new \DateTime();
        $timeSinceUpdate = $updatedAt ? $now->diff($updatedAt) : null;
        
        return response()->json([
            'job_id' => $status['job_id'] ?? null,
            'status' => $status['status'] ?? 'unknown',
            'message' => $status['message'] ?? 'No message available',
            'updated_at' => $status['updated_at'] ?? null,
            'elapsed_time' => $status['elapsed_time'] ?? null,
            'elapsed_time_human' => $status['elapsed_time_human'] ?? null,
            'parameters' => $status['parameters'] ?? null,
            'time_since_update' => $timeSinceUpdate ? [
                'human_readable' => $this->formatTimeDifference($timeSinceUpdate),
                'seconds' => ($timeSinceUpdate->days * 86400) + ($timeSinceUpdate->h * 3600) + ($timeSinceUpdate->i * 60) + $timeSinceUpdate->s
            ] : null,
            'is_running' => in_array($status['status'] ?? '', ['processing', 'started']),
            'is_completed' => ($status['status'] ?? '') === 'completed',
            'is_failed' => ($status['status'] ?? '') === 'failed',
            'is_cancelled' => ($status['status'] ?? '') === 'cancelled'
        ]);
    }

    /**
     * Get current refresh status from cache (protected method for internal use)
     */
    protected function getRefreshStatus(): ?array
    {
        return Cache::get('latest_refresh_status');
    }

    /**
     * Start a background refresh job
     */
    public function startRefreshJob(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'integer|min:1|max:365',
            'repositories' => 'array',
            'repositories.*' => 'string',
            'author' => 'string|nullable'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $days = $request->get('days', 14);
        $repositories = $request->get('repositories', []);
        $author = $request->get('author');

        try {
            // Start background job for refresh
            $jobId = uniqid('refresh_', true);
            
            // Set initial status before dispatching job
            $initialStatus = [
                'status' => 'started',
                'message' => 'Refresh job queued for processing',
                'updated_at' => now()->toISOString(),
                'job_id' => $jobId
            ];
            Cache::put("refresh_job_status:{$jobId}", $initialStatus, now()->addHour());
            Cache::put('latest_refresh_status', $initialStatus, now()->addHour());
            
            $job = new RefreshBitbucketDataJob($days, $repositories, $author, $jobId);
            dispatch($job);
            
            Log::info("Started background refresh job via API", [
                'job_id' => $jobId,
                'days' => $days,
                'repositories' => $repositories,
                'author' => $author
            ]);
            
            return response()->json([
                'message' => 'Refresh job started successfully',
                'job_id' => $jobId,
                'status' => 'started',
                'status_url' => url("api/bitbucket/refresh-status/{$jobId}"),
                'parameters' => [
                    'days' => $days,
                    'repositories' => $repositories,
                    'author' => $author
                ],
                'started_at' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to start refresh job", [
                'error' => $e->getMessage(),
                'days' => $days,
                'repositories' => $repositories,
                'author' => $author
            ]);
            
            return response()->json([
                'error' => 'Failed to start refresh job',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a refresh job
     */
    public function cancelRefreshJob(string $jobId): JsonResponse
    {
        try {
            // Validate job ID format
            if (!preg_match('/^refresh_[a-f0-9]+\.\d+$/', $jobId)) {
                return response()->json([
                    'error' => 'Invalid job ID format',
                    'job_id' => $jobId
                ], 400);
            }

            // Get current job status
            $cacheKey = "refresh_job_status:{$jobId}";
            $currentStatus = Cache::get($cacheKey);
            
            if (!$currentStatus) {
                return response()->json([
                    'error' => 'Job not found or already expired',
                    'job_id' => $jobId,
                    'message' => 'Job may have completed and expired (jobs are cached for 1 hour), or the job ID is invalid'
                ], 404);
            }

            // Check if job is already completed or failed
            if (in_array($currentStatus['status'], ['completed', 'failed', 'cancelled'])) {
                return response()->json([
                    'error' => 'Cannot cancel job',
                    'job_id' => $jobId,
                    'current_status' => $currentStatus['status'],
                    'message' => "Job is already {$currentStatus['status']} and cannot be cancelled"
                ], 400);
            }

            // Try to find and delete the job from the queue (if it's still queued)
            $deletedFromQueue = false;
            
            try {
                // Look for the job in the jobs table
                $queuedJob = DB::table('jobs')
                    ->where('payload', 'like', "%{$jobId}%")
                    ->first();

                if ($queuedJob) {
                    // Delete the job from the queue
                    DB::table('jobs')->where('id', $queuedJob->id)->delete();
                    $deletedFromQueue = true;
                    Log::info("Deleted queued job from database", [
                        'job_id' => $jobId,
                        'queue_job_id' => $queuedJob->id
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning("Failed to delete job from queue", [
                    'job_id' => $jobId,
                    'error' => $e->getMessage()
                ]);
            }

            // Update job status to cancelled
            $cancelledStatus = [
                'status' => 'cancelled',
                'message' => 'Job was cancelled by user request',
                'updated_at' => now()->toISOString(),
                'job_id' => $jobId,
                'cancelled_at' => now()->toISOString(),
                'parameters' => $currentStatus['parameters'] ?? null
            ];

            // Store cancelled status for 1 hour
            Cache::put($cacheKey, $cancelledStatus, now()->addHour());
            
            // Update latest refresh status if this was the latest
            $latestStatus = Cache::get('latest_refresh_status');
            if ($latestStatus && $latestStatus['job_id'] === $jobId) {
                Cache::put('latest_refresh_status', $cancelledStatus, now()->addHour());
            }

            Log::info("Cancelled refresh job", [
                'job_id' => $jobId,
                'was_queued' => $deletedFromQueue,
                'previous_status' => $currentStatus['status']
            ]);

            return response()->json([
                'message' => 'Job cancelled successfully',
                'job_id' => $jobId,
                'previous_status' => $currentStatus['status'],
                'cancelled_at' => now()->toISOString(),
                'was_removed_from_queue' => $deletedFromQueue,
                'note' => $deletedFromQueue 
                    ? 'Job was removed from queue before processing'
                    : 'Job may have already started processing and cannot be fully stopped'
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to cancel refresh job", [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to cancel job',
                'job_id' => $jobId,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get refresh status by job ID
     */
    public function getRefreshStatusById(string $jobId): JsonResponse
    {
        // Basic validation of job ID format
        if (!$this->isValidJobId($jobId)) {
            return response()->json([
                'error' => 'Invalid job ID format',
                'message' => 'Job ID should start with "refresh_" followed by a unique identifier',
                'job_id' => $jobId
            ], 400);
        }

        $status = Cache::get("refresh_job_status:{$jobId}");
        
        if (!$status) {
            return response()->json([
                'error' => 'Refresh job not found or expired',
                'message' => 'Job may have completed and expired (jobs are cached for 1 hour), or the job ID is invalid',
                'job_id' => $jobId,
                'expires_after' => '1 hour',
                'possible_reasons' => [
                    'Job completed more than 1 hour ago',
                    'Job ID is invalid or malformed',
                    'Cache was cleared'
                ]
            ], 404);
        }
        
        // Calculate how long ago the status was updated
        $updatedAt = isset($status['updated_at']) ? new \DateTime($status['updated_at']) : null;
        $now = new \DateTime();
        $timeSinceUpdate = $updatedAt ? $now->diff($updatedAt) : null;
        
        return response()->json([
            'job_id' => $jobId,
            'status' => $status['status'] ?? 'unknown',
            'message' => $status['message'] ?? 'No message available',
            'updated_at' => $status['updated_at'] ?? null,
            'elapsed_time' => $status['elapsed_time'] ?? null,
            'elapsed_time_human' => $status['elapsed_time_human'] ?? null,
            'parameters' => $status['parameters'] ?? null,
            'time_since_update' => $timeSinceUpdate ? [
                'human_readable' => $this->formatTimeDifference($timeSinceUpdate),
                'seconds' => ($timeSinceUpdate->days * 86400) + ($timeSinceUpdate->h * 3600) + ($timeSinceUpdate->i * 60) + $timeSinceUpdate->s
            ] : null,
            'is_running' => in_array($status['status'] ?? '', ['processing', 'started']),
            'is_completed' => ($status['status'] ?? '') === 'completed',
            'is_failed' => ($status['status'] ?? '') === 'failed',
            'is_cancelled' => ($status['status'] ?? '') === 'cancelled'
        ]);
    }
    
    /**
     * Validate if job ID has the expected format
     */
    private function isValidJobId(string $jobId): bool
    {
        return preg_match('/^refresh_[a-f0-9\.]+$/', $jobId);
    }
    
    /**
     * Format time difference in human readable format
     */
    private function formatTimeDifference(\DateInterval $diff): string
    {
        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
        } elseif ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        } elseif ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        } else {
            return $diff->s . ' second' . ($diff->s > 1 ? 's' : '') . ' ago';
        }
    }

    /**
     * Get user activity (commits and pull requests) with enhanced filtering
     */
    public function getActivity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'days' => 'integer|min:1|max:365',
            'repositories' => 'array',
            'repositories.*' => 'string',
            'author' => 'string|nullable',
            'force_refresh' => 'sometimes|in:true,false,1,0,"true","false","1","0"',
            'type' => 'string|in:commit,pull_request,all'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 400);
        }

        $days = $request->input('days', 14);
        $repositories = $request->input('repositories');
        $author = $request->input('author');
        $forceRefresh = filter_var($request->input('force_refresh', false), FILTER_VALIDATE_BOOLEAN);
        $typeFilter = $request->input('type', 'all');

        // Set max execution time for force refresh to prevent server timeout
        if ($forceRefresh) {
            set_time_limit(60); // Max 60 seconds for force refresh
        }

        // If no repositories specified, load user's enabled repositories
        if (empty($repositories)) {
            $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
            
            if ($user) {
                $repositories = $user->repositories()
                                   ->wherePivot('is_enabled', true)
                                   ->pluck('full_name')
                                   ->toArray();
                                   
                if (empty($repositories)) {
                    return response()->json([
                        'error' => 'No enabled repositories found for user',
                        'message' => 'Please enable some repositories first via PATCH /api/repositories/user'
                    ], 400);
                }
            } else {
                return response()->json([
                    'error' => 'User not found',
                    'message' => 'Please setup your repositories first via PATCH /api/repositories/user'
                ], 404);
            }
        }

        $cacheKey = 'bitbucket_activity_' . md5(json_encode([
            'days' => $days,
            'repositories' => $repositories,
            'author' => $author,
            'type' => $typeFilter
        ]));

        if (!$forceRefresh && Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);
            
            // Apply type filter to cached data
            if ($typeFilter !== 'all') {
                $data = array_filter($data, function($item) use ($typeFilter) {
                    return $item['type'] === $typeFilter;
                });
                $data = array_values($data); // Reindex array
            }
            
            return response()->json([
                'data' => $data,
                'cached' => true,
                'cache_expires_at' => Cache::get($cacheKey . '_expires'),
                'repositories_used' => $repositories,
                'filters' => [
                    'days' => $days,
                    'author' => $author,
                    'type' => $typeFilter
                ],
                'refresh_status' => $this->getRefreshStatus()
            ]);
        }

        try {
            Log::info("Starting getActivity fetchAllData", [
                'days' => $days, 
                'repositories_count' => count($repositories), 
                'author' => $author, 
                'force_refresh' => $forceRefresh,
                'type_filter' => $typeFilter
            ]);
            
            if ($forceRefresh) {
                // Start background job for refresh
                $jobId = uniqid('refresh_', true);
                
                // Set initial status before dispatching job
                $initialStatus = [
                    'status' => 'started',
                    'message' => 'Refresh job queued for processing',
                    'updated_at' => now()->toISOString(),
                    'job_id' => $jobId
                ];
                Cache::put("refresh_job_status:{$jobId}", $initialStatus, now()->addHour());
                Cache::put('latest_refresh_status', $initialStatus, now()->addHour());
                
                $job = new RefreshBitbucketDataJob($days, $repositories, $author, $jobId);
                dispatch($job);
                
                Log::info("Started background refresh job for activity", [
                    'job_id' => $jobId,
                    'days' => $days,
                    'repositories' => $repositories,
                    'author' => $author
                ]);
                
                // Get current data and apply type filter
                $currentData = $this->bitbucketService->fetchAllData($days, $repositories, $author, false);
                if ($typeFilter !== 'all') {
                    $currentData = array_filter($currentData, function($item) use ($typeFilter) {
                        return $item['type'] === $typeFilter;
                    });
                    $currentData = array_values($currentData);
                }
                
                return response()->json([
                    'data' => $currentData,
                    'cached' => false,
                    'refresh_status' => [
                        'status' => 'started',
                        'message' => 'Background refresh started - fresh data will be available shortly',
                        'job_id' => $jobId,
                        'started_at' => now()->toISOString(),
                        'status_url' => url("api/bitbucket/refresh-status/{$jobId}")
                    ],
                    'repositories_used' => $repositories,
                    'filters' => [
                        'days' => $days,
                        'author' => $author,
                        'type' => $typeFilter
                    ]
                ]);
            }
            
            $data = $this->bitbucketService->fetchAllData($days, $repositories, $author, false);
            
            Log::info("getActivity fetchAllData completed", ['data_count' => count($data)]);
            
            // Apply type filter
            if ($typeFilter !== 'all') {
                $data = array_filter($data, function($item) use ($typeFilter) {
                    return $item['type'] === $typeFilter;
                });
                $data = array_values($data); // Reindex array
            }
            
            // Cache for 15 minutes (shorter for activity data)
            $expiresAt = now()->addMinutes(15);
            Cache::put($cacheKey, $data, $expiresAt);
            Cache::put($cacheKey . '_expires', $expiresAt->toISOString(), $expiresAt);

            return response()->json([
                'data' => $data,
                'cached' => false,
                'cache_expires_at' => $expiresAt->toISOString(),
                'repositories_used' => $repositories,
                'filters' => [
                    'days' => $days,
                    'author' => $author,
                    'type' => $typeFilter
                ],
                'refresh_status' => $this->getRefreshStatus()
            ]);

        } catch (\Exception $e) {
            Log::error("getActivity fetchAllData failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'days' => $days,
                'repositories_count' => count($repositories),
                'force_refresh' => $forceRefresh,
                'type_filter' => $typeFilter
            ]);
            
            return response()->json([
                'error' => 'Failed to fetch Bitbucket activity data',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available repositories
     */
    public function getRepositories(): JsonResponse
    {
        $cacheKey = 'bitbucket_repositories';

        if (Cache::has($cacheKey)) {
            return response()->json([
                'data' => Cache::get($cacheKey),
                'cached' => true
            ]);
        }

        try {
            $repositories = $this->bitbucketService->getRepositories();
            
            // Cache for 1 hour
            Cache::put($cacheKey, $repositories, now()->addHour());

            return response()->json([
                'data' => $repositories,
                'cached' => false
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch repositories',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test Bitbucket authentication
     */
    public function testAuthentication(): JsonResponse
    {
        try {
            $result = $this->bitbucketService->testAuthentication();
            
            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Authentication test failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear cache
     */
    public function clearCache(): JsonResponse
    {
        try {
            Cache::flush();
            
            return response()->json([
                'message' => 'Cache cleared successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to clear cache',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Debug single repository
     */
    public function debugRepository(Request $request, $repo): JsonResponse
    {
        $days = $request->input('days', 7);
        
        try {
            $data = $this->bitbucketService->fetchAllData($days, [$repo], null);
            
            return response()->json([
                'repository' => $repo,
                'days' => $days,
                'data' => $data,
                'count' => count($data)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'repository' => $repo
            ], 500);
        }
    }

    /**
     * Trigger repository sync
     */
    public function syncRepositories(): JsonResponse
    {
        try {
            Artisan::call('bitbucket:sync-repositories', ['--force' => true]);
            $output = Artisan::output();
            
            return response()->json([
                'message' => 'Repository sync completed',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Sync failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger commits sync
     */
    public function syncCommits(Request $request): JsonResponse
    {
        $days = $request->input('days', 14);
        
        try {
            Artisan::call('bitbucket:sync-commits', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = Artisan::output();
            
            return response()->json([
                'message' => 'Commits sync completed',
                'days' => $days,
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Commits sync failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger pull requests sync
     */
    public function syncPullRequests(Request $request): JsonResponse
    {
        $days = $request->input('days', 14);
        
        try {
            Artisan::call('bitbucket:sync-pull-requests', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = Artisan::output();
            
            return response()->json([
                'message' => 'Pull requests sync completed',
                'days' => $days,
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Pull requests sync failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger complete sync of all data
     */
    public function syncAll(Request $request): JsonResponse
    {
        $days = $request->input('days', 14);
        
        try {
            Artisan::call('bitbucket:sync-all', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = Artisan::output();
            
            return response()->json([
                'message' => 'Complete sync finished',
                'days' => $days,
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Complete sync failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}