<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('agents', AgentController::class);
    Route::post('agents/{agent}/duplicate', [AgentController::class, 'duplicate'])->name('agents.duplicate');
});
