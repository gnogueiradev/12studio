import {
    Columns2,
    FileText,
    GalleryHorizontal,
    Grid3x3,
    HelpCircle,
    Image as ImageIcon,
    LayoutGrid,
    ListOrdered,
    Mail,
    Megaphone,
    MessageSquareQuote,
    PencilRuler,
    Rows3,
    ShieldCheck,
    Square,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BlockSchema, EditorBlock } from '@/types/page-builder';

/** Um ícone por tipo, para o catálogo se ler de relance. */
const ICONS: Record<string, LucideIcon> = {
    hero: Megaphone,
    product_grid: LayoutGrid,
    categories: Grid3x3,
    text: FileText,
    text_image: Columns2,
    banner: Rows3,
    gallery: GalleryHorizontal,
    steps: ListOrdered,
    faq: HelpCircle,
    testimonials: MessageSquareQuote,
    trust: ShieldCheck,
    contact: Mail,
    custom_order: PencilRuler,
    spacer: Square,
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    schema: BlockSchema[];
    blocks: EditorBlock[];
    onAdd: (schema: BlockSchema) => void;
};

/** "+ Adicionar bloco": os blocos que esta página pode ter. */
export function BlockCatalog({
    open,
    onOpenChange,
    schema,
    blocks,
    onAdd,
}: Props) {
    const used = new Set(blocks.map((block) => block.type));

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Adicionar bloco</DialogTitle>
                    <DialogDescription>
                        Entra a seguir ao bloco selecionado (ou no fim). Depois
                        podes arrastá-lo para onde quiseres.
                    </DialogDescription>
                </DialogHeader>
                <ul className="grid gap-2 sm:grid-cols-2">
                    {schema.map((block) => {
                        const Icon = ICONS[block.type] ?? ImageIcon;
                        const taken = block.unique && used.has(block.type);

                        return (
                            <li key={block.type}>
                                <button
                                    type="button"
                                    disabled={taken}
                                    onClick={() => {
                                        onAdd(block);
                                        onOpenChange(false);
                                    }}
                                    className="flex h-full w-full items-start gap-3 rounded-lg border border-border/60 p-3 text-left transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
                                        <Icon aria-hidden className="size-4" />
                                    </span>
                                    <span className="grid gap-0.5">
                                        <span className="text-sm font-medium">
                                            {block.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {taken
                                                ? 'Já está na página (só pode haver um).'
                                                : block.description}
                                        </span>
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </DialogContent>
        </Dialog>
    );
}
