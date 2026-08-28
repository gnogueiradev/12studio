<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});

/**
 * Ponto de descoberta que os gestores de passwords consultam para oferecer
 * "passar a passkey". Estava fora de TODO o middleware — anunciava a quem
 * passasse pelo site que existe um /settings/security, e portanto que ha contas
 * para atacar, enquanto a loja ainda esta fechada.
 *
 * Atras do cadeado nao perde utilidade: o EnsureLoginGate deixa passar quem ja
 * esta autenticado, que e exatamente quem tem passkeys para gerir. Quem nao
 * tem sessao nem cookie leva o mesmo 404 de qualquer rota inexistente.
 */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->middleware('login-gate')->name('well-known.passkeys');
