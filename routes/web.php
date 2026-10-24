<?php

use App\Alerts\AlertChannel;
use App\Http\Controllers\Admin;
use App\Http\Controllers\DashboardController;
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
    // A equipa de producao aterra no quadro; os outros no dashboard do starter.
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // ── Quadro de producao: o UNICO pedaco do /admin aberto a equipa de
    //    producao. Mesmo prefixo e nomes do grupo abaixo, outro porteiro. O
    //    ProductionStaffAccessTest garante que nada mais escapa para aqui. ──
    Route::middleware('production')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('producao', Admin\ProductionBoardController::class)->name('producao');
        Route::patch('itens/{item}/producao', [Admin\OrderItemController::class, 'updateProduction'])
            ->name('itens.producao');
    });

    // ── Equipa: so o dono, e cada acao pede a password outra vez. ──────────
    Route::middleware(['owner', 'password.confirm'])->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('utilizadores', [Admin\StaffController::class, 'index'])->name('utilizadores.index');
        Route::post('utilizadores', [Admin\StaffController::class, 'store'])->name('utilizadores.store');
        Route::patch('utilizadores/{staff}', [Admin\StaffController::class, 'update'])->name('utilizadores.update');
        Route::put('utilizadores/{staff}/password', [Admin\StaffController::class, 'resetPassword'])
            ->name('utilizadores.password');
        Route::patch('utilizadores/{staff}/desativar', [Admin\StaffController::class, 'disable'])
            ->name('utilizadores.desativar');
        Route::patch('utilizadores/{staff}/reativar', [Admin\StaffController::class, 'enable'])
            ->name('utilizadores.reativar');
    });

    // ── Backoffice: alias 'admin' (EnsureAdmin) por cima de auth+verified.
    //    Form Requests re-verificam isAdmin() — middleware sozinho nao e
    //    seguranca. URIs em PT, controllers em EN (padrao do plano). ─────────
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/', Admin\DashboardController::class)->name('dashboard');

        // Rascunhos de encomenda manual. ANTES do Route::resource abaixo: o
        // `show` do recurso e `encomendas/{order}`, e um URI literal
        // registado depois dele nunca chegaria a ser alcancado.
        Route::get('encomendas/rascunhos', [Admin\OrderDraftController::class, 'index'])
            ->name('encomendas.rascunhos.index');
        Route::post('encomendas/rascunhos', [Admin\OrderDraftController::class, 'store'])
            ->name('encomendas.rascunhos.store');
        Route::get('encomendas/rascunhos/{draft}', [Admin\OrderDraftController::class, 'edit'])
            ->name('encomendas.rascunhos.edit');
        Route::put('encomendas/rascunhos/{draft}', [Admin\OrderDraftController::class, 'update'])
            ->name('encomendas.rascunhos.update');
        Route::delete('encomendas/rascunhos/{draft}', [Admin\OrderDraftController::class, 'destroy'])
            ->name('encomendas.rascunhos.destroy');

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
        Route::patch('encomendas/{order}/ajuste', [Admin\OrderController::class, 'updateAdjustment'])
            ->name('encomendas.ajuste');
        Route::patch('encomendas/{order}/detalhes', [Admin\OrderController::class, 'updateDetails'])
            ->name('encomendas.detalhes');

        // Cliente = User::scopeCustomers(). Sem `create` nem `edit`: os dois
        // acontecem no modal da listagem, que chega ao cliente certo por
        // `?editar={id}` — como nos produtos.
        Route::resource('clientes', Admin\CustomerController::class)
            ->parameters(['clientes' => 'customer'])
            ->except(['show', 'create', 'edit']);

        // Sem `create`: a categoria nova nasce no modal da propria listagem,
        // como nos produtos. O `edit` fica para a ordem e para arquivar.
        Route::resource('categorias', Admin\CategoryController::class)
            ->parameters(['categorias' => 'category'])
            ->except(['show', 'create']);

        Route::patch('categorias/{category}/restaurar', [Admin\CategoryController::class, 'restore'])
            ->name('categorias.restaurar');

        // O outro eixo de organizacao, e o unico que serve tambem clientes e
        // encomendas. Sem `restaurar`: uma etiqueta nao arquiva, apaga-se mesmo
        // — a excepcao consciente a regra de eliminacao logica, ja escrita na
        // migracao que criou a tabela.
        //
        // A limpeza vem ANTES do resource: `etiquetas/nao-usadas` colidia com o
        // DELETE `etiquetas/{tag}` e o router resolvia a favor do primeiro que
        // registasse.
        Route::delete('etiquetas/nao-usadas', [Admin\TagController::class, 'prune'])
            ->name('etiquetas.limpar');

        Route::resource('etiquetas', Admin\TagController::class)
            ->parameters(['etiquetas' => 'tag'])
            ->except(['show', 'create', 'edit']);

        // Materiais e cores em recursos irmaos, e irmaos mesmo: sao dois eixos
        // independentes. Uma cor nao pertence a um material — quem os cruza e a
        // variante, que aponta para os dois.
        //
        // Sem `create` nem `edit`: o material nasce e edita-se no mesmo modal da
        // listagem, como as cores.
        Route::resource('materiais', Admin\MaterialController::class)
            ->parameters(['materiais' => 'material'])
            ->except(['show', 'create', 'edit']);

        Route::patch('materiais/{material}/restaurar', [Admin\MaterialController::class, 'restore'])
            ->name('materiais.restaurar');

        // Sem `create` nem `edit`: uma cor sao dois campos — nome e tom —, e um
        // modal na propria listagem chega para os dois.
        Route::resource('cores', Admin\ColorController::class)
            ->parameters(['cores' => 'color'])
            ->except(['show', 'create', 'edit']);

        Route::patch('cores/{color}/restaurar', [Admin\ColorController::class, 'restore'])
            ->name('cores.restaurar');

        // Perfis de impressora: o custo/hora de cada maquina, que e a parcela
        // de tempo do preco de cada peca. Sem `create` nem `edit`: a impressora
        // nasce e edita-se no mesmo modal da listagem, como os materiais.
        Route::resource('impressoras', Admin\PrinterProfileController::class)
            ->parameters(['impressoras' => 'printer_profile'])
            ->except(['show', 'create', 'edit']);

        Route::patch('impressoras/{printer_profile}/restaurar', [Admin\PrinterProfileController::class, 'restore'])
            ->name('impressoras.restaurar');

        Route::patch('impressoras/{printer_profile}/predefinida', [Admin\PrinterProfileController::class, 'setDefault'])
            ->name('impressoras.predefinida');

        // Calculadora de precos: simulacao livre, sem gravar nada. O mesmo
        // motor alimenta o formulario de variante.
        Route::get('calculadora', Admin\PricingCalculatorController::class)
            ->name('calculadora');

        // Definicoes editaveis em runtime (tabela `settings`). URIs proprios
        // por acao — o Wayfinder duplica chaves quando dois verbos partilham
        // o mesmo URI fora de um Route::resource.
        Route::get('definicoes', [Admin\SettingController::class, 'index'])
            ->name('definicoes.index');
        Route::patch('definicoes/guardar', [Admin\SettingController::class, 'update'])
            ->name('definicoes.update');
        Route::patch('definicoes/precos', [Admin\SettingController::class, 'updatePricing'])
            ->name('definicoes.precos');
        Route::patch('definicoes/marca', [Admin\SettingController::class, 'updateBrand'])
            ->name('definicoes.marca');

        // Chaves de API do MCP. Cada visita e acao pede a password outra vez:
        // uma chave de escrita e uma sessao de admin que vive fora do browser.
        Route::middleware('password.confirm')->group(function (): void {
            Route::get('definicoes/chaves-api', [Admin\ApiKeyController::class, 'index'])
                ->name('chaves-api.index');
            Route::post('definicoes/chaves-api', [Admin\ApiKeyController::class, 'store'])
                ->middleware('throttle:10,1')
                ->name('chaves-api.store');
            Route::delete('definicoes/chaves-api/todas', [Admin\ApiKeyController::class, 'destroyAll'])
                ->name('chaves-api.destroy-all');
            Route::delete('definicoes/chaves-api/{key}', [Admin\ApiKeyController::class, 'destroy'])
                ->name('chaves-api.destroy');

            // Webhooks do Discord (alertas). Tambem com password: quem os
            // muda escolhe para onde vao os dados dos clientes.
            Route::get('definicoes/alertas', [Admin\AlertSettingsController::class, 'index'])
                ->name('alertas.index');
            Route::put('definicoes/alertas/{channel}/guardar', [Admin\AlertSettingsController::class, 'update'])
                ->whereIn('channel', AlertChannel::ALL)
                ->middleware('throttle:10,1')
                ->name('alertas.update');
            Route::delete('definicoes/alertas/{channel}/remover', [Admin\AlertSettingsController::class, 'destroy'])
                ->whereIn('channel', AlertChannel::ALL)
                ->name('alertas.destroy');
            Route::post('definicoes/alertas/{channel}/testar', [Admin\AlertSettingsController::class, 'test'])
                ->whereIn('channel', AlertChannel::ALL)
                ->middleware('throttle:10,1')
                ->name('alertas.test');

            // A loja da Etsy. Com password pela mesma razao: quem a liga
            // escolhe para onde vao os produtos e de onde entram encomendas.
            Route::get('definicoes/etsy', [Admin\EtsySettingsController::class, 'index'])
                ->name('etsy.index');
            Route::get('definicoes/etsy/ligar', [Admin\EtsySettingsController::class, 'connect'])
                ->middleware('throttle:10,1')
                ->name('etsy.ligar');
            Route::delete('definicoes/etsy/desligar', [Admin\EtsySettingsController::class, 'disconnect'])
                ->name('etsy.desligar');
            Route::post('definicoes/etsy/testar', [Admin\EtsySettingsController::class, 'test'])
                ->middleware('throttle:10,1')
                ->name('etsy.testar');
        });

        // O regresso do OAuth da Etsy, fora do grupo da password: o dono vem
        // de la, e o que o identifica e o `state` que ficou na sessao.
        Route::get('definicoes/etsy/callback', [Admin\EtsySettingsController::class, 'callback'])
            ->middleware('throttle:10,1')
            ->name('etsy.callback');

        // Os anuncios que a loja tem na Etsy, e a ligacao de cada um a um
        // produto. Sem password: aqui nao ha segredos, e ligar nao escreve
        // nada na Etsy.
        Route::get('etsy/anuncios', [Admin\EtsyListingController::class, 'index'])
            ->name('etsy.anuncios.index');
        Route::post('etsy/anuncios/{product}/ligar', [Admin\EtsyListingController::class, 'link'])
            ->name('etsy.anuncios.ligar');
        // withTrashed: o anuncio de um produto apagado tem de poder ser
        // libertado sem primeiro restaurar o produto.
        Route::delete('etsy/anuncios/{product}/desligar', [Admin\EtsyListingController::class, 'unlink'])
            ->withTrashed()
            ->name('etsy.anuncios.desligar');

        // Envios. Os ids (`profile`, `destination`, ...) sao os da Etsy, nao
        // modelos nossos: nada disto vive na base de dados. Um URI por acao,
        // pela mesma razao das definicoes (o Wayfinder).
        Route::prefix('etsy/envios')->name('etsy.envios.')->group(function (): void {
            Route::get('/', [Admin\EtsyShippingController::class, 'index'])->name('index');
            Route::patch('predefinicoes', [Admin\EtsyShippingController::class, 'saveDefaults'])->name('predefinicoes');

            Route::post('perfis/guardar', [Admin\EtsyShippingController::class, 'saveProfile'])->name('perfis.guardar');
            Route::delete('perfis/{profile}/apagar', [Admin\EtsyShippingController::class, 'deleteProfile'])
                ->whereNumber('profile')->name('perfis.apagar');
            Route::post('perfis/{profile}/aplicar', [Admin\EtsyShippingController::class, 'assign'])
                ->whereNumber('profile')->name('perfis.aplicar');

            Route::post('perfis/{profile}/destinos/guardar', [Admin\EtsyShippingController::class, 'saveDestination'])
                ->whereNumber('profile')->name('destinos.guardar');
            Route::delete('perfis/{profile}/destinos/{destination}/apagar', [Admin\EtsyShippingController::class, 'deleteDestination'])
                ->whereNumber(['profile', 'destination'])->name('destinos.apagar');

            Route::post('perfis/{profile}/upgrades/guardar', [Admin\EtsyShippingController::class, 'saveUpgrade'])
                ->whereNumber('profile')->name('upgrades.guardar');
            Route::delete('perfis/{profile}/upgrades/{upgrade}/apagar', [Admin\EtsyShippingController::class, 'deleteUpgrade'])
                ->whereNumber(['profile', 'upgrade'])->name('upgrades.apagar');

            Route::post('processamento/guardar', [Admin\EtsyShippingController::class, 'saveProcessing'])->name('processamento.guardar');
            Route::delete('processamento/{definition}/apagar', [Admin\EtsyShippingController::class, 'deleteProcessing'])
                ->whereNumber('definition')->name('processamento.apagar');

            Route::post('devolucoes/guardar', [Admin\EtsyShippingController::class, 'saveReturnPolicy'])->name('devolucoes.guardar');
            Route::delete('devolucoes/{policy}/apagar', [Admin\EtsyShippingController::class, 'deleteReturnPolicy'])
                ->whereNumber('policy')->name('devolucoes.apagar');
        });

        // A loja em si: textos publicos, seccoes, e a seccao de cada categoria.
        Route::prefix('etsy/loja')->name('etsy.loja.')->group(function (): void {
            Route::get('/', [Admin\EtsyShopController::class, 'index'])->name('index');
            Route::patch('textos', [Admin\EtsyShopController::class, 'updateTexts'])->name('textos');
            Route::patch('categorias', [Admin\EtsyShopController::class, 'saveSectionMap'])->name('categorias');
            Route::post('seccoes/guardar', [Admin\EtsyShopController::class, 'saveSection'])->name('seccoes.guardar');
            Route::delete('seccoes/{section}/apagar', [Admin\EtsyShopController::class, 'deleteSection'])
                ->whereNumber('section')->name('seccoes.apagar');
        });

        // As encomendas da Etsy entram sozinhas; isto e o "importar agora".
        Route::post('etsy/encomendas/importar', [Admin\EtsyOrderController::class, 'import'])
            ->name('etsy.encomendas.importar');
        // O envio de uma encomenda da Etsy, dito (outra vez) a Etsy.
        Route::post('encomendas/{order}/etsy/envio', [Admin\EtsyOrderController::class, 'ship'])
            ->name('encomendas.etsy.envio');

        // Precos e margens na Etsy. A pagina e so contas nossas; as
        // definicoes (margem, taxas, predefinicoes de um anuncio novo)
        // gravam-se ca.
        Route::prefix('etsy/precos')->name('etsy.precos.')->group(function (): void {
            Route::get('/', [Admin\EtsyPricingController::class, 'index'])->name('index');
            Route::patch('definicoes', [Admin\EtsyPricingController::class, 'update'])->name('definicoes');
        });

        // O formulario do produto tem pagina, ao contrario do dos materiais e
        // do das impressoras: sao seis seccoes, uma galeria, uma matriz de
        // variantes e uma ficha de variante por dentro — dentro de um modal, a
        // ficha da variante tinha de tomar conta do ecra todo para caber.
        //
        // Escritas a mao e antes do resource por causa do endereco: o
        // `Route::resource` dava `/produtos/create` e `/produtos/{id}/edit` em
        // ingles, e esta casa fala portugues nos URIs (`/restaurar`,
        // `/imagens`, `/variantes`).
        Route::get('produtos/novo', [Admin\ProductController::class, 'create'])
            ->name('produtos.create');
        Route::get('produtos/{product}/editar', [Admin\ProductController::class, 'edit'])
            ->name('produtos.edit');

        Route::resource('produtos', Admin\ProductController::class)
            ->parameters(['produtos' => 'product'])
            ->except(['show', 'create', 'edit']);

        // withTrashed: restaurar serve os arquivados e os apagados, e um
        // apagado nao se resolve pelo binding normal.
        Route::patch('produtos/{product}/restaurar', [Admin\ProductController::class, 'restore'])
            ->withTrashed()
            ->name('produtos.restaurar');

        // Apagar (reciclagem) tem URI proprio: o DELETE do resource acima
        // arquiva, e continua a arquivar.
        Route::delete('produtos/{product}/apagar', [Admin\ProductController::class, 'delete'])
            ->name('produtos.apagar');

        // O anuncio do produto na Etsy. Guardar fica ca; so o `enviar`
        // escreve na Etsy. Um produto apagado nao tem pagina (o binding
        // normal nao o encontra).
        Route::prefix('produtos/{product}/etsy')->name('produtos.etsy.')->group(function (): void {
            Route::get('/', [Admin\EtsyProductController::class, 'show'])->name('show');
            Route::put('guardar', [Admin\EtsyProductController::class, 'save'])->name('guardar');
            Route::post('enviar', [Admin\EtsyProductController::class, 'push'])->name('enviar');
            Route::post('video', [Admin\EtsyProductController::class, 'uploadVideo'])->name('video');
            Route::delete('video/remover', [Admin\EtsyProductController::class, 'removeVideo'])->name('video.remover');
            // Publicar custa a taxa da Etsy; apagar, la, nao se recupera. O
            // ecra pergunta antes das tres.
            Route::post('publicar', [Admin\EtsyProductController::class, 'publish'])->name('publicar');
            Route::post('desativar', [Admin\EtsyProductController::class, 'deactivate'])->name('desativar');
            Route::delete('apagar', [Admin\EtsyProductController::class, 'destroy'])->name('apagar');
        });

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

        // Shallow: criar dentro do produto, editar/arquivar por
        // /admin/variantes/{variant}. Sem `create` nem `edit`: a ficha da
        // variante abre em gaveta na seccao "Variantes" da pagina do produto, e
        // o painel de custo dela vem da prop `pricing` que o
        // ProductController::edit serve.
        Route::resource('produtos.variantes', Admin\VariantController::class)
            ->parameters(['produtos' => 'product', 'variantes' => 'variant'])
            ->except(['show', 'index', 'create', 'edit'])
            ->shallow();

        // A seccao "Impressao": tempos e gramagens de todas as variantes
        // de uma vez, e os precos calculados a partir deles. Antes do resource
        // nao e preciso — `producao` e `precos` nao colidem com `{variant}`,
        // que so existe em /admin/variantes/{variant}.
        Route::patch('produtos/{product}/variantes/producao', [Admin\VariantProductionController::class, 'update'])
            ->name('produtos.variantes.producao');
        Route::post('produtos/{product}/variantes/precos', [Admin\VariantProductionController::class, 'applyPrices'])
            ->name('produtos.variantes.precos');
    });
});

require __DIR__.'/settings.php';
