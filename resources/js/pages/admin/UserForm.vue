<script setup lang="ts">
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import * as AdminUserController from '@/actions/App/Http/Controllers/Admin/AdminUserController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

interface UserData {
    id: number;
    name: string;
    email: string;
    email_verified: boolean;
    blocked: boolean;
}

interface Props {
    mode: 'create' | 'edit';
    user?: UserData;
}

const props = defineProps<Props>();

const isEdit = props.mode === 'edit';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: 'Users', href: AdminUserController.index().url },
    { title: isEdit ? 'Edit User' : 'Create User', href: '#' },
];

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    password: '',
    email_verified: props.user?.email_verified ?? false,
});

function submit() {
    if (isEdit && props.user) {
        form.put(AdminUserController.update(props.user.id).url, {
            onSuccess: () => toast.success('User updated.'),
        });
    } else {
        form.post(AdminUserController.store().url, {
            onSuccess: () => toast.success('User created.'),
        });
    }
}
</script>

<template>
    <Head :title="isEdit ? 'Edit User' : 'Create User'" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <h1 class="text-2xl font-bold tracking-tight">
                {{ isEdit ? 'Edit User' : 'Create User' }}
            </h1>

            <form @submit.prevent="submit" class="max-w-2xl space-y-6">
                <div class="rounded-xl border bg-card p-6 shadow-sm space-y-4">
                    <div class="space-y-2">
                        <Label for="name">Name</Label>
                        <Input id="name" v-model="form.name" required />
                        <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="email">Email</Label>
                        <Input id="email" type="email" v-model="form.email" required />
                        <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
                    </div>

                    <div class="space-y-2">
                        <Label for="password">
                            {{ isEdit ? 'New Password (leave blank to keep current)' : 'Password' }}
                        </Label>
                        <Input id="password" type="password" v-model="form.password" :required="!isEdit" />
                        <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Checkbox
                            id="email_verified"
                            :checked="form.email_verified"
                            @update:checked="form.email_verified = $event as boolean"
                        />
                        <Label for="email_verified" class="cursor-pointer">Email verified</Label>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing">
                        {{ isEdit ? 'Update User' : 'Create User' }}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        as-child
                    >
                        <a :href="AdminUserController.index().url">Cancel</a>
                    </Button>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
