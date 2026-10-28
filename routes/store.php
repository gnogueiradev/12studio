<?php

use App\Http\Controllers\Store\CatalogController;
use App\Http\Controllers\Store\ConversationController;
use App\Http\Controllers\Store\LandingPageController;
use App\Http\Controllers\Store\PageController;
use App\Http\Controllers\Store\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Montra publica
|--------------------------------------------------------------------------
|
| As mesmas rotas duas vezes, a partir de um so closure: no idioma por
| omissao, na raiz e com os nomes de sempre ('home', 'store.about'), e nos
| outros com o prefixo do idioma ('/en', nomes 'i18n.*'). Assim cada idioma
| tem o seu endereco sem haver nada calculado no arranque — o route:cache
| continua a funcionar —, e os controladores nao sabem que o idioma existe
| (o SetStoreLocale tira-lhes o parametro).
|
| Carregado no fim do routes/web.php, e as landing pages do construtor (um
| '/{slug}' solto) no fim deste: sao as ultimas a ser tentadas.
|
*/

$middleware = ['store.locale', 'store.shared', 'store.open'];

$storefront = function (): void {
    Route::get('/', PageController::class)->defaults('page', 'home')->name('home');

    // Catalogo, categorias e produtos (templates do construtor).
    Route::get('produtos', [CatalogController::class, 'index'])->name('store.catalog');
    Route::get('categoria/{slug}', [CatalogController::class, 'category'])->name('store.category');
    Route::get('produtos/{slug}', ProductController::class)->name('store.product');

    // Conversas com a loja (sem conta): abrir pelos formularios da montra, e
    // depois o link privado. Os limites estao no AppServiceProvider.
    Route::post('conversas', [ConversationController::class, 'store'])
        ->middleware('throttle:conversations')
        ->name('store.conversations.store');

    // Paginas de sistema montadas por blocos (App\PageBuilder).
    foreach ([
        'about' => 'sobre',
        'custom' => 'personalizado',
        'contact' => 'contacto',
        'faq' => 'faq',
        'terms' => 'termos',
        'privacy' => 'privacidade',
        'shipping' => 'envios-e-devolucoes',
    ] as $key => $path) {
        Route::get($path, PageController::class)->defaults('page', $key)->name("store.$key");
    }
};

// O link privado das conversas: fora do `store.open`. Uma conversa ja aberta
// continua a funcionar com a montra fechada (antes do lancamento, ferias) — o
// link nunca expira enquanto a conversa existir.
$conversations = function (): void {
    Route::get('conversa', [ConversationController::class, 'lost'])->name('store.conversations.lost');
    Route::post('conversa/reenviar', [ConversationController::class, 'resend'])
        ->middleware('throttle:conversation-resend')
        ->name('store.conversations.resend');
    Route::get('conversa/{token}', [ConversationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{1,64}')
        ->name('store.conversation');
    Route::post('conversa/{token}/responder', [ConversationController::class, 'reply'])
        ->where('token', '[A-Za-z0-9]{1,64}')
        ->middleware('throttle:conversation-reply')
        ->name('store.conversation.reply');
    Route::get('conversa/{token}/anexos/{attachment}', [ConversationController::class, 'attachment'])
        ->where('token', '[A-Za-z0-9]{1,64}')
        ->name('store.conversation.attachment');
};

// Um endereco de landing: minusculas, numeros e hifens (PageBuilderService).
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

$locales = implode('|', array_diff(
    array_keys((array) config('locales.available')),
    [config('locales.default')],
));

Route::middleware(['store.locale', 'store.shared'])->group($conversations);
Route::prefix('{locale}')
    ->where(['locale' => $locales])
    ->name('i18n.')
    ->middleware(['store.locale', 'store.shared'])
    ->group($conversations);

Route::middleware($middleware)->group($storefront);

Route::prefix('{locale}')
    ->where(['locale' => $locales])
    ->name('i18n.')
    ->middleware($middleware)
    ->group(function () use ($storefront, $slug): void {
        $storefront();

        Route::get('{slug}', LandingPageController::class)->where('slug', $slug)->name('store.landing');
    });

Route::get('{slug}', LandingPageController::class)
    ->where('slug', $slug)
    ->middleware($middleware)
    ->name('store.landing');
