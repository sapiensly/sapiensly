<?php

use App\Http\Controllers\AgentController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::resource('agents', AgentController::class);
    Route::post('agents/{agent}/duplicate', [AgentController::class, 'duplicate'])->name('agents.duplicate');
});
