<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyOrderImporter;
use App\Http\Controllers\Admin\Concerns\AttemptsEtsy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use Illuminate\Http\RedirectResponse;

/**
 * "Importar agora": as encomendas da Etsy entram sozinhas de 5 em 5 minutos,
 * e isto e para quem acabou de ver uma venda no telemovel e nao quer esperar.
 */
class EtsyOrderController extends Controller
{
    use AttemptsEtsy;

    public function __construct(
        private EtsyOrderImporter $importer,
    ) {}

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
