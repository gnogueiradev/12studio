<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductionAccess
{
    /**
     * Quadro de producao: admins e a equipa de producao. So guarda as duas
     * rotas do quadro — tudo o resto do /admin continua atras do `admin`, e o
     * tests/Feature/Admin/ProductionStaffAccessTest.php percorre todas para o
     * garantir.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->canAccessProduction()) {
            abort(403);
        }

        return $next($request);
    }
}
