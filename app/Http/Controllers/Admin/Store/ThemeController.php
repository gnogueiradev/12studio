<?php

namespace App\Http\Controllers\Admin\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\UpdateThemeRequest;
use App\PageBuilder\Pages;
use App\Services\SettingService;
use App\Services\ThemeSettings;
use Illuminate\Foundation\Vite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * O tema da montra (so o dono — ver as rotas). A pre-visualizacao do editor e
 * a propria montra num iframe; as cores ainda por gravar chegam-lhe pelo
 * preview(), que devolve o CSS (e as letras) gerados pelo ThemeSettings — o
 * mesmo codigo que vai servir a montra depois de gravado.
 */
class ThemeController extends Controller
{
    public function __construct(
        private SettingService $settings,
        private ThemeSettings $theme,
    ) {}

    public function edit(Pages $pages): Response
    {
        return Inertia::render('admin/montra/tema', [
            'theme' => $this->theme->toForm(),
            'defaults' => [
                ...ThemeSettings::DEFAULTS,
                'radius' => ThemeSettings::DEFAULT_RADIUS,
                'body_font' => ThemeSettings::DEFAULT_FONT,
                'heading_font' => ThemeSettings::DEFAULT_FONT,
            ],
            'fonts' => ThemeSettings::fontOptions(),
            'radii' => array_keys(ThemeSettings::RADII),
            'previewUrl' => route('admin.montra.preview', $pages->system('home'), false),
        ]);
    }

    public function update(UpdateThemeRequest $request): RedirectResponse
    {
        $this->settings->set(ThemeSettings::KEY, $this->theme->fromForm($request->validated()));

        $this->toast('Tema guardado. A montra já está com as cores novas.');

        return to_route('admin.montra.tema.edit');
    }

    /**
     * O CSS de um tema por gravar, para o iframe da pre-visualizacao. Sem a
     * verificacao de contraste: e ao experimentar que se ve que nao chega.
     */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isOwner(), 403);

        $rules = Arr::except((new UpdateThemeRequest)->rules(), ['logo', 'favicon']);
        $theme = $this->theme->fromForm($request->validate($rules));

        try {
            $fonts = (string) app(Vite::class)->fonts(array_values(array_unique([$theme['body_font'], $theme['heading_font']])));
        } catch (Throwable) {
            $fonts = '';
        }

        return response()->json([
            'css' => ThemeSettings::render($theme),
            'fonts' => $fonts,
        ]);
    }

    public function reset(): RedirectResponse
    {
        abort_unless(request()->user()?->isOwner(), 403);

        $this->settings->forget(ThemeSettings::KEY);

        $this->toast('Tema reposto como de origem.');

        return to_route('admin.montra.tema.edit');
    }
}
