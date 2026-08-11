<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginGateController;
use App\Http\Controllers\NotifyController;
use Illuminate\Support\Facades\Route;

// ── Montra publica ──────────────────────────────────────────────────────────
Route::get('/', HomeController::class)->name('home');

// Emails deixados na landing "em breve". Throttle porque e o unico POST que um
// visitante sem sessao alcanca — 6/min chega para quem corrige um erro de
// escrita e nao chega para encher a tabela.
Route::post('avisar', NotifyController::class)
    ->middleware('throttle:6,1')
    ->name('notify');

// ── Cadeado do login: visitar /acesso/<segredo> grava o cookie que torna as
//    rotas do Fortify visiveis neste browser. Sem middleware — tem de ser
//    alcancavel por quem ainda nao tem cookie. ─────────────────────────────────
Route::get('acesso/{secret}', LoginGateController::class)->name('login-gate');

// ── Area autenticada (o /dashboard do starter fica para a conta do admin;
//    /conta de clientes chega na Fase 5) ────────────────────────────────────
Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // ── Backoffice: alias 'admin' (EnsureAdmin) por cima de auth+verified.
    //    Form Requests re-verificam isAdmin() — middleware sozinho nao e
    //    seguranca. URIs em PT, controllers em EN (padrao do plano). ─────────
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::inertia('/', 'admin/dashboard')->name('dashboard');

        // Encomendas: sem `edit`/`destroy` — uma encomenda nunca se apaga,
        // cancela-se. `show` existe (ao contrario de produtos) porque o
        // detalhe e mesmo uma vista, nao um formulario.
        Route::resource('encomendas', Admin\OrderController::class)
            ->parameters(['encomendas' => 'order'])
            ->only(['index', 'create', 'store', 'show']);

        // URIs proprios por acao: o Wayfinder duplica chaves quando dois
        // verbos partilham o mesmo URI fora de um Route::resource.
        Route::patch('encomendas/{order}/estado', [Admin\OrderController::class, 'updateStatus'])
            ->name('encomendas.estado');
        Route::patch('encomendas/{order}/pagamento', [Admin\OrderController::class, 'updatePayment'])
            ->name('encomendas.pagamento');
        Route::patch('encomendas/{order}/detalhes', [Admin\OrderController::class, 'updateDetails'])
            ->name('encomendas.detalhes');

        // Quadro de producao (por item) e o avanco de cada cartao.
        Route::get('producao', Admin\ProductionBoardController::class)->name('producao');
        Route::patch('itens/{item}/producao', [Admin\OrderItemController::class, 'updateProduction'])
            ->name('itens.producao');

        // Cliente = User com is_admin = false; o `edit` acumula formulario
        // e historico de encomendas (o backoffice nao tem rotas `show`).
        Route::resource('clientes', Admin\CustomerController::class)
            ->parameters(['clientes' => 'customer'])
            ->except(['show']);

        Route::resource('categorias', Admin\CategoryController::class)
            ->parameters(['categorias' => 'category'])
            ->except(['show']);

        // Materiais e cores em recursos irmaos: uma cor pertence a um
        // material, mas a paleta gere-se toda de uma vez — aninhar as cores
        // dentro do material daria URLs mais longos sem ganho nenhum.
        Route::resource('materiais', Admin\MaterialController::class)
            ->parameters(['materiais' => 'material'])
            ->except(['show']);

        Route::resource('cores', Admin\ColorController::class)
            ->parameters(['cores' => 'color'])
            ->except(['show']);

        // Definicoes editaveis em runtime (tabela `settings`). URIs proprios
        // por acao — o Wayfinder duplica chaves quando dois verbos partilham
        // o mesmo URI fora de um Route::resource.
        Route::get('definicoes', [Admin\SettingController::class, 'index'])
            ->name('definicoes.index');
        Route::patch('definicoes/guardar', [Admin\SettingController::class, 'update'])
            ->name('definicoes.update');

        Route::resource('produtos', Admin\ProductController::class)
            ->parameters(['produtos' => 'product'])
            ->except(['show']);

        // Fotografias: shallow como as variantes, mas com URIs proprios por
        // acao — o Wayfinder duplica chaves quando dois verbos partilham o
        // mesmo URI fora de um Route::resource.
        Route::post('produtos/{product}/imagens', [Admin\ProductImageController::class, 'store'])
            ->name('produtos.imagens.store');
        Route::patch('produtos/{product}/imagens/ordem', [Admin\ProductImageController::class, 'reorder'])
            ->name('produtos.imagens.ordem');
        Route::patch('imagens/{image}/principal', [Admin\ProductImageController::class, 'setPrimary'])
            ->name('imagens.principal');
        Route::patch('imagens/{image}', [Admin\ProductImageController::class, 'update'])
            ->name('imagens.update');
        Route::delete('imagens/{image}', [Admin\ProductImageController::class, 'destroy'])
            ->name('imagens.destroy');

        // Shallow: criar dentro do produto, editar/apagar por /admin/variantes/{variant}.
        // A listagem e a seccao "Variantes" da pagina de edicao do produto.
        Route::resource('produtos.variantes', Admin\VariantController::class)
            ->parameters(['produtos' => 'product', 'variantes' => 'variant'])
            ->except(['show', 'index'])
            ->shallow();
    });
});

require __DIR__.'/settings.php';
