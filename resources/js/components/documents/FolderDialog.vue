<script setup lang="ts">
import * as FolderController from '@/actions/App/Http/Controllers/FolderController';
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
import type { VisibilityOption } from '@/types/document';
import { useForm } from '@inertiajs/vue3';
import { FolderPlus, Lock, Users } from 'lucide-vue-next';
import { watch } from 'vue';

interface Props {
    visibilityOptions: VisibilityOption[];
    canShareWithOrg: boolean;
    parentFolderId: string | null;
}

const props = defineProps<Props>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    name: '',
    visibility: 'private',
    parent_id: props.parentFolderId,
});

watch(
    () => props.parentFolderId,
    (newVal) => {
        form.parent_id = newVal;
    }
);

const handleSubmit = () => {
    form.post(FolderController.store().url, {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleClose = () => {
    open.value = false;
    form.reset();
};
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Create Folder</DialogTitle>
                <DialogDescription>
                    Create a new folder to organize your documents.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="handleSubmit" class="space-y-4">
                <!-- Name Input -->
                <div class="space-y-2">
                    <Label for="folder-name">Folder Name</Label>
                    <Input
                        id="folder-name"
                        v-model="form.name"
                        placeholder="My Folder"
                        required
                    />
                    <p v-if="form.errors.name" class="text-xs text-destructive">
                        {{ form.errors.name }}
                    </p>
                </div>

                <!-- Visibility Select -->
                <div class="space-y-2">
                    <Label for="folder-visibility">Visibility</Label>
                    <Select v-model="form.visibility" :disabled="!canShareWithOrg">
                        <SelectTrigger>
                            <SelectValue placeholder="Select visibility" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="option in visibilityOptions"
                                :key="option.value"
                                :value="option.value"
                                :disabled="option.value === 'organization' && !canShareWithOrg"
                            >
                                <div class="flex items-center gap-2">
                                    <component
                                        :is="option.value === 'organization' ? Users : Lock"
                                        class="h-4 w-4"
                                    />
                                    {{ option.label }}
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ visibilityOptions.find(o => o.value === form.visibility)?.description }}
                    </p>
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="handleClose">
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!form.name || form.processing"
                    >
                        <FolderPlus class="mr-2 h-4 w-4" />
                        Create Folder
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
