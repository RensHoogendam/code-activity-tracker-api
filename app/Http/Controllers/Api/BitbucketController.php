<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        $cacheKey = 'bitbucket_data_' . md5(json_encode([
            'days' => $days,
            'repositories' => $repositories,
            'author' => $author
        ]));

        if (!$forceRefresh && Cache::has($cacheKey)) {
            return response()->json([
                'data' => Cache::get($cacheKey),
                'cached' => true,
                'cache_expires_at' => Cache::get($cacheKey . '_expires')
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
                'cache_expires_at' => $expiresAt->toISOString()
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
}