<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Services\MenuSanitizer;
use App\Services\StoreMenus;
use App\Store\StoreLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Os menus do header e do rodape da montra.
 */
class MenuController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/montra/menus', [
            'menus' => [
                'header' => $this->editable('header'),
                'footer' => $this->editable('footer'),
            ],
            'locales' => array_keys((array) config('locales.available')),
            'links' => StoreLinks::all(),
            'limits' => MenuSanitizer::LIMITS,
        ]);
    }

    public function update(Request $request, MenuSanitizer $sanitizer, string $location): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless(in_array($location, Menu::LOCATIONS, true), 404);

        try {
            $items = $sanitizer->clean($location, (array) $request->input('items', []));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors(), $location);
        }

        Menu::query()->updateOrCreate(['location' => $location], ['items' => $items]);

        $this->toast($location === 'header' ? 'Menu do header guardado.' : 'Rodapé guardado.');

        return back();
    }

    public function reset(Request $request, string $location): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        Menu::query()->where('location', $location)->delete();

        $this->toast('Menu reposto como de origem.');

        return back();
    }

    /**
     * O menu como o editor o edita: os itens de origem (com chaves de
     * traducao) passam a rotulos em cada idioma.
     *
     * @return array<int, array<string, mixed>>
     */
    private function editable(string $location): array
    {
        $items = Menu::query()->where('location', $location)->value('items');

        if (! is_array($items)) {
            $items = StoreMenus::defaults($location);
        }

        $convert = function (array $item) use (&$convert): array {
            $labels = is_array($item['label'] ?? null) ? $item['label'] : [];

            if (is_string($item['label_key'] ?? null)) {
                foreach (array_keys((array) config('locales.available')) as $locale) {
                    $labels[$locale] = (string) trans('store.'.$item['label_key'], [], $locale);
                }
            }

            return [
                'label' => $labels,
                'type' => $item['type'] ?? 'path',
                'target' => $item['target'] ?? '',
                'children' => array_map($convert, array_values(array_filter((array) ($item['children'] ?? []), 'is_array'))),
            ];
        };

        return array_map($convert, array_values(array_filter($items, 'is_array')));
    }
}
