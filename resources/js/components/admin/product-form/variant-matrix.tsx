import { useCallback, useMemo } from 'react';
import { ColorSwatchGrid } from '@/components/admin/color-swatch-grid';
import { ToggleChip } from '@/components/admin/toggle-chip';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { cn } from '@/lib/utils';
import type {
    ColorOption,
    MaterialOption,
    ProductFormData,
} from '@/types/catalog';

type Matrix = ProductFormData['variants'];

type Props = {
    value: Matrix;
    onChange: (changes: Partial<Matrix>) => void;
    colors: ColorOption[];
    materials: MaterialOption[];
    /** Erros do pedido inteiro; a matriz escolhe os seus. */
    messages: Record<string, string>;
};

/** Quantas combinações se mostram antes de o resto virar "+N". */
const PREVIEW_LIMIT = 6;

const SIZES = ['Pequeno', 'Médio', 'Grande'] as const;

/**
 * A matriz que gera as variantes de um produto novo: cor × material × tamanho.
 *
 * Só existe a criar. Depois de o produto existir, as variantes editam-se uma a
 * uma na lista — um campo aqui em cima prometia escrever nas trinta de uma vez.
 *
 * Não é um produto cartesiano, é uma INTERSECÇÃO: os eixos cruzam-se com o
 * catálogo de bobines e o par que o dono não tem cai fora. Ver
 * ProductService::generateVariants.
 */
export function VariantMatrix({
    value,
    onChange,
    colors,
    materials,
    messages,
}: Props) {
    const colorsById = useMemo(
        () => new Map(colors.map((color) => [color.id, color] as const)),
        [colors],
    );

    const materialsById = useMemo(
        () =>
            new Map(
                materials.map((material) => [material.id, material] as const),
            ),
        [materials],
    );

    const toggle = <T,>(list: readonly T[], item: T): T[] =>
        list.includes(item)
            ? list.filter((current) => current !== item)
            : [...list, item];

    /*
     * A mesma ordem que o ProductService usa para gerar (cor por fora, material
     * no meio, tamanho por dentro), para a pré-visualização não prometer uma
     * coisa e as variantes saírem por outra — e o mesmo filtro: o par que o
     * dono não tem não aparece aqui porque não vai nascer lá.
     */
    const combos = useMemo(() => {
        const sizes = value.sizes.length ? value.sizes : [null];

        return value.color_ids.flatMap((colorId) =>
            value.material_ids
                .filter(
                    (materialId) =>
                        colorsById
                            .get(colorId)
                            ?.materialIds.includes(materialId) ?? false,
                )
                .flatMap((materialId) =>
                    sizes.map((size) => ({
                        key: `${colorId}-${materialId}-${size ?? ''}`,
                        label: [
                            colorsById.get(colorId)?.name,
                            materialsById.get(materialId)?.name,
                            size,
                        ]
                            .filter(
                                (part) => part !== null && part !== undefined,
                            )
                            .join(' · '),
                    })),
                ),
        );
    }, [value, colorsById, materialsById]);

    /*
     * Quantos pares o catálogo deixou cair. Não é um erro — escolher eixos e
     * receber a intersecção é a funcionalidade —, mas tem de se dizer em voz
     * alta: uma matriz que promete oito variantes e entrega seis, em silêncio,
     * é a mesma surpresa que isto veio resolver, ao contrário.
     */
    const skipped =
        value.color_ids.length *
            value.material_ids.length *
            Math.max(value.sizes.length, 1) -
        combos.length;

    /** Nomes dos filamentos, na ordem em que vieram. */
    const materialNames = useCallback(
        (ids: number[]) =>
            ids
                .map((id) => materialsById.get(id)?.name)
                .filter((name): name is string => name !== undefined),
        [materialsById],
    );

    /*
     * Uma cor só se bloqueia quando é impossível em TODOS os materiais
     * escolhidos.
     *
     * Se escolheste PLA e Silk e o rosa só existe em PLA, tu CONSEGUES fazer a
     * peça em rosa — bloquear o rosa por causa do Silk era recusar uma venda
     * que sabes fazer. O que se faz é gerar só o par que existe e dizê-lo, na
     * nota logo abaixo da grelha.
     */
    const impossibleColorIds = useMemo(() => {
        if (value.material_ids.length === 0) {
            return [];
        }

        return colors
            .filter(
                (color) =>
                    !value.material_ids.some((materialId) =>
                        color.materialIds.includes(materialId),
                    ),
            )
            .map((color) => color.id);
    }, [colors, value.material_ids]);

    const colorReason = (colorId: number) => {
        const color = colorsById.get(colorId);

        if (color === undefined) {
            return '';
        }

        if (color.materialIds.length === 0) {
            return `Ainda não disseste em que filamentos tens ${color.name}.`;
        }

        const names = materialNames(color.materialIds);

        return names.length === 0
            ? `Não tens ${color.name} em nenhum destes filamentos.`
            : `Só tens ${color.name} em ${names.join(', ')}.`;
    };

    /*
     * O que cada cor ESCOLHIDA vai dar, quando não dá tudo.
     *
     * Uma cor escolhida nunca fica esbatida — senão, mudar de material
     * deixava-a presa na selecção sem forma de a tirar —, por isso é aqui que
     * ela tem de dizer o que lhe falta. Sem esta nota, quem escolheu duas cores
     * e dois materiais conta quatro variantes de cabeça e recebe três sem saber
     * qual caiu.
     */
    const partialColors = useMemo(() => {
        if (value.material_ids.length === 0) {
            return [];
        }

        return value.color_ids.flatMap((colorId) => {
            const color = colorsById.get(colorId);

            if (color === undefined) {
                return [];
            }

            const possible = value.material_ids.filter((materialId) =>
                color.materialIds.includes(materialId),
            );

            if (possible.length === value.material_ids.length) {
                return [];
            }

            const names = materialNames(possible);

            return [
                names.length === 0
                    ? `${color.name}: não tens em nenhum destes filamentos`
                    : `${color.name}: só em ${names.join(' e ')}`,
            ];
        });
    }, [value.color_ids, value.material_ids, colorsById, materialNames]);

    /*
     * Só os eixos: os três preços mostram o erro por baixo do campo, e um
     * apanha-tudo sobre `variants` repetia essa mensagem no fim da secção,
     * longe do campo que a causou.
     */
    const axisError = Object.entries(messages).find(
        ([key]) =>
            key.startsWith('variants.color_ids') ||
            key.startsWith('variants.material_ids') ||
            key.startsWith('variants.sizes'),
    )?.[1];

    const empty = colors.length === 0 || materials.length === 0;

    return (
        <div className="flex flex-col gap-4">
            {empty ? (
                <p className="rounded-xl border border-dashed border-border px-6 py-8 text-center text-sm text-muted-foreground">
                    {colors.length === 0
                        ? 'Ainda não há cores ativas. Cria uma antes de gerar variantes.'
                        : 'Ainda não há materiais ativos. Cria um antes de gerar variantes.'}
                </p>
            ) : (
                <>
                    <div className="grid gap-2">
                        <span className="text-xs text-muted-foreground">
                            Cor
                        </span>
                        <ColorSwatchGrid
                            colors={colors}
                            multiple
                            value={value.color_ids}
                            onChange={(color_ids) => onChange({ color_ids })}
                            disabledIds={impossibleColorIds}
                            disabledReason={colorReason}
                        />
                        {partialColors.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {partialColors.join(' · ')}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <span className="text-xs text-muted-foreground">
                            Material
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {materials.map((material) => (
                                <ToggleChip
                                    key={material.id}
                                    active={value.material_ids.includes(
                                        material.id,
                                    )}
                                    onClick={() =>
                                        onChange({
                                            material_ids: toggle(
                                                value.material_ids,
                                                material.id,
                                            ),
                                        })
                                    }
                                >
                                    {material.name}
                                    <span className="text-muted-foreground tabular-nums">
                                        {formatCents(material.pricePerKgCents)}
                                    </span>
                                </ToggleChip>
                            ))}
                        </div>
                    </div>
                </>
            )}

            <div className="grid gap-2">
                <span className="text-xs text-muted-foreground">
                    Tamanho <span className="opacity-70">(opcional)</span>
                </span>
                <div className="flex flex-wrap gap-2">
                    {SIZES.map((size) => (
                        <ToggleChip
                            key={size}
                            active={value.sizes.includes(size)}
                            onClick={() =>
                                onChange({ sizes: toggle(value.sizes, size) })
                            }
                        >
                            {size}
                        </ToggleChip>
                    ))}
                </div>
            </div>

            {/*
             * Os três preços são o molde que a matriz aplica a todas as
             * combinações. A gramagem e o tempo de impressão não estão aqui de
             * propósito — são dados de produção e entram depois, na secção
             * "Impressão", que os escreve em todas as variantes de uma vez.
             */}
            <div className="grid gap-4 sm:grid-cols-3">
                <div className="grid gap-2">
                    <Label htmlFor="matrix-wholesale-price">
                        Preço de revenda
                    </Label>
                    <div className="relative">
                        <Input
                            id="matrix-wholesale-price"
                            type="number"
                            step="0.01"
                            min={0}
                            value={value.wholesale_price}
                            onChange={(event) =>
                                onChange({
                                    wholesale_price: event.target.value,
                                })
                            }
                            placeholder="21,00"
                            className="pr-8 tabular-nums"
                        />
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                            €
                        </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Só no backoffice — a montra nunca o mostra.
                    </p>
                    <InputError
                        message={messages['variants.wholesale_price']}
                    />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="matrix-price">Preço de venda</Label>
                    <div className="relative">
                        <Input
                            id="matrix-price"
                            type="number"
                            step="0.01"
                            min={0}
                            value={value.normal_price}
                            onChange={(event) =>
                                onChange({ normal_price: event.target.value })
                            }
                            placeholder="29,00"
                            className="pr-8 tabular-nums"
                        />
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                            €
                        </span>
                    </div>
                    <InputError message={messages['variants.normal_price']} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="matrix-sale-price">Preço em promo</Label>
                    <div className="relative">
                        <Input
                            id="matrix-sale-price"
                            type="number"
                            step="0.01"
                            min={0}
                            value={value.sale_price}
                            onChange={(event) =>
                                onChange({ sale_price: event.target.value })
                            }
                            placeholder="Opcional"
                            className="pr-8 tabular-nums"
                        />
                        <span className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted-foreground">
                            €
                        </span>
                    </div>
                    <InputError message={messages['variants.sale_price']} />
                </div>
            </div>

            <div className="rounded-xl border border-border/60 bg-secondary/40 px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <span
                        className={cn(
                            'text-sm font-semibold',
                            combos.length === 0 &&
                                'font-normal text-muted-foreground',
                        )}
                    >
                        {combos.length === 0
                            ? skipped > 0
                                ? 'Não tens nenhuma destas cores nestes filamentos'
                                : 'Escolhe pelo menos uma cor e um material'
                            : combos.length === 1
                              ? '1 variante vai ser criada'
                              : `${combos.length} variantes vão ser criadas`}
                    </span>
                    {combos.length > 0 && skipped > 0 && (
                        <span className="text-xs text-muted-foreground">
                            {skipped === 1
                                ? '1 combinação que não tens ficou de fora'
                                : `${skipped} combinações que não tens ficaram de fora`}
                        </span>
                    )}
                </div>

                {combos.length > 0 && (
                    <div className="mt-2.5 flex flex-wrap gap-1.5">
                        {combos.slice(0, PREVIEW_LIMIT).map((combo) => (
                            <span
                                key={combo.key}
                                className="rounded-md border border-border/60 bg-card px-2 py-1 text-xs text-muted-foreground"
                            >
                                {combo.label}
                            </span>
                        ))}
                        {combos.length > PREVIEW_LIMIT && (
                            <span className="px-1 py-1 text-xs text-muted-foreground">
                                +{combos.length - PREVIEW_LIMIT}
                            </span>
                        )}
                    </div>
                )}

                <p className="mt-2.5 text-xs text-muted-foreground">
                    As referências são geradas a partir do nome. O stock começa
                    a zero — a primeira contagem entra pelo registo de stock de
                    cada variante.
                </p>
            </div>

            <InputError message={axisError} />
        </div>
    );
}

/**
 * Se a matriz dá para gravar: cor E material E pelo menos um par possível, a
 * espelhar o ProductService::generateVariants(). O terceiro é o que impede
 * gravar um produto cuja matriz o catálogo esvaziou por inteiro — o servidor
 * recusa-o na mesma, mas depois de o formulário já ter sido submetido.
 */
export function matrixIsPrintable(
    value: Matrix,
    colors: ColorOption[],
): boolean {
    const possible = value.color_ids.some((colorId) => {
        const color = colors.find((candidate) => candidate.id === colorId);

        return (
            color !== undefined &&
            value.material_ids.some((materialId) =>
                color.materialIds.includes(materialId),
            )
        );
    });

    return (
        value.color_ids.length > 0 && value.material_ids.length > 0 && possible
    );
}
