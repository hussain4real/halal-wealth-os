<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    LayoutGrid,
    UserRound,
    Shield,
    Wallet,
    ReceiptText,
    ArrowLeftRight,
    ChartNoAxesCombined,
    ClipboardCheck,
    CalendarDays,
    Target,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as profile } from '@/routes/profile';
import { edit as security } from '@/routes/security';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Drive export', href: '/drive', icon: CalendarDays },
    { title: 'Reminders', href: '/notifications', icon: CalendarDays },
    { title: 'Monthly plan', href: '/budgets', icon: CalendarDays },
    { title: 'Goals & protection', href: '/goals', icon: Target },
    { title: 'Portfolio', href: '/portfolio', icon: ChartNoAxesCombined },
    { title: 'Market data', href: '/market-data', icon: ChartNoAxesCombined },
    { title: 'Asset catalog', href: '/assets', icon: LayoutGrid },
    { title: 'Opportunities', href: '/opportunities', icon: Target },
    {
        title: 'Purification records',
        href: '/purification',
        icon: ClipboardCheck,
    },
    { title: 'Accounts', href: '/accounts', icon: Wallet },
    { title: 'Transactions', href: '/transactions', icon: ReceiptText },
    { title: 'Statement imports', href: '/imports', icon: ClipboardCheck },
    { title: 'Currency exchange', href: '/fx', icon: ArrowLeftRight },
    { title: 'Balance reports', href: '/reports', icon: ChartNoAxesCombined },
    { title: 'Reconciliation', href: '/reconciliations', icon: ClipboardCheck },
    { title: 'Profile', href: profile(), icon: UserRound },
    { title: 'Security', href: security(), icon: Shield },
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const page = usePage();
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <nav aria-label="Primary"><NavMain :items="mainNavItems" /></nav>
            <a
                v-if="page.props.auth.canAdmin"
                href="/admin/owners"
                class="m-3 rounded px-2 py-1 text-sm underline focus-visible:outline-2"
                >Administration</a
            >
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
