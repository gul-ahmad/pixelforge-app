<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    ChartColumn,
    FolderGit2,
    History,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { report as adminReport } from '@/routes/admin';
import { create as generate, index as history } from '@/routes/generations';
import type { NavItem } from '@/types';

const page = usePage();

const userNavItems: NavItem[] = [
    {
        title: 'Generate',
        href: generate(),
        icon: Sparkles,
    },
    {
        title: 'History',
        href: history(),
        icon: History,
    },
];

// Operators also get the spend report (the server checks this too; this only hides the link).
const mainNavItems = computed<NavItem[]>(() =>
    page.props.auth.user?.is_admin
        ? [
              ...userNavItems,
              { title: 'Admin report', href: adminReport(), icon: ChartColumn },
          ]
        : userNavItems,
);

const footerNavItems: NavItem[] = [
    {
        title: 'Source code',
        href: 'https://github.com/gul-ahmad/pixelforge-app',
        icon: FolderGit2,
    },
    {
        title: 'Pixelforge API docs',
        href: 'https://pixelforge.promptedit.dev/docs',
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
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
