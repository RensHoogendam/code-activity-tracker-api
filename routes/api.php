<?php

use App\Http\Controllers\Api\BitbucketController;
use App\Http\Controllers\Api\UserRepositoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('bitbucket')->group(function () {
    Route::get('/commits', [BitbucketController::class, 'getCommitsAndPullRequests']);
    Route::get('/activity', [BitbucketController::class, 'getActivity']);
    Route::get('/repositories', [BitbucketController::class, 'getRepositories']);
    Route::get('/test-auth', [BitbucketController::class, 'testAuthentication']);
    Route::delete('/cache', [BitbucketController::class, 'clearCache']);
    Route::get('/debug/{repo}', [BitbucketController::class, 'debugRepository']);
    
    // Refresh status routes
    Route::get('/refresh-status', [BitbucketController::class, 'getLatestRefreshStatus']);
    Route::get('/refresh-status/{jobId}', [BitbucketController::class, 'getRefreshStatusById']);
    Route::post('/refresh', [BitbucketController::class, 'startRefreshJob']);
    Route::delete('/refresh-cancel/{jobId}', [BitbucketController::class, 'cancelRefreshJob']);
    
    // Data sync routes
    Route::post('/sync/repositories', [BitbucketController::class, 'syncRepositories']);
    Route::post('/sync/commits', [BitbucketController::class, 'syncCommits']);
    Route::post('/sync/pull-requests', [BitbucketController::class, 'syncPullRequests']);
    Route::post('/sync/all', [BitbucketController::class, 'syncAll']);
});

Route::prefix('repositories')->group(function () {
    Route::get('/', [UserRepositoryController::class, 'index']);
    Route::get('/user', [UserRepositoryController::class, 'getUserRepositories']);
    Route::post('/user', [UserRepositoryController::class, 'addRepositories']);
    Route::patch('/user', [UserRepositoryController::class, 'updateUserRepositories']); // Bulk update enabled repositories
    Route::delete('/user/{repository}', [UserRepositoryController::class, 'removeRepository']);
    
    // Individual repository enable/disable routes (kept for backwards compatibility)
    Route::patch('/user/{repository}/enable', [UserRepositoryController::class, 'enableRepository']);
    Route::patch('/user/{repository}/disable', [UserRepositoryController::class, 'disableRepository']);
    Route::patch('/user/{repository}/toggle', [UserRepositoryController::class, 'toggleRepository']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');