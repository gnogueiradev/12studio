<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyOrderImporter;
use App\Etsy\EtsyShipments;
use App\Http\Controllers\Admin\Concerns\AttemptsEtsy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Etsy\PushEtsyShipmentRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;

/**
 * As duas coisas que se pedem a mao sobre as encomendas da Etsy: importar
 * agora (entram sozinhas de 5 em 5 minutos; isto e para quem nao quer
 * esperar) e mandar o envio de uma encomenda para la.
 */
class EtsyOrderController extends Controller
{
    use AttemptsEtsy;

    public function __construct(
        private EtsyOrderImporter $importer,
        private EtsyShipments $shipments,
    ) {}

    /**
     * Mandar o envio para a Etsy a partir da pagina da encomenda: quando o
     * aviso automatico falhou, ou quando o seguimento so chegou depois. A
     * Etsy volta a avisar o comprador — o ecra pergunta antes.
     */
    public function ship(PushEtsyShipmentRequest $request, Order $order): RedirectResponse
    {
        if (! in_array($order->status, ['shipped', 'delivered'], true)) {
            $this->toast('Esta encomenda ainda não foi enviada: passa-a a "Enviado" e o 12studio avisa a Etsy sozinho.', 'error');

            return back();
        }

        return $this->attempt(function () use ($request, $order): null {
            $this->shipments->push($order, ['resend' => true, 'note_to_buyer' => $request->validated('note_to_buyer')]);

            return null;
        }, 'Envio dito à Etsy. O comprador recebe o email dela.');
    }

    public function import(AdminActionRequest $request): RedirectResponse
    {
        return $this->attempt(function (): string {
            $result = $this->importer->run();

            if ($result['fetched'] === 0) {
                return 'Não há nada de novo na Etsy.';
            }

            return sprintf(
                'Etsy: %d encomenda(s) nova(s), %d atualizada(s)%s%s.',
                $result['imported'],
                $result['updated'],
                $result['review'] > 0 ? ", {$result['review']} por rever" : '',
                $result['failed'] > 0 ? ", {$result['failed']} falhada(s)" : '',
            );
        }, 'Importação feita.');
    }
}
