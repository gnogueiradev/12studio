<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O `home` do Fortify. A equipa de producao nao tem nada para ver no
 * dashboard do starter nem acesso ao /admin — vai direta ao quadro.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user !== null && ! $user->isAdmin() && $user->isProductionStaff()) {
            return to_route('admin.producao');
        }

        return Inertia::render('dashboard');
    }
}
