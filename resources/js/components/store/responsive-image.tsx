import type { ImgHTMLAttributes } from 'react';
import type { ResponsiveImageData } from '@/types/store';

type Props = Omit<
    ImgHTMLAttributes<HTMLImageElement>,
    'src' | 'srcSet' | 'width' | 'height' | 'alt'
> & {
    image: ResponsiveImageData;
    /**
     * Quanto da largura do ecrã a imagem ocupa, para o browser escolher a
     * versão certa do srcset. Ex.: "(min-width: 1024px) 25vw, 50vw".
     */
    sizes: string;
    /**
     * A imagem principal do ecrã (hero, foto do produto): carrega logo e com
     * prioridade. Todas as outras esperam até estarem perto do ecrã.
     */
    priority?: boolean;
};

/**
 * Imagem da montra: WebP do tamanho certo quando há versões reduzidas, o
 * original enquanto não há. As medidas reservam o espaço antes de a imagem
 * chegar, para a página não saltar.
 */
export function ResponsiveImage({
    image,
    sizes,
    priority = false,
    ...props
}: Props) {
    return (
        <img
            src={image.src}
            srcSet={image.srcset ?? undefined}
            sizes={image.srcset ? sizes : undefined}
            width={image.width ?? undefined}
            height={image.height ?? undefined}
            alt={image.alt}
            loading={priority ? 'eager' : 'lazy'}
            fetchPriority={priority ? 'high' : 'auto'}
            decoding="async"
            {...props}
        />
    );
}
