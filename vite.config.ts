import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            // Instrument Sans e a letra da marca e do backoffice. As outras
            // sao as opcoes do tema da montra (App\Services\ThemeSettings::FONTS):
            // ficam alojadas no build, mas o @fonts do Blade so carrega a
            // escolhida.
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Inter', { weights: [400, 500, 600] }),
                bunny('Fraunces', { weights: [400, 600] }),
                bunny('DM Serif Display', { weights: [400] }),
                bunny('Space Grotesk', { weights: [400, 500, 600] }),
            ],
        }),
        inertia(),
        react({
            babel: {
                plugins: ['babel-plugin-react-compiler'],
            },
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
