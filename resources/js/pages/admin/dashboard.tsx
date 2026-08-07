import { Head } from '@inertiajs/react';
import { dashboard } from '@/routes/admin';

export default function AdminDashboard() {
    return (
        <>
            <Head title="Backoffice" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Backoffice 12studio</h1>
                <p className="text-sm text-muted-foreground">
                    KPIs e alertas chegam na Fase 5 — por agora, gere o catálogo
                    a partir da barra lateral.
                </p>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Backoffice',
            href: dashboard(),
        },
    ],
};
