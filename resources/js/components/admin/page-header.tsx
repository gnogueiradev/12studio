import type { ReactNode } from 'react';

type Props = {
    title: string;
    description?: string;
    /** Ações à direita (normalmente o botão "Novo …"). */
    children?: ReactNode;
};

export function PageHeader({ title, description, children }: Props) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 className="text-xl font-semibold">{title}</h1>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {children && (
                <div className="flex items-center gap-2">{children}</div>
            )}
        </div>
    );
}
