<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base dos pedidos que so um administrador pode fazer.
 *
 * O routes/web.php diz, e cumpre, que "middleware sozinho nao e seguranca": o
 * alias `admin` (EnsureAdmin) e a primeira barreira, e o authorize() de cada
 * Form Request e a segunda. Se um dia uma rota sair do grupo por engano — uma
 * reorganizacao do ficheiro, um copy-paste — a segunda continua de pe.
 *
 * Existe para as accoes destrutivas, que nao tinham Form Request nenhum por nao
 * receberem corpo de pedido, e ficavam so com a primeira barreira.
 */
abstract class AdminFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }
}
