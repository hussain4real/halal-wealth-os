<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { LayoutGrid, UserRound, Shield, Wallet } from '@lucide/vue';
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
    { title: 'Accounts', href: '/accounts', icon: Wallet },
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
