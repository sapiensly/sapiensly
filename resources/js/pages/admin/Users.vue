<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import * as ImpersonateController from '@/actions/App/Http/Controllers/Admin/ImpersonateController';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useInitials } from '@/composables/useInitials';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Edit,
    Eye,
    Lock,
    LockOpen,
    MoreHorizontal,
    Plus,
    Trash2,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

interface UserRow {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    organization_id: string | null;
    organization?: { id: string; name: string } | null;
    memberships_count: number;
    email_verified_at: string | null;
    blocked_at: string | null;
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

// Confirmation dialog state
const dialogOpen = ref(false);
const dialogAction = ref<'block' | 'delete'>('block');
const dialogUser = ref<UserRow | null>(null);
const emailConfirmation = ref('');
const emailConfirmationError = ref('');
const processing = ref(false);

function impersonate(user: UserRow) {
    router.post(ImpersonateController.start(user.id).url);
}

function openConfirmDialog(action: 'block' | 'delete', user: UserRow) {
    dialogAction.value = action;
    dialogUser.value = user;
    emailConfirmation.value = '';
    emailConfirmationError.value = '';
    dialogOpen.value = true;
}

function confirmAction() {
    if (!dialogUser.value) return;

    if (emailConfirmation.value !== dialogUser.value.email) {
        emailConfirmationError.value = 'Email does not match.';
        return;
    }

    processing.value = true;
    emailConfirmationError.value = '';

    if (dialogAction.value === 'block') {
        router.post(AdminUserController.block(dialogUser.value.id).url, {
            email_confirmation: emailConfirmation.value,
        }, {
            onSuccess: () => {
                dialogOpen.value = false;
                const wasBlocked = dialogUser.value?.blocked_at;
                toast.success(wasBlocked ? 'User unblocked.' : 'User blocked.');
            },
            onError: (errors) => {
                emailConfirmationError.value = errors.email_confirmation ?? '';
            },
            onFinish: () => { processing.value = false; },
        });
    } else {
        router.delete(AdminUserController.destroy(dialogUser.value.id).url, {
            data: { email_confirmation: emailConfirmation.value },
            onSuccess: () => {
                dialogOpen.value = false;
                toast.success('User deleted.');
            },
            onError: (errors) => {
                emailConfirmationError.value = errors.email_confirmation ?? '';
            },
            onFinish: () => { processing.value = false; },
        });
    }
}

function backupUser(user: UserRow) {
    toast.info(`Backup for ${user.name} — coming soon.`);
}
</script>

<template>
    <Head title="Admin - Users" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold tracking-tight">Users</h1>
                <div class="flex items-center gap-3">
                    <Badge variant="secondary">{{ users.total }} total</Badge>
                    <Button as-child size="sm" class="gap-1.5">
                        <Link :href="AdminUserController.create().url">
                            <Plus class="h-4 w-4" />
                            Create User
                        </Link>
                    </Button>
                </div>
            </div>

            <div class="rounded-xl border bg-card shadow-sm">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b bg-muted/50">
                                <th class="px-4 py-3 text-left font-medium">User</th>
                                <th class="px-4 py-3 text-left font-medium">Email</th>
                                <th class="px-4 py-3 text-left font-medium">Current Org</th>
                                <th class="px-4 py-3 text-left font-medium">Status</th>
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
                                <td class="px-4 py-3">
                                    <Badge v-if="user.blocked_at" variant="destructive">Blocked</Badge>
                                    <Badge v-else-if="!user.email_verified_at" variant="secondary">Unverified</Badge>
                                    <Badge v-else variant="default">Active</Badge>
                                </td>
                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ new Date(user.created_at).toLocaleDateString() }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm" class="h-8 w-8 p-0">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link :href="AdminUserController.edit(user.id).url" class="gap-2">
                                                    <Edit class="h-4 w-4" />
                                                    Edit
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="user.id !== page.props.auth.user.id"
                                                class="gap-2"
                                                @click="impersonate(user)"
                                            >
                                                <Eye class="h-4 w-4" />
                                                Impersonate
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="gap-2"
                                                @click="backupUser(user)"
                                            >
                                                <Archive class="h-4 w-4" />
                                                Backup Data
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                v-if="user.id !== page.props.auth.user.id"
                                                class="gap-2"
                                                @click="openConfirmDialog('block', user)"
                                            >
                                                <component :is="user.blocked_at ? LockOpen : Lock" class="h-4 w-4" />
                                                {{ user.blocked_at ? 'Unblock' : 'Block' }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="user.id !== page.props.auth.user.id"
                                                class="gap-2 text-destructive focus:text-destructive"
                                                @click="openConfirmDialog('delete', user)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
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

        <!-- Confirmation Dialog -->
        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {{
                            dialogAction === 'delete' ? 'Delete User' :
                            dialogUser?.blocked_at ? 'Unblock User' : 'Block User'
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        <template v-if="dialogAction === 'delete'">
                            This will permanently delete <strong>{{ dialogUser?.name }}</strong> and all their data. This action cannot be undone.
                        </template>
                        <template v-else-if="dialogUser?.blocked_at">
                            This will restore access for <strong>{{ dialogUser?.name }}</strong>.
                        </template>
                        <template v-else>
                            This will block <strong>{{ dialogUser?.name }}</strong> from accessing the platform. They will be logged out immediately.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3 py-2">
                    <div class="rounded-md border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
                        <p class="text-sm text-amber-800 dark:text-amber-200">
                            To confirm, type the user's email:
                            <strong class="font-mono">{{ dialogUser?.email }}</strong>
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="email_confirmation">Email confirmation</Label>
                        <Input
                            id="email_confirmation"
                            v-model="emailConfirmation"
                            placeholder="Type email to confirm"
                            @keyup.enter="confirmAction"
                        />
                        <p v-if="emailConfirmationError" class="text-sm text-destructive">
                            {{ emailConfirmationError }}
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" @click="dialogOpen = false">
                        Cancel
                    </Button>
                    <Button
                        :variant="dialogAction === 'delete' ? 'destructive' : 'default'"
                        :disabled="processing || emailConfirmation !== dialogUser?.email"
                        @click="confirmAction"
                    >
                        {{
                            dialogAction === 'delete' ? 'Delete User' :
                            dialogUser?.blocked_at ? 'Unblock User' : 'Block User'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
