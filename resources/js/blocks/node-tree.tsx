import type { CSSProperties } from 'react';
import { ResponsiveImage } from '@/components/store/responsive-image';
import { isContainer, resolveDevices } from '@/lib/page-tree';
import { cn } from '@/lib/utils';
import type {
    StoreBlock,
    StoreContainer,
    StoreDevice,
    StoreDeviceValues,
    StoreNode,
} from '@/types/store';
import { BACKGROUNDS } from './section';
import { BLOCK_COMPONENTS } from './index';

/**
 * A página como o PageRenderer a entrega: contentores (caixas flex) com
 * contentores ou elementos lá dentro. Os valores por dispositivo saem como
 * variáveis CSS (--pb-<prop>-d|t|m) que o resources/css/page-builder.css lê
 * com as media queries; o que o dono não definiu não sai, e o contentor fica
 * neutro.
 */
export function NodeTree({ nodes }: { nodes: StoreNode[] }) {
    const firstId = firstElementId(nodes);

    return (
        <>
            {nodes.map((node) => (
                <TreeNode
                    key={node.id}
                    node={node}
                    firstId={firstId}
                    stacked={false}
                />
            ))}
        </>
    );
}

type NodeProps = {
    node: StoreNode;
    /** O primeiro elemento da página leva o <h1> e a imagem com prioridade. */
    firstId: string | null;
    /** O pai é uma linha que passou a coluna no telemóvel. */
    stacked: boolean;
};

function TreeNode({ node, firstId, stacked }: NodeProps) {
    return isContainer(node) ? (
        <ContainerNode node={node} firstId={firstId} stacked={stacked} />
    ) : (
        <ElementNode block={node} first={node.id === firstId} />
    );
}

function ContainerNode({
    node,
    firstId,
    stacked,
}: {
    node: StoreContainer;
    firstId: string | null;
    stacked: boolean;
}) {
    const layout = containerLayout(node, stacked);
    const advanced = advancedLayout(node.advanced);
    const bg = firstValue(node.style.bg);
    const image = node.bgImage;

    const children = node.children.map((child) => (
        <TreeNode
            key={child.id}
            node={child}
            firstId={firstId}
            stacked={layout.stacksOnMobile}
        />
    ));

    return (
        <div
            data-node-id={node.id}
            className={cn(
                'pb-container',
                layout.boxed ? 'pb-boxed' : 'pb-flex',
                advanced.className,
                typeof bg === 'string' && Object.hasOwn(BACKGROUNDS, bg)
                    ? BACKGROUNDS[bg as keyof typeof BACKGROUNDS]
                    : null,
                image && 'relative isolate overflow-hidden',
            )}
            style={inlineStyle({ ...layout.vars, ...advanced.vars })}
        >
            {image && (
                <>
                    <ResponsiveImage
                        image={{ ...image, alt: '' }}
                        sizes="100vw"
                        priority={firstId !== null && contains(node, firstId)}
                        className="absolute inset-0 -z-10 size-full object-cover"
                    />
                    {/* Escurece a imagem com o fundo do modo escuro do tema. */}
                    <div
                        aria-hidden="true"
                        className="pb-overlay dark absolute inset-0 -z-10 bg-background"
                    />
                </>
            )}
            {layout.boxed ? (
                <div className="pb-inner pb-flex">{children}</div>
            ) : (
                children
            )}
        </div>
    );
}

/**
 * Os elementos básicos (título, botão…) ficam do tamanho do conteúdo numa
 * linha; os "Da loja" ocupam a largura toda, porque o `@container` deles não
 * tem largura própria.
 */
const CONTENT_SIZED = new Set([
    'heading',
    'paragraph',
    'image',
    'button',
    'icon',
    'divider',
    'video',
    'icon_list',
    'quote',
]);

function ElementNode({ block, first }: { block: StoreBlock; first: boolean }) {
    const Component = BLOCK_COMPONENTS[block.type];

    if (!Component) {
        return null;
    }

    const advanced = advancedLayout(block.advanced);

    return (
        <div
            data-node-id={block.id}
            data-block-id={block.id}
            className={
                cn(
                    advanced.className,
                    !CONTENT_SIZED.has(block.type) && 'w-full',
                ) || undefined
            }
            style={inlineStyle(advanced.vars)}
        >
            <Component
                block={block}
                first={first}
                nested={block.shell === false}
            />
        </div>
    );
}

const DEVICES: StoreDevice[] = ['desktop', 'tablet', 'mobile'];

const SUFFIX: Record<StoreDevice, string> = {
    desktop: 'd',
    tablet: 't',
    mobile: 'm',
};

const DIRECTIONS = { row: 'row', column: 'column' };

const JUSTIFY = {
    start: 'flex-start',
    center: 'center',
    end: 'flex-end',
    between: 'space-between',
};

const ALIGN = {
    stretch: 'stretch',
    start: 'flex-start',
    center: 'center',
    end: 'flex-end',
};

const TEXT_ALIGN = { start: 'start', center: 'center', end: 'end' };

const WIDTHS = { contained: 'contained', full: 'full' };

type Vars = Record<string, string>;

function containerLayout(node: StoreContainer, stacked: boolean) {
    const style = node.style;
    const vars: Vars = {};

    // Uma linha passa a coluna no telemóvel, a não ser que o dono defina o
    // telemóvel: o -m só sai quando é explícito (a folha usa `column`).
    const ownDirection = pick(style.direction, DIRECTIONS);
    const direction = {
        ...resolveDevices(ownDirection),
        mobile: ownDirection.mobile,
    };
    put(vars, 'direction', direction);

    put(vars, 'wrap', resolveDevices(map(style.wrap, wrapValue)));
    put(vars, 'gap', resolveDevices(units(style.gap, 'px')));
    put(vars, 'justify', resolveDevices(pick(style.justify, JUSTIFY)));
    put(vars, 'align', resolveDevices(pick(style.align, ALIGN)));

    // Numa linha que empilhou no telemóvel, a largura dos filhos (feita para
    // a linha) não desce ao telemóvel: cada filho ocupa a largura toda.
    const ownWidth = units(style.width, '%');
    const width = resolveDevices(ownWidth);
    put(vars, 'width', stacked ? { ...width, mobile: ownWidth.mobile } : width);

    put(vars, 'min-height', resolveDevices(units(style.min_height, 'px')));
    put(vars, 'padding-y', resolveDevices(units(style.padding_y, 'px')));
    put(vars, 'padding-x', resolveDevices(units(style.padding_x, 'px')));
    put(vars, 'radius', resolveDevices(units(style.radius, 'px')));
    put(vars, 'text-align', resolveDevices(pick(style.text_align, TEXT_ALIGN)));

    if (node.bgImage) {
        put(vars, 'overlay', resolveDevices(units(style.overlay, '%')));
    }

    // Contido: o max-w-6xl e o px-4 de hoje vão para um interior, para o
    // fundo continuar de ponta a ponta.
    const widths = resolveDevices(pick(style.max_width, WIDTHS));
    const boxed = DEVICES.some((device) => widths[device] === 'contained');

    if (boxed) {
        put(
            vars,
            'max-width',
            map(widths, (value) => (value === 'contained' ? '72rem' : 'none')),
        );
        put(
            vars,
            'gutter',
            map(widths, (value) => (value === 'contained' ? '1rem' : '0px')),
        );
    }

    const stacksOnMobile =
        direction.tablet === 'row' &&
        (direction.mobile ?? 'column') === 'column';

    return { vars, boxed, stacksOnMobile };
}

/** O separador Avançado: margens, preenchimento e esconder por dispositivo. */
function advancedLayout(advanced: Record<string, StoreDeviceValues> = {}) {
    const vars: Vars = {};

    put(vars, 'margin-top', resolveDevices(units(advanced.margin_top, 'px')));
    put(
        vars,
        'margin-bottom',
        resolveDevices(units(advanced.margin_bottom, 'px')),
    );
    put(vars, 'adv-padding-y', resolveDevices(units(advanced.padding_y, 'px')));
    put(vars, 'adv-padding-x', resolveDevices(units(advanced.padding_x, 'px')));

    const hide = resolveDevices(
        map(advanced.hide, (value) =>
            typeof value === 'boolean' ? value : undefined,
        ),
    );

    return {
        vars,
        className: cn(
            Object.keys(vars).length > 0 && 'pb-advanced',
            hide.desktop && 'pb-hide-d',
            hide.tablet && 'pb-hide-t',
            hide.mobile && 'pb-hide-m',
        ),
    };
}

function map<T>(
    values: StoreDeviceValues | undefined,
    transform: (value: unknown) => T | undefined,
): StoreDeviceValues<T> {
    const result: StoreDeviceValues<T> = {};

    for (const device of DEVICES) {
        const value =
            values?.[device] === undefined
                ? undefined
                : transform(values[device]);

        if (value !== undefined) {
            result[device] = value;
        }
    }

    return result;
}

/** Um inteiro com a unidade; o resto não passa. */
function units(values: StoreDeviceValues | undefined, unit: string) {
    return map(values, (value) =>
        typeof value === 'number' && Number.isFinite(value)
            ? `${value}${unit}`
            : undefined,
    );
}

/** Uma opção do enum, já como o valor CSS. */
function pick(
    values: StoreDeviceValues | undefined,
    options: Record<string, string>,
) {
    return map(values, (value) =>
        typeof value === 'string' && Object.hasOwn(options, value)
            ? options[value]
            : undefined,
    );
}

function wrapValue(value: unknown): string | undefined {
    return typeof value === 'boolean' ? (value ? 'wrap' : 'nowrap') : undefined;
}

function put(vars: Vars, name: string, values: StoreDeviceValues<string>) {
    for (const device of DEVICES) {
        const value = values[device];

        if (value !== undefined) {
            vars[`--pb-${name}-${SUFFIX[device]}`] = value;
        }
    }
}

function inlineStyle(vars: Vars): CSSProperties | undefined {
    return Object.keys(vars).length > 0 ? (vars as CSSProperties) : undefined;
}

/** Um valor que não é por dispositivo (o fundo): o do maior tamanho. */
function firstValue(values: StoreDeviceValues | undefined): unknown {
    return values?.desktop ?? values?.tablet ?? values?.mobile;
}

function firstElementId(nodes: StoreNode[]): string | null {
    for (const node of nodes) {
        if (!isContainer(node)) {
            return node.id;
        }

        const id = firstElementId(node.children);

        if (id !== null) {
            return id;
        }
    }

    return null;
}

function contains(node: StoreContainer, id: string): boolean {
    return node.children.some(
        (child) =>
            child.id === id || (isContainer(child) && contains(child, id)),
    );
}
