import type { SVGAttributes } from 'react';

/**
 * Marca do 12studio: o "12" do logotipo reduzido a silhueta a uma cor.
 *
 * Nao leva `fill` proprio — os chamadores usam `fill-current` e a cor vem do
 * tema, o que obriga a marca a funcionar nas duas polaridades (escura sobre a
 * pagina no header, clara sobre `bg-sidebar-primary` na sidebar).
 *
 * O viewBox e o recorte justo da mesma geometria de `public/favicon.svg`
 * (que usa 0 0 64 64, porque uma tela de icone e quadrada). Aqui a margem sai,
 * para a marca encher a largura que o CSS lhe der.
 *
 * A variante em camadas do logotipo vive so no apple-touch-icon: abaixo de
 * ~48px as folgas caem para menos de 1px e a marca deixa de se ler.
 * Ver docs/superpowers/specs/2026-08-11-favicon-marca-design.md.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="4 13 56 38" xmlns="http://www.w3.org/2000/svg">
            <path d="M4 13 H23 V51 H13.5 V20.5 H4 Z" />
            <path d="M29 51 H60 V41.5 H48.5 L57.2 37.4 A15.5 15.5 0 1 0 29 28.5 H38.5 A6 6 0 1 1 49.4 31.9 L29 41.5 Z" />
        </svg>
    );
}
