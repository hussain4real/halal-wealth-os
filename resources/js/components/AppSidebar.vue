<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    BookOpen,
    Boxes,
    BriefcaseBusiness,
    ChartPie,
    FileDown,
    FolderInput,
    LayoutGrid,
    ListChecks,
    MessagesSquare,
    SlidersHorizontal,
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

const groups = [
    {
        label: 'Workspace',
        items: [
            { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
            { title: 'Accounts', href: '/accounts', icon: Wallet },
            { title: 'Transactions', href: '/transactions', icon: ReceiptText },
            { title: 'Monthly plan', href: '/budgets', icon: CalendarDays },
            { title: 'Goals & protection', href: '/goals', icon: Target },
            { title: 'Portfolio', href: '/portfolio', icon: BriefcaseBusiness },
        ],
    },
    {
        label: 'Investment research',
        items: [
            {
                title: 'Market data',
                href: '/market-data',
                icon: ChartNoAxesCombined,
            },
            { title: 'Asset catalog', href: '/assets', icon: Boxes },
            { title: 'Opportunities', href: '/opportunities', icon: BookOpen },
            {
                title: 'Purification records',
                href: '/purification',
                icon: ClipboardCheck,
            },
        ],
    },
    {
        label: 'Ledger tools',
        items: [
            { title: 'Statement imports', href: '/imports', icon: FolderInput },
            { title: 'Currency exchange', href: '/fx', icon: ArrowLeftRight },
            { title: 'Balance reports', href: '/reports', icon: ChartPie },
            {
                title: 'Reconciliation',
                href: '/reconciliations',
                icon: ListChecks,
            },
        ],
    },
    {
        label: 'Workspace settings',
        items: [
            { title: 'Reminders', href: '/notifications', icon: Bell },
            {
                title: 'Read-only copilot',
                href: '/copilot',
                icon: MessagesSquare,
            },
            { title: 'Drive export', href: '/drive', icon: FileDown },
            {
                title: 'Optional feature controls',
                href: '/operations',
                icon: SlidersHorizontal,
            },
            { title: 'Profile', href: profile(), icon: UserRound },
            { title: 'Security', href: security(), icon: Shield },
        ],
    },
] satisfies { label: string; items: NavItem[] }[];

const page = usePage();
</script>

<template>
    <Sidebar collapsible="icon" variant="sidebar">
        <SidebarHeader class="py-4">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="gap-3 [&>svg]:size-8"
                    >
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="group-data-[collapsible=icon]:overflow-y-auto">
            <nav aria-label="Primary" class="space-y-3 py-3">
                <NavMain
                    v-for="(group, index) in groups"
                    :key="group.label"
                    :label="group.label"
                    :items="group.items"
                    :collapsible="index > 0"
                />
            </nav>
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
