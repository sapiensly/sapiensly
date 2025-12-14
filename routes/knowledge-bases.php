<?php

use App\Http\Controllers\KnowledgeBaseController;
use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function () {
    Route::resource('knowledge-bases', KnowledgeBaseController::class);
});
