<script setup lang="ts">
import * as AccessSettingsController from '@/actions/App/Http/Controllers/Admin/AccessSettingsController';
import AdminDashboardController from '@/actions/App/Http/Controllers/Admin/AdminDashboardController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AdminLayout from '@/layouts/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

interface Props {
    settings: {
        registration_enabled: boolean;
    };
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: AdminDashboardController().url },
    { title: 'Access Settings', href: AccessSettingsController.index().url },
];

const form = useForm({
    registration_enabled: props.settings.registration_enabled,
});

function submit() {
    form.put(AccessSettingsController.update().url, {
        onSuccess: () => toast.success('Access settings updated.'),
    });
}
</script>

<template>
    <Head title="Admin - Access Settings" />

    <AdminLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <h1 class="text-2xl font-bold tracking-tight">Access Settings</h1>

            <form @submit.prevent="submit" class="max-w-2xl space-y-6">
                <div class="rounded-xl border bg-card p-6 shadow-sm">
                    <h2 class="text-lg font-semibold">Registration</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Control whether new users can register on the platform.
                    </p>

                    <div class="mt-6 flex items-center justify-between">
                        <div class="flex flex-col gap-1">
                            <Label for="registration" class="text-sm font-medium">
                                Allow new registrations
                            </Label>
                            <p class="text-sm text-muted-foreground">
                                When disabled, the registration page will not be accessible.
                            </p>
                        </div>
                        <div class="flex items-center gap-3">
                            <Badge :variant="form.registration_enabled ? 'default' : 'secondary'">
                                {{ form.registration_enabled ? 'Enabled' : 'Disabled' }}
                            </Badge>
                            <Switch
                                id="registration"
                                v-model="form.registration_enabled"
                            />
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing || !form.isDirty">
                        Save Changes
                    </Button>
                    <span
                        v-if="form.recentlySuccessful"
                        class="text-sm text-green-600 dark:text-green-400"
                    >
                        Saved.
                    </span>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
