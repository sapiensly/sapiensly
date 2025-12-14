<?php

use App\Http\Controllers\AgentTeamController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('agent-teams', AgentTeamController::class);
});
