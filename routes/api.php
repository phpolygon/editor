<?php

use App\Http\Controllers\EditorApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('editor')->group(function () {
    Route::post('/command', [EditorApiController::class, 'dispatch']);
    Route::get('/project', [EditorApiController::class, 'project']);
    Route::get('/project/recent', [EditorApiController::class, 'recentProjects']);
    Route::post('/project/open', [EditorApiController::class, 'openProject']);
    Route::get('/assets', [EditorApiController::class, 'assets']);
    Route::get('/assets/file', [EditorApiController::class, 'assetFile']);
    Route::post('/project/open-dialog', [EditorApiController::class, 'openProjectDialog']);
    Route::post('/project/import', [EditorApiController::class, 'importProject']);
    Route::post('/project/import-dialog', [EditorApiController::class, 'importProjectDialog']);
    Route::post('/assets/browse', [EditorApiController::class, 'browseAsset']);
});
