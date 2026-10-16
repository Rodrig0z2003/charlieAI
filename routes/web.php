<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Central\KnowledgeBaseController;

Route::get('/', function () {
    return redirect('/knowledge');
});

Route::prefix('knowledge')->group(function () {
    Route::get('/', [KnowledgeBaseController::class, 'index'])->name('knowledge.index');
    Route::post('/', [KnowledgeBaseController::class, 'store'])->name('knowledge.store');
    Route::get('/{documentName}/download', [KnowledgeBaseController::class, 'download'])->name('knowledge.download');
    Route::delete('/{documentName}', [KnowledgeBaseController::class, 'destroy'])->name('knowledge.destroy');
});