<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\UpdateProductionStatusRequest;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class OrderItemController extends Controller
{
    public function __construct(
        private OrderService $orderService,
    ) {}

    /**
     * Avanco de producao de um item. Usado tanto pelo quadro como pelo
     * detalhe da encomenda — a regra vive so no OrderService.
     */
    public function updateProduction(UpdateProductionStatusRequest $request, OrderItem $item): RedirectResponse
    {
        try {
            $this->orderService->setItemProductionStatus(
                $item,
                $request->string('production_status')->value(),
                $request->user(),
                $request->input('note'),
            );
        } catch (RuntimeException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back();
        }

        $this->toast('Produção atualizada.');

        return back();
    }
}
