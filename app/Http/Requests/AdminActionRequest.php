<?php

namespace App\Http\Requests;

/**
 * Accao de administrador sem corpo de pedido: apagar, restaurar, promover a
 * principal, limpar etiquetas orfas.
 *
 * Nao valida nada — o que traz e o authorize() da classe base. Aparece na
 * assinatura dos controllers sem ser usada no corpo do metodo de proposito: o
 * simples facto de estar la e o que faz o Laravel resolver o Form Request, e
 * portanto correr a verificacao de administrador, antes de a accao acontecer.
 */
class AdminActionRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
