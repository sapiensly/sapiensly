<?php

use App\Http\Controllers\ToolController;
use App\Http\Controllers\Tools\ToolMcpController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::post('tools/{tool}/mcp/refresh', [ToolMcpController::class, 'refresh'])
        ->name('tools.mcp.refresh');
    Route::resource('tools', ToolController::class);
});
