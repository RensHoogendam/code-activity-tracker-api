<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserRepositoryController extends Controller
{
    /**
     * Get all available repositories
     */
    public function index(): JsonResponse
    {
        $repositories = Repository::active()
                                ->orderBy('workspace')
                                ->orderBy('name')
                                ->get(['id', 'name', 'full_name', 'workspace', 'description', 'language']);

        return response()->json([
            'data' => $repositories
        ]);
    }

    /**
     * Get user's repositories (for now using a default user)
     */
    public function getUserRepositories(Request $request): JsonResponse
    {
        // For now, we'll use user ID 1 or create a default user
        $user = User::firstOrCreate(
            ['email' => env('BITBUCKET_AUTHOR_EMAIL')],
            [
                'name' => env('BITBUCKET_AUTHOR_DISPLAY_NAME'),
                'password' => bcrypt('password') // You should change this
            ]
        );

        $repositories = $user->repositories()
                           ->active()
                           ->orderBy('name')
                           ->get()
                           ->map(function($repository) {
                               return [
                                   'id' => $repository->id,
                                   'name' => $repository->name,
                                   'full_name' => $repository->full_name,
                                   'workspace' => $repository->workspace,
                                   'description' => $repository->description,
                                   'language' => $repository->language,
                                   'is_primary' => $repository->pivot->is_primary,
                                   'is_enabled' => $repository->pivot->is_enabled,
                               ];
                           });

        return response()->json([
            'data' => $repositories,
            'user_id' => $user->id
        ]);
    }

    /**
     * Add repositories to user
     */
    public function addRepositories(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'repository_ids' => 'required|array',
            'repository_ids.*' => 'integer|exists:repositories,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 400);
        }

        // Get or create user
        $user = User::firstOrCreate(
            ['email' => env('BITBUCKET_AUTHOR_EMAIL')],
            [
                'name' => env('BITBUCKET_AUTHOR_DISPLAY_NAME'),
                'password' => bcrypt('password')
            ]
        );

        $repositoryIds = $request->input('repository_ids');
        
        // Prepare sync data with default values for pivot fields
        $syncData = [];
        foreach ($repositoryIds as $repoId) {
            $syncData[$repoId] = [
                'is_primary' => false,
                'is_enabled' => true // Default to enabled when adding new repositories
            ];
        }
        
        // Attach repositories (sync will remove existing and add new ones)
        $user->repositories()->sync($syncData);

        $repositories = $user->repositories()->get()->map(function($repository) {
            return [
                'id' => $repository->id,
                'name' => $repository->name,
                'full_name' => $repository->full_name,
                'workspace' => $repository->workspace,
                'description' => $repository->description,
                'language' => $repository->language,
                'is_primary' => $repository->pivot->is_primary,
                'is_enabled' => $repository->pivot->is_enabled,
            ];
        });

        return response()->json([
            'message' => 'Repositories updated successfully',
            'data' => $repositories,
            'count' => $repositories->count()
        ]);
    }

    /**
     * Update user's enabled repositories in bulk
     */
    public function updateUserRepositories(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled_repositories' => 'required|array',
            'enabled_repositories.*' => 'string|exists:repositories,full_name'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Validation failed',
                'messages' => $validator->errors()
            ], 400);
        }

        // Get or create user
        $user = User::firstOrCreate(
            ['email' => env('BITBUCKET_AUTHOR_EMAIL')],
            [
                'name' => env('BITBUCKET_AUTHOR_DISPLAY_NAME'),
                'password' => bcrypt('password')
            ]
        );

        $enabledRepositoryNames = $request->input('enabled_repositories');
        
        // Get all repositories that should be enabled
        $enabledRepositories = Repository::whereIn('full_name', $enabledRepositoryNames)->get();
        
        // Get all existing user repository relationships
        $existingUserRepos = $user->repositories()->get();
        
        // Find repositories that need to be added to the user
        $existingRepoIds = $existingUserRepos->pluck('id')->toArray();
        $enabledRepoIds = $enabledRepositories->pluck('id')->toArray();
        $newRepoIds = array_diff($enabledRepoIds, $existingRepoIds);
        
        // Add new repositories to user with enabled = true
        foreach ($newRepoIds as $repoId) {
            $user->repositories()->attach($repoId, [
                'is_primary' => false,
                'is_enabled' => true
            ]);
        }
        
        // Update all user repositories (existing + newly added)
        $allUserRepositories = $user->repositories()->get(); // Refresh the list
        
        foreach ($allUserRepositories as $repository) {
            $shouldBeEnabled = in_array($repository->id, $enabledRepoIds);
            $user->repositories()->updateExistingPivot($repository->id, [
                'is_enabled' => $shouldBeEnabled
            ]);
        }

        // Get final updated repositories
        $repositories = $user->repositories()->get()->map(function($repository) {
            return [
                'id' => $repository->id,
                'name' => $repository->name,
                'full_name' => $repository->full_name,
                'workspace' => $repository->workspace,
                'description' => $repository->description,
                'language' => $repository->language,
                'is_primary' => $repository->pivot->is_primary,
                'is_enabled' => $repository->pivot->is_enabled,
            ];
        });

        $enabledCount = $repositories->where('is_enabled', true)->count();
        $newlyAddedCount = count($newRepoIds);

        return response()->json([
            'message' => 'Repository status updated successfully',
            'data' => $repositories,
            'total_repositories' => $repositories->count(),
            'enabled_repositories' => $enabledCount,
            'disabled_repositories' => $repositories->count() - $enabledCount,
            'newly_added_repositories' => $newlyAddedCount
        ]);
    }

    /**
     * Remove repository from user
     */
    public function removeRepository(Request $request, int $repositoryId): JsonResponse
    {
        $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $repository = Repository::find($repositoryId);
        if (!$repository) {
            return response()->json(['error' => 'Repository not found'], 404);
        }

        $user->repositories()->detach($repositoryId);

        return response()->json([
            'message' => 'Repository removed successfully'
        ]);
    }

    /**
     * Enable a repository for the user
     */
    public function enableRepository(Request $request, int $repositoryId): JsonResponse
    {
        $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $repository = Repository::find($repositoryId);
        if (!$repository) {
            return response()->json(['error' => 'Repository not found'], 404);
        }

        // Check if the repository is already linked to the user
        if (!$user->repositories()->where('repository_id', $repositoryId)->exists()) {
            return response()->json(['error' => 'Repository is not linked to this user'], 400);
        }

        // Update the pivot to enable the repository
        $user->repositories()->updateExistingPivot($repositoryId, ['is_enabled' => true]);

        return response()->json([
            'message' => 'Repository enabled successfully'
        ]);
    }

    /**
     * Disable a repository for the user
     */
    public function disableRepository(Request $request, int $repositoryId): JsonResponse
    {
        $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $repository = Repository::find($repositoryId);
        if (!$repository) {
            return response()->json(['error' => 'Repository not found'], 404);
        }

        // Check if the repository is already linked to the user
        if (!$user->repositories()->where('repository_id', $repositoryId)->exists()) {
            return response()->json(['error' => 'Repository is not linked to this user'], 400);
        }

        // Update the pivot to disable the repository
        $user->repositories()->updateExistingPivot($repositoryId, ['is_enabled' => false]);

        return response()->json([
            'message' => 'Repository disabled successfully'
        ]);
    }

    /**
     * Toggle repository enabled/disabled status for the user
     */
    public function toggleRepository(Request $request, int $repositoryId): JsonResponse
    {
        $user = User::where('email', env('BITBUCKET_AUTHOR_EMAIL'))->first();
        
        if (!$user) {
            return response()->json(['error' => 'User not found'], 404);
        }

        $repository = Repository::find($repositoryId);
        if (!$repository) {
            return response()->json(['error' => 'Repository not found'], 404);
        }

        // Get the current relationship
        $userRepository = $user->repositories()->where('repository_id', $repositoryId)->first();
        
        if (!$userRepository) {
            return response()->json(['error' => 'Repository is not linked to this user'], 400);
        }

        $currentStatus = $userRepository->pivot->is_enabled;
        $newStatus = !$currentStatus;

        // Update the pivot to toggle the repository
        $user->repositories()->updateExistingPivot($repositoryId, ['is_enabled' => $newStatus]);

        return response()->json([
            'message' => 'Repository ' . ($newStatus ? 'enabled' : 'disabled') . ' successfully',
            'is_enabled' => $newStatus
        ]);
    }
}
