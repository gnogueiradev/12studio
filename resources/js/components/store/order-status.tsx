import { useT } from '@/lib/i18n';

/** O estado de uma encomenda, em texto: a cor sozinha não diz nada a quem não a vê. */
export function OrderStatus({ status }: { status: string }) {
    const t = useT();

    return (
        <span className="inline-flex rounded-full border border-border px-2.5 py-0.5 text-xs font-medium">
            {t(`account.status.${status}`)}
        </span>
    );
}
