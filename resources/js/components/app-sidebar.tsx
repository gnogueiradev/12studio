import { Link } from '@inertiajs/react';
import { LayoutGrid, Package, Store, Tags } from 'lucide-react';
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

// Navegacao do backoffice. Cresce com as fases: materiais & cores e
// variantes (Fase 2), encomendas e producao (Fases 3-4), KPIs (Fase 5).
const mainNavItems: NavItem[] = [
    {
        title: 'Backoffice',
        href: '/admin',
        icon: LayoutGrid,
    },
    {
        title: 'Categorias',
        href: '/admin/categorias',
        icon: Tags,
    },
    {
        title: 'Produtos',
        href: '/admin/produtos',
        icon: Package,
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
