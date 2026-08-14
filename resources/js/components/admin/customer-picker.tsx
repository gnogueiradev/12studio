import { Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fold } from '@/lib/text';
import type { CustomerOption } from '@/types/customer';

type Props = {
    customers: CustomerOption[];
    /** O cliente escolhido, ou null quando a venda é a alguém sem conta. */
    selected: CustomerOption | null;
    onPick: (customer: CustomerOption) => void;
    onClear: () => void;
};

/** Quantos resultados se mostram de uma vez. Mais do que isto é uma lista. */
const MAX_RESULTS = 6;

/**
 * Escolher o cliente de uma encomenda manual.
 *
 * É um campo de pesquisa e não um `<Select>` porque a lista são todos os
 * clientes da loja, sem paginação — o seletor nativo obrigava a percorrer
 * centenas de linhas para encontrar uma. Procura por nome, email e NIF, que são
 * as três formas por que um cliente se identifica ao balcão.
 *
 * Escolhido o cliente, os campos do formulário dão lugar a um cartão com os
 * dados dele: já estão decididos, e um formulário preenchido a repeti-los só
 * convida a alterá-los sem intenção.
 */
export function CustomerPicker({
    customers,
    selected,
    onPick,
    onClear,
}: Props) {
    const [query, setQuery] = useState('');

    const results = useMemo(() => {
        const needle = fold(query.trim());

        if (needle === '') {
            return [];
        }

        return customers
            .filter((customer) =>
                [customer.name, customer.email, customer.nif].some(
                    (field) => field !== null && fold(field).includes(needle),
                ),
            )
            .slice(0, MAX_RESULTS);
    }, [customers, query]);

    if (selected !== null) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-xl border border-border bg-secondary/40 px-4 py-3">
                <span className="min-w-0 text-sm">
                    <span className="block font-medium">{selected.name}</span>
                    <span className="block text-xs text-muted-foreground">
                        Cliente registado — a encomenda fica associada à ficha.
                    </span>
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                        setQuery('');
                        onClear();
                    }}
                >
                    <X />
                    Trocar
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor="customer-search">Cliente registado</Label>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    id="customer-search"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder="Procurar por nome, email ou NIF"
                    className="pl-9"
                />
            </div>

            {results.length > 0 && (
                <ul className="flex flex-col overflow-hidden rounded-xl border border-border">
                    {results.map((customer) => (
                        <li
                            key={customer.id}
                            className="border-b border-border/60 last:border-b-0"
                        >
                            <button
                                type="button"
                                onClick={() => {
                                    setQuery('');
                                    onPick(customer);
                                }}
                                className="flex w-full flex-col items-start px-4 py-2 text-left text-sm hover:bg-secondary"
                            >
                                <span className="font-medium">
                                    {customer.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {customer.email ?? 'sem email'}
                                    {customer.nif !== null &&
                                        ` · NIF ${customer.nif}`}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {query.trim() !== '' && results.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    Nenhum cliente com esse nome, email ou NIF. Escreve os dados
                    em baixo — a venda não precisa de uma ficha.
                </p>
            )}

            <p className="text-xs text-muted-foreground">
                Deixa em branco para uma venda a quem não tem conta.
            </p>
        </div>
    );
}
