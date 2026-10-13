import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

export type TaxonomyNode = {
    id: number;
    name: string;
    /** O caminho inteiro: "Home & Living > Home Decor > Vases". */
    path: string;
    level: number;
    is_leaf: boolean;
};

type Props = {
    id: string;
    value: number | null;
    onChange: (taxonomyId: number | null) => void;
    /** O que a última procura devolveu (prop opcional da página). */
    results: TaxonomyNode[] | undefined;
    /** O que vale quando não há escolha própria, para o dizer. */
    fallbackId?: number | null;
};

/**
 * A categoria da Etsy. A árvore são milhares de nós, por isso procura-se: o
 * texto vai ao servidor numa recarga parcial (`?categoria=`) e só o resultado
 * volta. Os nomes estão em inglês, como a Etsy os tem.
 */
export function TaxonomyPicker({
    id,
    value,
    onChange,
    results,
    fallbackId = null,
}: Props) {
    const [query, setQuery] = useState('');
    const [searching, setSearching] = useState(false);
    /** O nome do que foi escolhido nesta visita; o servidor só guarda o número. */
    const [chosen, setChosen] = useState<TaxonomyNode | null>(null);

    const search = () => {
        if (query.trim() === '') {
            return;
        }

        setSearching(true);
        router.reload({
            only: ['taxonomyResults'],
            data: { categoria: query.trim() },
            onFinish: () => setSearching(false),
        });
    };

    return (
        <div className="flex flex-col gap-2">
            <p className="text-sm">
                {value === null ? (
                    <span className="text-muted-foreground">
                        {fallbackId === null
                            ? 'Nenhuma escolhida.'
                            : `A predefinida (n.º ${fallbackId}).`}
                    </span>
                ) : (
                    <>
                        <span className="font-medium">
                            {chosen?.id === value
                                ? chosen.path
                                : `Categoria n.º ${value}`}
                        </span>{' '}
                        <button
                            type="button"
                            className="text-xs text-muted-foreground underline underline-offset-4 hover:text-foreground"
                            onClick={() => {
                                setChosen(null);
                                onChange(null);
                            }}
                        >
                            tirar
                        </button>
                    </>
                )}
            </p>

            <div className="flex gap-2">
                <Input
                    id={id}
                    value={query}
                    placeholder="Procurar em inglês: vase, planter, keychain…"
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            // Dentro de um formulário, Enter gravava-o.
                            event.preventDefault();
                            search();
                        }
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={search}
                    disabled={searching || query.trim() === ''}
                >
                    {searching && <Spinner />}
                    Procurar
                </Button>
            </div>

            {results !== undefined && results.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    Nenhuma categoria com esse texto. Tenta um termo mais geral.
                </p>
            )}

            {results !== undefined && results.length > 0 && (
                <ul className="max-h-56 overflow-y-auto rounded-lg border border-border/60 text-[13px]">
                    {results.map((node) => (
                        <li
                            key={node.id}
                            className="border-b border-border/40 last:border-b-0"
                        >
                            <button
                                type="button"
                                className="flex w-full items-baseline justify-between gap-3 px-3 py-1.5 text-left transition-colors hover:bg-secondary"
                                aria-pressed={node.id === value}
                                onClick={() => {
                                    setChosen(node);
                                    onChange(node.id);
                                }}
                            >
                                <span>{node.path}</span>
                                {!node.is_leaf && (
                                    <span className="flex-none text-xs text-muted-foreground">
                                        tem subcategorias
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
