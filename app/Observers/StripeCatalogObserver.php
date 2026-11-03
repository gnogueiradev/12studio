<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Variant;
use App\Stripe\StripeHooks;

/**
 * Avisa o Stripe quando um produto ou uma variante muda naquilo que ele
 * mostra.
 *
 * No modelo, e nao no ProductService: os precos mudam tambem pela
 * calculadora, pelo MCP e pela paleta de cores, e um aviso posto num so
 * servico deixava os outros caminhos com o Stripe para tras. A propria
 * sincronizacao grava os ids com saveQuietly, e por isso nao volta a passar
 * aqui.
 */
class StripeCatalogObserver
{
    public function __construct(
        private StripeHooks $hooks,
    ) {}

    public function created(Product|Variant $model): void
    {
        $this->notify($model);
    }

    public function updated(Product|Variant $model): void
    {
        $fields = $model instanceof Product ? StripeHooks::PRODUCT_FIELDS : StripeHooks::VARIANT_FIELDS;

        if ($model->wasChanged($fields)) {
            $this->notify($model);
        }
    }

    public function deleted(Product|Variant $model): void
    {
        $this->notify($model);
    }

    public function restored(Product $model): void
    {
        $this->notify($model);
    }

    private function notify(Product|Variant $model): void
    {
        if ($model instanceof Product) {
            $this->hooks->productChanged($model);
        } else {
            $this->hooks->variantChanged($model);
        }
    }
}
