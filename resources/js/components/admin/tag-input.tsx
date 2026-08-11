import { X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';

type Props = {
    id?: string;
    value: string[];
    onChange: (tags: string[]) => void;
    /** Etiquetas já usadas noutros produtos, para sugerir em vez de duplicar. */
    suggestions: string[];
    max?: number;
};

/** A mesma normalização do servidor: "Natal" e "natal" são a mesma etiqueta. */
const key = (tag: string) => tag.trim().toLowerCase();

export function TagInput({
    id,
    value,
    onChange,
    suggestions,
    max = 20,
}: Props) {
    const [draft, setDraft] = useState('');

    const available = useMemo(() => {
        const chosen = new Set(value.map(key));
        const query = key(draft);

        return suggestions
            .filter((tag) => !chosen.has(key(tag)))
            .filter((tag) => query === '' || key(tag).includes(query))
            .slice(0, 8);
    }, [suggestions, value, draft]);

    const add = (tag: string) => {
        const clean = tag.trim();

        if (clean === '' || value.length >= max) {
            setDraft('');

            return;
        }

        if (!value.some((existing) => key(existing) === key(clean))) {
            onChange([...value, clean]);
        }

        setDraft('');
    };

    const remove = (tag: string) =>
        onChange(value.filter((existing) => existing !== tag));

    const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',') {
            // Enter dentro de um formulário submete-o; aqui só fecha a etiqueta.
            event.preventDefault();
            add(draft);

            return;
        }

        // Apagar com o campo vazio tira a última — o atalho que toda a gente
        // tenta sem pensar.
        if (event.key === 'Backspace' && draft === '' && value.length > 0) {
            remove(value[value.length - 1]);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            {value.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {value.map((tag) => (
                        <Badge key={tag} variant="secondary" className="gap-1">
                            {tag}
                            <button
                                type="button"
                                onClick={() => remove(tag)}
                                className="text-muted-foreground hover:text-foreground"
                                aria-label={`Remover ${tag}`}
                            >
                                <X className="size-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}

            <Input
                id={id}
                value={draft}
                onChange={(event) => setDraft(event.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={() => add(draft)}
                maxLength={60}
                disabled={value.length >= max}
                placeholder={
                    value.length >= max
                        ? `Máximo de ${max} etiquetas`
                        : 'Escreve e carrega Enter'
                }
            />

            {available.length > 0 && (
                <div className="flex flex-wrap gap-1.5">
                    {available.map((tag) => (
                        <button
                            key={tag}
                            type="button"
                            onClick={() => add(tag)}
                            className="rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:text-foreground"
                        >
                            + {tag}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
