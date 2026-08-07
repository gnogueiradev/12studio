import { Link } from '@inertiajs/react';
import {
    Factory,
    LayoutGrid,
    Package,
    ShoppingCart,
    Store,
    Tags,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

// Navegacao do backoffice. Cresce com as fases: materiais & cores (Fase 2),
// carrinho e checkout Stripe (Fase 3), KPIs (Fase 5).
const mainNavItems: NavItem[] = [
    {
        title: 'Backoffice',
        href: '/admin',
        icon: LayoutGrid,
    },
    {
        title: 'Encomendas',
        href: '/admin/encomendas',
        icon: ShoppingCart,
    },
    {
        title: 'Produção',
        href: '/admin/producao',
        icon: Factory,
    },
    {
        title: 'Clientes',
        href: '/admin/clientes',
        icon: Users,
    },
    {
        title: 'Produtos',
        href: '/admin/produtos',
        icon: Package,
    },
    {
        title: 'Categorias',
        href: '/admin/categorias',
        icon: Tags,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Ver loja',
        href: '/',
        icon: Store,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/admin" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
