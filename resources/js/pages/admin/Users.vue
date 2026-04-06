<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import * as ImpersonateController from '@/actions/App/Http/Controllers/Admin/ImpersonateController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useInitials } from '@/composables/useInitials';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Eye } from 'lucide-vue-next';

interface UserRow {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    organization_id: string | null;
    organization?: { id: string; name: string } | null;
    memberships_count: number;
    email_verified_at: string | null;
    created_at: string;
}

interface Pagination {
    data: UserRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    next_page_url: string | null;
    prev_page_url: string | null;
}

interface Props {
    users: Pagination;
}

defineProps<Props>();

const page = usePage();
const { getInitials } = useInitials();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: 'Users', href: AdminUserController.index().url },
];

function impersonate(user: UserRow) {
    router.post(ImpersonateController.start(user.id).url);
}
</script>

<template>
    <Head title="Admin - Users" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold tracking-tight">Users</h1>
                <Badge variant="secondary">{{ users.total }} total</Badge>
            </div>

            <div class="rounded-xl border bg-card shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th class="px-4 py-3 text-left font-medium">User</th>
                                <th class="px-4 py-3 text-left font-medium">Email</th>
                                <th class="px-4 py-3 text-left font-medium">Current Org</th>
                                <th class="px-4 py-3 text-left font-medium">Orgs</th>
                                <th class="px-4 py-3 text-left font-medium">Verified</th>
                                <th class="px-4 py-3 text-left font-medium">Joined</th>
                                <th class="px-4 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="user in users.data"
                                :key="user.id"
                                class="border-b last:border-0 hover:bg-muted/30"
                            >
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <Avatar class="h-8 w-8">
                                            <AvatarFallback class="text-xs">
                                                {{ getInitials(user.name) }}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span class="font-medium">{{ user.name }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ user.email }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge v-if="user.organization" variant="outline">
                                        {{ user.organization.name }}
                                    </Badge>
                                    <span v-else class="text-muted-foreground">Personal</span>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ user.memberships_count }}
                                </td>
                                <td class="px-4 py-3">
                                    <Badge
                                        :variant="user.email_verified_at ? 'default' : 'destructive'"
                                    >
                                        {{ user.email_verified_at ? 'Yes' : 'No' }}
                                    </Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ new Date(user.created_at).toLocaleDateString() }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <Button
                                        v-if="user.id !== page.props.auth.user.id"
                                        size="sm"
                                        variant="ghost"
                                        class="h-8 gap-1.5 text-xs"
                                        @click="impersonate(user)"
                                    >
                                        <Eye class="h-3.5 w-3.5" />
                                        Impersonate
                                    </Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="users.last_page > 1"
                    class="flex items-center justify-between border-t px-4 py-3"
                >
                    <span class="text-sm text-muted-foreground">
                        Page {{ users.current_page }} of {{ users.last_page }}
                    </span>
                    <div class="flex gap-2">
                        <Link
                            v-if="users.prev_page_url"
                            :href="users.prev_page_url"
                            class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="users.next_page_url"
                            :href="users.next_page_url"
                            class="rounded-md border px-3 py-1.5 text-sm hover:bg-muted"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
