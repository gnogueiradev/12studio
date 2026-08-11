import Link from '@tiptap/extension-link';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import type { Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Heading2,
    Heading3,
    Italic,
    Link2,
    Link2Off,
    List,
    ListOrdered,
    Quote,
    RemoveFormatting,
    Strikethrough,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Separator } from '@/components/ui/separator';
import { Toggle } from '@/components/ui/toggle';

type Props = {
    id?: string;
    /** HTML. O servidor sanitiza-o antes de gravar (perfil `product`). */
    value: string;
    onChange: (html: string) => void;
    placeholder?: string;
};

type ToolProps = {
    icon: LucideIcon;
    label: string;
    active?: boolean;
    onClick: () => void;
};

function Tool({ icon: Icon, label, active = false, onClick }: ToolProps) {
    return (
        <Toggle
            size="sm"
            pressed={active}
            onPressedChange={onClick}
            // Sem isto, carregar num botão tira o foco ao editor e o
            // ProseMirror perde a seleção antes de o comando correr — o
            // clássico "seleciono texto, carrego em negrito, não acontece
            // nada".
            onMouseDown={(event) => event.preventDefault()}
            aria-label={label}
            title={label}
        >
            <Icon className="size-4" />
        </Toggle>
    );
}

/**
 * A barra só oferece o que a lista branca do servidor deixa passar
 * (config/purifier.php). Acrescentar aqui um botão sem o acrescentar lá dá
 * formatação que desaparece ao gravar, sem aviso nenhum.
 */
function Toolbar({ editor }: { editor: Editor }) {
    // O useEditor não volta a renderizar a cada transação (é o default desde
    // a v3), por isso `editor.isActive(...)` lido diretamente ficaria congelado
    // e os botões nunca acendiam. O useEditorState subscreve só o que
    // interessa e volta a renderizar quando algum destes valores muda.
    const active = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current.isActive('bold'),
            italic: current.isActive('italic'),
            strike: current.isActive('strike'),
            h2: current.isActive('heading', { level: 2 }),
            h3: current.isActive('heading', { level: 3 }),
            bulletList: current.isActive('bulletList'),
            orderedList: current.isActive('orderedList'),
            blockquote: current.isActive('blockquote'),
            link: current.isActive('link'),
        }),
    });

    const toggleLink = () => {
        if (active.link) {
            editor.chain().focus().unsetLink().run();

            return;
        }

        const url = window.prompt('Endereço do link', 'https://');

        if (url) {
            editor.chain().focus().setLink({ href: url }).run();
        }
    };

    return (
        <div className="flex flex-wrap items-center gap-0.5 border-b border-input p-1">
            <Tool
                icon={Bold}
                label="Negrito"
                active={active.bold}
                onClick={() => editor.chain().focus().toggleBold().run()}
            />
            <Tool
                icon={Italic}
                label="Itálico"
                active={active.italic}
                onClick={() => editor.chain().focus().toggleItalic().run()}
            />
            <Tool
                icon={Strikethrough}
                label="Rasurado"
                active={active.strike}
                onClick={() => editor.chain().focus().toggleStrike().run()}
            />

            <Separator orientation="vertical" className="mx-1 h-6" />

            <Tool
                icon={Heading2}
                label="Título"
                active={active.h2}
                onClick={() =>
                    editor.chain().focus().toggleHeading({ level: 2 }).run()
                }
            />
            <Tool
                icon={Heading3}
                label="Subtítulo"
                active={active.h3}
                onClick={() =>
                    editor.chain().focus().toggleHeading({ level: 3 }).run()
                }
            />

            <Separator orientation="vertical" className="mx-1 h-6" />

            <Tool
                icon={List}
                label="Lista com pontos"
                active={active.bulletList}
                onClick={() => editor.chain().focus().toggleBulletList().run()}
            />
            <Tool
                icon={ListOrdered}
                label="Lista numerada"
                active={active.orderedList}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
            />
            <Tool
                icon={Quote}
                label="Citação"
                active={active.blockquote}
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
            />

            <Separator orientation="vertical" className="mx-1 h-6" />

            <Tool
                icon={active.link ? Link2Off : Link2}
                label={active.link ? 'Remover link' : 'Link'}
                active={active.link}
                onClick={toggleLink}
            />
            <Tool
                icon={RemoveFormatting}
                label="Limpar formatação"
                onClick={() =>
                    editor.chain().focus().unsetAllMarks().clearNodes().run()
                }
            />
        </div>
    );
}

export function RichTextEditor({
    id,
    value,
    onChange,
    placeholder = 'Materiais, medidas, cuidados…',
}: Props) {
    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                // H1 fica para o título da página do produto; a descrição
                // começa em H2 para a hierarquia do documento não partir.
                heading: { levels: [2, 3] },
                // O purificador remove-os na gravação — melhor não os
                // oferecer do que os deixar desaparecer em silêncio.
                codeBlock: false,
                code: false,
                horizontalRule: false,
                link: false,
            }),
            Link.configure({
                openOnClick: false,
                autolink: true,
                protocols: ['http', 'https', 'mailto'],
            }),
        ],
        content: value,
        // O Inertia não hidrata isto no servidor; sem a flag o React avisa
        // de incompatibilidade entre SSR e cliente.
        immediatelyRender: false,
        onUpdate: ({ editor: current }) => onChange(current.getHTML()),
        editorProps: {
            attributes: {
                class: 'rich-text min-h-40 px-3 py-2 focus:outline-none',
                'data-placeholder': placeholder,
                ...(id ? { id } : {}),
            },
        },
    });

    if (!editor) {
        return null;
    }

    return (
        <div className="rounded-md border border-input bg-transparent shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50">
            <Toolbar editor={editor} />
            <EditorContent editor={editor} />
        </div>
    );
}
