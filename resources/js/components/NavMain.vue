<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

const props = defineProps<{
    items: NavItem[];
    label: string;
    collapsible: boolean;
}>();
const { currentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const { isMobile, state, setOpenMobile } = useSidebar();
const menuId = useId();
const open = ref(false);
watch(
    currentUrl,
    () => {
        if (props.items.some((item) => isCurrentOrParentUrl(item.href)))
            open.value = true;
    },
    { immediate: true },
);
const iconOnly = computed(() => !isMobile.value && state.value === 'collapsed');
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <button
            v-if="collapsible && !iconOnly"
            class="flex min-h-10 w-full items-center justify-between rounded-lg px-3 text-sm font-medium text-sidebar-foreground/80 hover:bg-sidebar-accent"
            :aria-expanded="open"
            :aria-controls="menuId"
            @click="open = !open"
        >
            {{ label
            }}<ChevronDown
                aria-hidden="true"
                class="size-4"
                :class="{ 'rotate-180': open }"
            />
        </button>
        <SidebarGroupLabel
            v-else-if="!iconOnly"
            class="px-3 text-[.7rem] font-semibold tracking-widest uppercase"
            >{{ label }}</SidebarGroupLabel
        >
        <SidebarMenu v-show="!collapsible || open || iconOnly" :id="menuId">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                    class="h-10 gap-3 px-3"
                >
                    <Link
                        :href="item.href"
                        :aria-current="
                            isCurrentOrParentUrl(item.href)
                                ? 'location'
                                : undefined
                        "
                        @click="setOpenMobile(false)"
                    >
                        <component :is="item.icon" aria-hidden="true" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
