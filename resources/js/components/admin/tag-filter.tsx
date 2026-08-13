import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Option } from '@/lib/options';

type Props = {
    /** Slug ativo, ou '' quando não há filtro. */
    value: string;
    /** Só etiquetas com pelo menos um uso — o servidor já filtra. */
    options: Option[];
    onChange: (slug: string) => void;
};

// O Radix Select não aceita value="" — sentinela para "sem filtro", a mesma
// convenção das três listagens.
const ALL = 'all';

/**
 * Filtro por etiqueta, partilhado pelas listagens de produtos, clientes e
 * encomendas. O valor que viaja no URL é o slug e não o id: mantém o
 * `?tag=natal` legível e sobrevive a um nome renomeado que dê o mesmo slug.
 *
 * Não se desenha quando não há opções. Um seletor com uma entrada só — "todas"
 * — é uma promessa de filtro que a página não pode cumprir.
 */
export function TagFilter({ value, options, onChange }: Props) {
    if (options.length === 0) {
        return null;
    }

    return (
        <Select
            value={value || ALL}
            onValueChange={(next) => onChange(next === ALL ? '' : next)}
        >
            <SelectTrigger className="w-44">
                <SelectValue placeholder="Etiqueta" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>Todas as etiquetas</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
