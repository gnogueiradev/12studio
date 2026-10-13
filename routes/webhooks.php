<?php

use App\Http\Controllers\EtsyWebhookController;
use Illuminate\Support\Facades\Route;

// ── Avisos de fora (webhooks) ────────────────────────────────────────────────
// Carregado SEM o grupo `web` (bootstrap/app.php): quem chama e um servidor,
// nao um browser — nao ha sessao, nem cookies, nem token de CSRF para mandar.
// O que guarda cada rota daqui e a assinatura do pedido, conferida no
// controlador, e um teto de pedidos por IP.

// A Etsy avisa quando ha uma venda (ou um cancelamento, um envio). So manda
// correr a importacao de sempre; ver EtsyWebhookController.
Route::post('webhooks/etsy', EtsyWebhookController::class)
    ->middleware('throttle:etsy-webhook')
    ->name('webhooks.etsy');
