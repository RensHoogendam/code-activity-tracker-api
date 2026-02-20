<?php

namespace App\Jobs;

use App\Services\BitbucketService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshBitbucketDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600; // 10 minutes timeout

    protected int $maxDays;
    protected ?array $selectedRepos;
    protected ?string $authorFilter;
    protected string $jobId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $maxDays = 14, ?array $selectedRepos = null, ?string $authorFilter = null, ?string $jobId = null)
    {
        $this->maxDays = $maxDays;
        $this->selectedRepos = $selectedRepos;
        $this->authorFilter = $authorFilter;
        $this->jobId = $jobId ?? uniqid('refresh_', true);
    }

    /**
     * Execute the job.
     */
    public function handle(BitbucketService $bitbucketService): void
    {
        $startTime = microtime(true);
        
        try {
            // Check if job has been cancelled before starting
            if ($this->isCancelled()) {
                Log::info("Job was cancelled before starting", ['job_id' => $this->jobId]);
                return;
            }

            Log::info("Starting background refresh job", [
                'job_id' => $this->jobId,
                'max_days' => $this->maxDays,
                'repositories_count' => $this->selectedRepos ? count($this->selectedRepos) : 'all',
                'author_filter' => $this->authorFilter
            ]);
            
            // Set a custom log context for this job
            \Log::withContext(['job_id' => $this->jobId]);

            // Set initial status immediately
            $this->updateJobStatus('processing', 'Initializing refresh job...', $startTime);

            // Limit repositories to prevent timeout (maximum 5 repos per job)
            $limitedRepos = $this->selectedRepos;
            if ($limitedRepos && count($limitedRepos) > 5) {
                $limitedRepos = array_slice($limitedRepos, 0, 5);
                Log::info("Limited repositories to prevent timeout", [
                    'original_count' => count($this->selectedRepos),
                    'limited_count' => count($limitedRepos)
                ]);
                
                $this->updateJobStatus('processing', 'Limited to ' . count($limitedRepos) . ' repositories to prevent timeout', $startTime);
            }

            // Create progress callback to update job status
            $progressCallback = function($message) use ($startTime) {
                // Check if job was cancelled during processing
                if ($this->isCancelled()) {
                    Log::info("Job cancellation detected during processing", ['job_id' => $this->jobId]);
                    throw new \Exception("Job was cancelled during processing");
                }
                
                $this->updateJobStatus('processing', $message, $startTime);
            };

            // Do the actual refresh with limited repos and progress callback
            // Allow almost the full job timeout (leaving some buffer for cleanup)
            $bitbucketService->refreshDataFromApi($this->maxDays, $limitedRepos, $this->authorFilter, $progressCallback, 550);

            // Clear relevant caches so next request gets fresh data
            $this->clearRelevantCaches();

            $executionTime = round(microtime(true) - $startTime, 2);
            
            // Update job status to completed
            $this->updateJobStatus('completed', "Refresh completed successfully in {$executionTime} seconds", $startTime);

            Log::info("Background refresh job completed", [
                'job_id' => $this->jobId,
                'execution_time' => $executionTime
            ]);

        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            
            Log::error("Background refresh job failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ]);

            // Update job status to failed
            $this->updateJobStatus('failed', 'Refresh failed: ' . $e->getMessage(), $startTime);

            throw $e; // Re-throw to mark job as failed
        } finally {
            // Clear the log context for this job
            \Log::withoutContext();
        }
    }

    /**
     * Update the job status in cache with enhanced progress info
     */
    protected function updateJobStatus(string $status, string $message, ?float $startTime = null): void
    {
        $statusData = [
            'status' => $status,
            'message' => $message,
            'updated_at' => now()->toISOString(),
            'job_id' => $this->jobId,
            'parameters' => [
                'max_days' => $this->maxDays,
                'selected_repos_count' => $this->selectedRepos ? count($this->selectedRepos) : null,
                'author_filter' => $this->authorFilter
            ]
        ];

        // Add elapsed time if startTime is provided
        if ($startTime !== null) {
            $elapsedTime = round(microtime(true) - $startTime, 2);
            $statusData['elapsed_time'] = $elapsedTime;
            $statusData['elapsed_time_human'] = $this->formatElapsedTime($elapsedTime);
        }

        // Store status for 1 hour
        $cacheKey = "refresh_job_status:{$this->jobId}";
        Cache::put($cacheKey, $statusData, now()->addHour());
        
        // Also store a general "latest refresh" status
        Cache::put('latest_refresh_status', $statusData, now()->addHour());
        
        // Log cache operations for debugging
        Log::info("Job status updated in cache", [
            'job_id' => $this->jobId,
            'status' => $status,
            'message' => $message,
            'elapsed_time' => $statusData['elapsed_time'] ?? null,
            'cache_key' => $cacheKey,
            'cache_stored' => Cache::has($cacheKey)
        ]);
    }

    /**
     * Format elapsed time in human readable format
     */
    private function formatElapsedTime(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . ' seconds';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return $minutes . 'm ' . round($remainingSeconds, 0) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $remainingMinutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $remainingMinutes . 'm';
        }
    }

    /**
     * Clear caches that would contain stale data
     */
    protected function clearRelevantCaches(): void
    {
        try {
            // Instead of trying to get all keys (which is Redis-specific), 
            // let's clear known cache keys that our application uses
            $keysToCheck = [
                'bitbucket_data_' . $this->maxDays . '_' . ($this->authorFilter ?? 'all'),
                'bitbucket_activity_' . $this->maxDays . '_' . ($this->authorFilter ?? 'all'),
                'repositories_with_activity',
                'latest_refresh_status'
            ];

            foreach ($keysToCheck as $key) {
                if (Cache::has($key)) {
                    Cache::forget($key);
                    Log::debug("Cleared cache key: {$key}");
                }
            }

            Log::info("Cleared relevant caches after refresh job", [
                'keys_checked' => count($keysToCheck),
                'max_days' => $this->maxDays,
                'author_filter' => $this->authorFilter
            ]);

        } catch (\Exception $e) {
            Log::warning("Failed to clear some caches after refresh job", [
                'error' => $e->getMessage(),
                'job_id' => $this->jobId
            ]);
        }
    }

    /**
     * Get the job ID
     */
    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Check if the job has been cancelled
     */
    protected function isCancelled(): bool
    {
        $cacheKey = "refresh_job_status:{$this->jobId}";
        $status = Cache::get($cacheKey);
        
        return $status && $status['status'] === 'cancelled';
    }
}