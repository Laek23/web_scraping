<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExecutionController;
use App\Http\Controllers\Api\DealBookmarkController;
use App\Http\Controllers\Api\DealCommentController;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DealVoteController;
use App\Http\Controllers\Api\ProductPreviewController;
use App\Http\Controllers\Api\ProxyScraperController;
use App\Http\Controllers\Api\RobotController;
use App\Http\Controllers\Api\UserProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/extract-preview', ProductPreviewController::class)->middleware('throttle:30,1');
    Route::post('/proxy/extract', [ProxyScraperController::class, 'extract'])->middleware('throttle:30,1');
    Route::post('/proxy/amazon', [ProxyScraperController::class, 'extract'])->middleware('throttle:30,1');
    Route::get('/deals', [DealController::class, 'index']);
    Route::get('/deals/{deal}', [DealController::class, 'show'])->whereNumber('deal');
    Route::post('/deals/{deal}/check-price', [DealController::class, 'checkPrice'])->whereNumber('deal');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user/profile', [UserProfileController::class, 'show']);
        Route::put('/user/profile', [UserProfileController::class, 'update']);
        Route::put('/user/password', [UserProfileController::class, 'updatePassword']);
        Route::get('/user/deals', [DealController::class, 'userDeals']);
        Route::patch('/user/deals/{deal}/status', [DealController::class, 'updateUserDealStatus'])->whereNumber('deal');
        Route::get('/user/bookmarks', [DealBookmarkController::class, 'index']);
        Route::post('/deals', [DealController::class, 'store']);
        Route::patch('/deals/{deal}', [DealController::class, 'update'])->whereNumber('deal');
        Route::delete('/deals/{deal}', [DealController::class, 'destroy'])->whereNumber('deal');
        Route::post('/deals/{deal}/bookmark', [DealBookmarkController::class, 'toggle'])->whereNumber('deal');
        Route::post('/deals/{deal}/vote', [DealVoteController::class, 'toggle'])->whereNumber('deal');
        Route::post('/deals/{deal}/comments', [DealCommentController::class, 'store'])->whereNumber('deal');
        Route::delete('/comments/{comment}', [DealCommentController::class, 'destroy'])->whereNumber('comment');
    });
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/demo', [AuthController::class, 'demo'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('robots', RobotController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('/robots/{robot}/run', [RobotController::class, 'run']);
    Route::get('/executions', [ExecutionController::class, 'index']);
    Route::get('/executions/{execution}', [ExecutionController::class, 'show']);
    Route::get('/tokens', [ApiTokenController::class, 'index']);
    Route::post('/tokens', [ApiTokenController::class, 'store']);
    Route::delete('/tokens/{tokenId}', [ApiTokenController::class, 'destroy']);
});
