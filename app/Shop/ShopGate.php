<?php

namespace App\Shop;

use App\Models\User;
use App\Services\StoreSettings;
use App\Stripe\StripeClient;
use App\Stripe\StripeWebhookSignature;

/**
 * Quem pode comprar na montra. Tudo o que e carrinho e compra esconde-se
 * atras disto: sem Stripe (ou sem o segredo do webhook, que e quem marca a
 * encomenda paga) a loja comporta-se como desligada, escolha o dono o que
 * escolher.
 */
class ShopGate
{
    public function __construct(
        private StoreSettings $store,
        private StripeClient $stripe,
        private StripeWebhookSignature $webhook,
    ) {}

    public function ready(): bool
    {
        return $this->stripe->isEnabled() && $this->webhook->isConfigured();
    }

    public function allows(?User $user): bool
    {
        if (! $this->ready()) {
            return false;
        }

        return match ($this->store->shopMode()) {
            'on' => true,
            'staff' => $user?->isAdmin() === true,
            default => false,
        };
    }
}
