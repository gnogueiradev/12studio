import { Badge } from '@/components/ui/badge';

type Props = {
    tags: string[];
    /** Acima disto o resto conta-se em vez de se mostrar. */
    max?: number;
};

/**
 * As etiquetas de uma linha de listagem.
 *
 * Sem cor: a cor é o que distingue a categoria, que é uma só. Vinte etiquetas
 * coloridas numa tabela viram confetti, e cada tom seria mais um problema de
 * contraste a resolver nos dois temas.
 *
 * Vão por baixo da célula principal e não numa coluna nova — as tabelas de
 * produtos e de encomendas já têm sete colunas.
 */
export function TagChips({ tags, max = 3 }: Props) {
    if (tags.length === 0) {
        return null;
    }

    const shown = tags.slice(0, max);
    const hidden = tags.length - shown.length;

    return (
        <span className="mt-1 flex flex-wrap items-center gap-1">
            {shown.map((tag) => (
                <Badge key={tag} variant="secondary" className="font-normal">
                    {tag}
                </Badge>
            ))}
            {hidden > 0 && (
                <span className="text-xs text-muted-foreground">+{hidden}</span>
            )}
        </span>
    );
}
