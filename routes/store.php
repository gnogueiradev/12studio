<?php

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Montra publica
|--------------------------------------------------------------------------
|
| As mesmas rotas duas vezes, a partir de um so closure: no idioma por
| omissao, na raiz e com os nomes de sempre ('home'), e nos outros com o
| prefixo do idioma ('/en', nomes 'i18n.*'). Assim cada idioma tem o seu
| endereco sem haver nada calculado no arranque — o route:cache continua a
| funcionar —, e os controladores nao sabem que o idioma existe (o
| SetStoreLocale tira-lhes o parametro).
|
| Carregado no fim do routes/web.php: as landing pages do construtor (um
| '/{slug}' solto) tem de ser as ultimas a ser tentadas.
|
*/

$storefront = function (): void {
    Route::get('/', HomeController::class)->name('home');
};

Route::middleware(['store.locale', 'store.shared', 'store.open'])->group($storefront);

Route::prefix('{locale}')
    ->where(['locale' => implode('|', array_diff(
        array_keys((array) config('locales.available')),
        [config('locales.default')],
    ))])
    ->name('i18n.')
    ->middleware(['store.locale', 'store.shared', 'store.open'])
    ->group($storefront);
