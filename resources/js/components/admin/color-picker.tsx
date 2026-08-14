import { useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    MIN_CONTRAST,
    hexToHsv,
    hsvToHex,
    isPlainHex,
    worstThemeContrast,
} from '@/lib/color';
import { cn } from '@/lib/utils';

export type Preset = { name: string; hex: string };

type Props = {
    value: string;
    onChange: (hex: string) => void;
    /** Atalhos por cima do espectro: a paleta da marca, não o catálogo. */
    presets?: Preset[];
    /** Botão a devolver o valor a "sem cor". */
    onClear?: () => void;
    /** Prefixo dos `id` dos campos, para dois seletores caberem numa página. */
    idPrefix?: string;
};

/** Fallback quando o campo ainda está vazio: um tom neutro no meio do espectro. */
const FALLBACK = '#8FAE7F';

/** Quanto anda o cursor por cada tecla de seta. */
const STEP = { hue: 2, sv: 0.02 };

const clamp = (value: number) => Math.min(1, Math.max(0, value));

/**
 * Seletor de tom: área de saturação/valor, slider de matiz, hex à mão e
 * atalhos.
 *
 * Feito à mão e sem dependências, como a galeria de fotografias — uma
 * biblioteca de seletor de cor traz o seu próprio CSS, e o trabalho passava a
 * ser sobrepor-lhe os tokens em vez de os usar.
 *
 * Todos os tons vão em `style` e nunca numa classe: são valores da base de
 * dados, e uma classe de cor arbitrária é uma cor solta fora da paleta da marca
 * — o `DesignTokensTest` rejeita-as. Mesmo padrão do `ColorSwatch`.
 */
export function ColorPicker({
    value,
    onChange,
    presets = [],
    onClear,
    idPrefix = 'color',
}: Props) {
    const valid = isPlainHex(value);

    /*
     * O HSV é o estado; o hex é a saída. Derivá-lo do hex a cada render parece
     * mais simples e não é: um hex tem 8 bits por canal, e num tom quase
     * cinzento (o branco #FAF8F5 tem saturação 0,02) mexer a matiz dois graus
     * dá exatamente o mesmo hex. O `hexToHsv` devolvia o valor de partida, o
     * cursor não andava, e o slider da matiz ficava preso — sem nada na consola
     * a dizer porquê.
     *
     * `emitted` é o hex que este seletor produziu da última vez. Quando o
     * `value` que chega difere dele, veio de fora — de um atalho, do campo de
     * texto ou do formulário a repor — e aí sim o HSV volta a ser lido do hex.
     */
    const [state, setState] = useState(() => ({
        hsv: hexToHsv(valid ? value : FALLBACK),
        emitted: value,
    }));

    if (value !== state.emitted) {
        setState({
            hsv: isPlainHex(value) ? hexToHsv(value) : state.hsv,
            emitted: value,
        });
    }

    const hsv = state.hsv;

    /*
     * O hex escreve-se a mão, e a meio de "#8FAE7F" o campo passa por "#8F",
     * que não é cor nenhuma. O rascunho fica aqui até estar completo; só então
     * sobe. Sem isto, cada tecla escrita reposicionava os cursores num tom
     * aleatório.
     */
    const [draft, setDraft] = useState<string | null>(null);

    const commitHex = (next: string) => {
        const hex = next.startsWith('#') ? next : `#${next}`;

        setDraft(next);

        if (isPlainHex(hex)) {
            onChange(hex.toUpperCase());
        }
    };

    const setHsv = (changes: Partial<typeof hsv>) => {
        const next = { ...hsv, ...changes };
        const hex = hsvToHex(next);

        setDraft(null);
        setState({ hsv: next, emitted: hex });
        onChange(hex);
    };

    /** Um atalho ou o botão "sem cor": o HSV volta a sair do hex que entra. */
    const emit = (hex: string) => {
        setDraft(null);
        onChange(hex);
    };

    const contrast = valid ? worstThemeContrast(value) : null;

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-start gap-4">
                <SaturationArea
                    hsv={hsv}
                    onChange={(s, v) => setHsv({ s, v })}
                />

                <div className="flex min-w-40 flex-1 flex-col gap-3">
                    <HueSlider hue={hsv.h} onChange={(h) => setHsv({ h })} />

                    <div className="flex items-end gap-3">
                        <span
                            aria-hidden
                            className={cn(
                                'size-10 shrink-0 rounded-lg border border-border',
                                !valid && 'bg-muted',
                            )}
                            style={
                                valid ? { backgroundColor: value } : undefined
                            }
                        />
                        <div className="grid flex-1 gap-2">
                            <Label htmlFor={`${idPrefix}-hex`}>Hex</Label>
                            <Input
                                id={`${idPrefix}-hex`}
                                value={draft ?? value}
                                onChange={(event) =>
                                    commitHex(event.target.value)
                                }
                                onBlur={() => setDraft(null)}
                                placeholder="#8FAE7F"
                                maxLength={7}
                                className="font-mono uppercase"
                            />
                        </div>
                    </div>
                </div>
            </div>

            {(presets.length > 0 || onClear) && (
                <div className="flex flex-wrap items-center gap-2">
                    {presets.map((preset) => (
                        <button
                            key={preset.hex}
                            type="button"
                            onClick={() => emit(preset.hex)}
                            title={preset.name}
                            aria-label={preset.name}
                            aria-pressed={
                                value.toUpperCase() === preset.hex.toUpperCase()
                            }
                            className={cn(
                                'size-7 rounded-lg border transition-colors',
                                value.toUpperCase() === preset.hex.toUpperCase()
                                    ? 'border-ring ring-2 ring-ring/40'
                                    : 'border-border hover:border-ring',
                            )}
                            style={{ backgroundColor: preset.hex }}
                        />
                    ))}

                    {onClear && (
                        <button
                            type="button"
                            onClick={() => {
                                setDraft(null);
                                onClear();
                            }}
                            className="rounded-lg border border-border px-2.5 py-1 text-xs text-muted-foreground transition-colors hover:border-ring"
                        >
                            Sem cor
                        </button>
                    )}
                </div>
            )}

            {/*
             * Avisa, não bloqueia. Um branco de filamento é uma cor legítima e
             * tem sempre contraste péssimo contra o bege da loja — travar a
             * gravação tirava do catálogo cores que existem mesmo. O que este
             * aviso evita é a escolha feita sem se dar conta, a olhar só para
             * um dos temas.
             */}
            {contrast !== null && contrast < MIN_CONTRAST && (
                <p className="text-xs text-warning">
                    Contraste de {contrast.toFixed(1)}:1 no pior dos dois temas
                    — este tom quase não se vê contra o fundo. Serve como cor de
                    filamento; como marca de categoria, passa despercebido.
                </p>
            )}
        </div>
    );
}

/**
 * A área quadrada: saturação na horizontal, valor na vertical, sobre a matiz
 * escolhida no slider.
 */
function SaturationArea({
    hsv,
    onChange,
}: {
    hsv: { h: number; s: number; v: number };
    onChange: (s: number, v: number) => void;
}) {
    const area = useRef<HTMLDivElement>(null);

    /*
     * `setPointerCapture` e não listeners no `window`: o arrasto continua a
     * chegar aqui mesmo quando o ponteiro sai do quadrado, e o browser limpa
     * tudo sozinho ao largar. Mesmo mecanismo do arrasto da galeria.
     */
    const track = (event: React.PointerEvent<HTMLDivElement>) => {
        const rect = area.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        onChange(
            clamp((event.clientX - rect.left) / rect.width),
            clamp(1 - (event.clientY - rect.top) / rect.height),
        );
    };

    const onKeyDown = (event: React.KeyboardEvent) => {
        const moves: Record<string, [number, number]> = {
            ArrowLeft: [-STEP.sv, 0],
            ArrowRight: [STEP.sv, 0],
            ArrowUp: [0, STEP.sv],
            ArrowDown: [0, -STEP.sv],
        };

        const move = moves[event.key];

        if (move === undefined) {
            return;
        }

        event.preventDefault();
        onChange(clamp(hsv.s + move[0]), clamp(hsv.v + move[1]));
    };

    return (
        <div
            ref={area}
            role="application"
            aria-label="Saturação e brilho"
            tabIndex={0}
            onKeyDown={onKeyDown}
            onPointerDown={(event) => {
                event.currentTarget.setPointerCapture(event.pointerId);
                track(event);
            }}
            onPointerMove={(event) => {
                if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                    track(event);
                }
            }}
            className="relative size-40 shrink-0 cursor-crosshair touch-none rounded-lg border border-border focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            style={{
                backgroundImage:
                    'linear-gradient(to top, #000, transparent), linear-gradient(to right, #FFF, transparent)',
                backgroundColor: `hsl(${hsv.h} 100% 50%)`,
            }}
        >
            <Thumb
                style={{
                    left: `${hsv.s * 100}%`,
                    top: `${(1 - hsv.v) * 100}%`,
                }}
            />
        </div>
    );
}

function HueSlider({
    hue,
    onChange,
}: {
    hue: number;
    onChange: (hue: number) => void;
}) {
    const bar = useRef<HTMLDivElement>(null);

    const track = (event: React.PointerEvent<HTMLDivElement>) => {
        const rect = bar.current?.getBoundingClientRect();

        if (!rect) {
            return;
        }

        onChange(clamp((event.clientX - rect.left) / rect.width) * 360);
    };

    return (
        <div
            ref={bar}
            role="slider"
            aria-label="Matiz"
            aria-valuemin={0}
            aria-valuemax={360}
            aria-valuenow={Math.round(hue)}
            tabIndex={0}
            onKeyDown={(event) => {
                const delta =
                    event.key === 'ArrowLeft' || event.key === 'ArrowDown'
                        ? -STEP.hue
                        : event.key === 'ArrowRight' || event.key === 'ArrowUp'
                          ? STEP.hue
                          : 0;

                if (delta === 0) {
                    return;
                }

                event.preventDefault();
                onChange((hue + delta + 360) % 360);
            }}
            onPointerDown={(event) => {
                event.currentTarget.setPointerCapture(event.pointerId);
                track(event);
            }}
            onPointerMove={(event) => {
                if (event.currentTarget.hasPointerCapture(event.pointerId)) {
                    track(event);
                }
            }}
            className="relative h-4 cursor-pointer touch-none rounded-full border border-border focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            style={{
                backgroundImage:
                    'linear-gradient(to right, #F00, #FF0, #0F0, #0FF, #00F, #F0F, #F00)',
            }}
        >
            <Thumb style={{ left: `${(hue / 360) * 100}%`, top: '50%' }} />
        </div>
    );
}

/**
 * O cursor. Anel branco por dentro e escuro por fora, para se ver tanto sobre
 * um tom claro como sobre um escuro — a única coisa no seletor que tem mesmo de
 * ser visível sobre qualquer cor.
 */
function Thumb({ style }: { style: React.CSSProperties }) {
    return (
        <span
            aria-hidden
            className="pointer-events-none absolute size-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-2"
            style={{
                ...style,
                borderColor: '#FFF',
                boxShadow: '0 0 0 1px rgb(0 0 0 / 0.45)',
            }}
        />
    );
}
