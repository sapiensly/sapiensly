<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Mail } from '@/lib/admin/icons';
import { useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const open = defineModel<boolean>('open', { required: true });
const { t } = useI18n();

const form = useForm({
    email: '',
    name: '',
    role: 'member' as 'owner' | 'admin' | 'member',
});

function submit() {
    form.post('/admin2/users/invite', {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}

function close() {
    open.value = false;
    form.reset();
    form.clearErrors();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="rounded-sp-sm border-medium bg-navy sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ t('admin_v2.users.invite.title') }}</DialogTitle>
                <DialogDescription>
                    {{ t('admin_v2.users.invite.description') }}
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="space-y-1.5">
                    <Label for="invite-email">{{
                        t('admin_v2.users.invite.email')
                    }}</Label>
                    <Input
                        id="invite-email"
                        v-model="form.email"
                        type="email"
                        placeholder="name@example.com"
                        autocomplete="off"
                    />
                    <p
                        v-if="form.errors.email"
                        class="text-xs text-sp-danger"
                    >
                        {{ form.errors.email }}
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label for="invite-name">{{
                        t('admin_v2.users.invite.name')
                    }}</Label>
                    <Input
                        id="invite-name"
                        v-model="form.name"
                        :placeholder="t('admin_v2.users.invite.name_placeholder')"
                    />
                </div>

                <div class="space-y-1.5">
                    <Label for="invite-role">{{
                        t('admin_v2.users.invite.role')
                    }}</Label>
                    <Select v-model="form.role">
                        <SelectTrigger id="invite-role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="member">{{
                                t('admin_v2.users.role.member')
                            }}</SelectItem>
                            <SelectItem value="admin">{{
                                t('admin_v2.users.role.admin')
                            }}</SelectItem>
                            <SelectItem value="owner">{{
                                t('admin_v2.users.role.owner')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <p
                        v-if="form.errors.role"
                        class="text-xs text-sp-danger"
                    >
                        {{ form.errors.role }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="close">
                        {{ t('common.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.email"
                        class="gap-1.5 rounded-pill bg-accent-blue text-white shadow-btn-primary hover:bg-accent-blue-hover"
                    >
                        <Mail class="size-3.5" />
                        {{ t('admin_v2.users.invite.submit') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
