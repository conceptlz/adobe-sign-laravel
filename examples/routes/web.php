<?php

// Example routes for examples/app/Http/Controllers/AgreementController.php.
// The package itself registers adobe-sign.webhook and (for OAuth) adobe-sign.oauth.* routes.

use App\Http\Controllers\AgreementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/agreements', [AgreementController::class, 'store'])->name('agreements.store');
    Route::get('/agreements/{agreementId}', [AgreementController::class, 'show'])->name('agreements.show');
    Route::get('/agreements/{agreementId}/sign', [AgreementController::class, 'signingUrl'])->name('agreements.sign');
    Route::get('/agreements/{agreementId}/pdf', [AgreementController::class, 'download'])->name('agreements.pdf');
});
