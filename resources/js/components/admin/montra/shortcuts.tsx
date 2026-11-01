import { Keyboard } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

/** "⌘" no Mac, "Ctrl" no resto — só para mostrar; o editor aceita os dois. */
export const MOD =
    typeof navigator !== 'undefined' &&
    /Mac|iPhone|iPad/.test(navigator.userAgent)
        ? '⌘'
        : 'Ctrl';

const SHORTCUTS: [string, string][] = [
    [`${MOD} Z`, 'Desfazer'],
    [`${MOD} ⇧ Z`, 'Refazer'],
    [`${MOD} D`, 'Duplicar o selecionado'],
    ['Delete', 'Apagar o selecionado'],
    ['Esc', 'Largar a seleção'],
];

/** Os atalhos do editor, num tooltip discreto. */
export function ShortcutsHint() {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    className="inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 text-xs text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Keyboard aria-hidden className="size-3.5" />
                    Atalhos
                </button>
            </TooltipTrigger>
            <TooltipContent side="top" align="start" className="p-3">
                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5">
                    {SHORTCUTS.map(([keys, label]) => (
                        <div key={keys} className="contents">
                            <dt>
                                <kbd className="font-sans font-medium">
                                    {keys}
                                </kbd>
                            </dt>
                            <dd>{label}</dd>
                        </div>
                    ))}
                </dl>
                <p className="mt-2 opacity-80">Fora dos campos de texto.</p>
            </TooltipContent>
        </Tooltip>
    );
}
