<?php

use App\Http\Controllers\FlowController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
])->group(function () {
    Route::get('agents/{agent}/flows', [FlowController::class, 'index'])->name('agents.flows.index');
    Route::get('agents/{agent}/flows/create', [FlowController::class, 'create'])->name('agents.flows.create');
    Route::post('agents/{agent}/flows', [FlowController::class, 'store'])->name('agents.flows.store');
    Route::get('agents/{agent}/flows/{flow}/edit', [FlowController::class, 'edit'])->name('agents.flows.edit');
    Route::put('agents/{agent}/flows/{flow}', [FlowController::class, 'update'])->name('agents.flows.update');
    Route::delete('agents/{agent}/flows/{flow}', [FlowController::class, 'destroy'])->name('agents.flows.destroy');
    Route::post('agents/{agent}/flows/{flow}/activate', [FlowController::class, 'activate'])->name('agents.flows.activate');
});
