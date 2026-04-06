<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Users, Building2 } from 'lucide-vue-next';

interface Props {
    stats: {
        users: number;
        organizations: number;
    };
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: 'Dashboard', href: AdminDashboardController().url },
];
</script>

<template>
    <Head title="Admin Dashboard" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <h1 class="text-2xl font-bold tracking-tight">Admin Dashboard</h1>

            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <Users class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Total Users</p>
                            <p class="text-2xl font-bold">{{ stats.users }}</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border bg-card p-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                            <Building2 class="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p class="text-sm text-muted-foreground">Organizations</p>
                            <p class="text-2xl font-bold">{{ stats.organizations }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
