<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BarChart3,
    BookOpen,
    Coins,
    FolderGit2,
    History,
    LayoutGrid,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { report as adminReport } from '@/routes/admin';
import { index as creditsIndex } from '@/routes/credits';
import {
    create as createGeneration,
    index as generationsIndex,
} from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();
const credits = computed(() => page.props.auth?.credits);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Generate',
            href: createGeneration(),
            icon: Sparkles,
        },
        {
            title: 'History',
            href: generationsIndex(),
            icon: History,
        },
        {
            title: 'Buy Credits',
            href: creditsIndex(),
            icon: Coins,
        },
    ];

    if (page.props.auth?.user?.is_admin) {
        items.push({
            title: 'Admin Report',
            href: adminReport(),
            icon: BarChart3,
        });
    }

    return items;
});

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
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
            <NavMain :items="mainNavItems" />

            <div
                v-if="credits"
                data-test="nav-credits-widget"
                class="mx-2 mt-4 rounded-lg border bg-muted/40 p-3 text-xs group-data-[collapsible=icon]:hidden"
            >
                <div class="flex items-center justify-between">
                    <span class="font-medium text-muted-foreground">
                        Credit Balance
                    </span>
                    <Badge
                        variant="secondary"
                        data-test="nav-balance-amount"
                        class="font-mono tabular-nums"
                    >
                        {{ credits.balance_formatted }}
                    </Badge>
                </div>
                <div
                    v-if="credits.reserved_nano > 0"
                    class="mt-1 flex items-center justify-between text-[11px] text-muted-foreground"
                >
                    <span>Available</span>
                    <span class="font-mono tabular-nums">{{
                        credits.available_formatted
                    }}</span>
                </div>
                <Button
                    as-child
                    size="sm"
                    variant="outline"
                    class="mt-2.5 w-full text-xs"
                    data-test="nav-buy-credits-button"
                >
                    <Link :href="creditsIndex()">
                        <Coins class="mr-1.5 size-3.5" />
                        Buy credits
                    </Link>
                </Button>
            </div>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
