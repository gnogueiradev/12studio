<?php

use App\Http\Controllers\EtsyWebhookController;
use App\Http\Controllers\StripeWebhookController;
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

// O Stripe avisa quando uma encomenda foi paga pelo link de pagamento (ou
// reembolsada). Ver StripeWebhookController.
Route::post('webhooks/stripe', StripeWebhookController::class)
    ->middleware('throttle:stripe-webhook')
    ->name('webhooks.stripe');
