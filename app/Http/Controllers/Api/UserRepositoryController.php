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
                           ->with(['repositories' => function($query) {
                               $query->active()->orderBy('name');
                           }])
                           ->get()
                           ->pluck('repositories')
                           ->flatten();

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
        
        // Attach repositories (sync will remove existing and add new ones)
        $user->repositories()->sync($repositoryIds);

        $repositories = $user->repositories()->get();

        return response()->json([
            'message' => 'Repositories updated successfully',
            'data' => $repositories,
            'count' => $repositories->count()
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
}
