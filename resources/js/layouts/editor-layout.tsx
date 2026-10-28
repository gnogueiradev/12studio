import type { ReactNode } from 'react';

/**
 * O ecrã do editor de páginas: sem sidebar nem breadcrumbs, para a
 * pré-visualização ter o espaço todo. O Toaster e os tooltips vêm do app.tsx.
 */
export default function EditorLayout({ children }: { children: ReactNode }) {
    return (
        <div className="flex h-svh min-h-0 flex-col overflow-hidden bg-background text-foreground">
            {children}
        </div>
    );
}
