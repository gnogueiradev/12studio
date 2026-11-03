import { MenuLink } from '@/components/store/menu-link';
import type { Announcement } from '@/types/store';

/**
 * A faixa de aviso por cima do header ("Portes grátis acima de 25 €"), em
 * todas as páginas da montra. O texto e o link vêm de Definições → Montra, já
 * no idioma da página; com link, o texto todo é o link.
 *
 * As cores são as do botão principal do tema: são as que já se garantem
 * legíveis uma sobre a outra.
 */
export function AnnouncementBar({
    announcement,
}: {
    announcement: Announcement;
}) {
    const { text, href, external } = announcement;

    return (
        <div className="bg-primary px-4 py-2 text-center text-sm text-balance text-primary-foreground">
            {href ? (
                <MenuLink
                    item={{ href, external, label: text }}
                    className="font-medium underline-offset-4 hover:underline focus-visible:underline"
                />
            ) : (
                <p className="font-medium">{text}</p>
            )}
        </div>
    );
}
