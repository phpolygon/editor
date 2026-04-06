<?php

use App\Http\Controllers\EditorApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('editor')->group(function () {
    Route::post('/command', [EditorApiController::class, 'dispatch']);
    Route::get('/project', [EditorApiController::class, 'project']);
    Route::post('/project/open', [EditorApiController::class, 'openProject']);
    Route::get('/assets', [EditorApiController::class, 'assets']);
    Route::post('/project/open-dialog', [EditorApiController::class, 'openProjectDialog']);
    Route::post('/assets/browse', [EditorApiController::class, 'browseAsset']);
});
