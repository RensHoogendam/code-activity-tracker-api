<?php

use App\Http\Controllers\Api\BitbucketController;
use App\Http\Controllers\Api\UserRepositoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('bitbucket')->group(function () {
    Route::get('/commits', [BitbucketController::class, 'getCommitsAndPullRequests']);
    Route::get('/repositories', [BitbucketController::class, 'getRepositories']);
    Route::get('/test-auth', [BitbucketController::class, 'testAuthentication']);
    Route::delete('/cache', [BitbucketController::class, 'clearCache']);
    Route::get('/debug/{repo}', [BitbucketController::class, 'debugRepository']);
});

Route::prefix('repositories')->group(function () {
    Route::get('/', [UserRepositoryController::class, 'index']);
    Route::get('/user', [UserRepositoryController::class, 'getUserRepositories']);
    Route::post('/user', [UserRepositoryController::class, 'addRepositories']);
    Route::delete('/user/{repository}', [UserRepositoryController::class, 'removeRepository']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');