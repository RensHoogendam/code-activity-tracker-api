<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BitbucketService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
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
            'force_refresh' => 'boolean'
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
        $forceRefresh = $request->input('force_refresh', false);

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
                'repositories_used' => $repositories
            ]);
        }

        try {
            $data = $this->bitbucketService->fetchAllData($days, $repositories, $author);
            
            // Cache for 30 minutes
            $expiresAt = now()->addMinutes(30);
            Cache::put($cacheKey, $data, $expiresAt);
            Cache::put($cacheKey . '_expires', $expiresAt->toISOString(), $expiresAt);

            return response()->json([
                'data' => $data,
                'cached' => false,
                'cache_expires_at' => $expiresAt->toISOString(),
                'repositories_used' => $repositories
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch Bitbucket data',
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
            \Artisan::call('bitbucket:sync-repositories', ['--force' => true]);
            $output = \Artisan::output();
            
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
            \Artisan::call('bitbucket:sync-commits', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = \Artisan::output();
            
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
            \Artisan::call('bitbucket:sync-pull-requests', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = \Artisan::output();
            
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
            \Artisan::call('bitbucket:sync-all', [
                '--days' => $days,
                '--force' => true
            ]);
            $output = \Artisan::output();
            
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