<script setup lang="ts">
import * as DocumentController from '@/actions/App/Http/Controllers/DocumentController';
import type { Folder, GroupedFolders } from '@/types/document';
import { Link, router } from '@inertiajs/vue3';
import {
    ChevronDown,
    ChevronRight,
    FolderIcon,
    FolderOpen,
    Home,
    Lock,
    Users,
} from 'lucide-vue-next';
import { ref } from 'vue';

interface Props {
    folders: GroupedFolders;
    currentFolderId: string | null;
}

const props = defineProps<Props>();

const expandedFolders = ref<Set<string>>(new Set());

const toggleFolder = (folderId: string, event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    if (expandedFolders.value.has(folderId)) {
        expandedFolders.value.delete(folderId);
    } else {
        expandedFolders.value.add(folderId);
    }
};

const navigateToFolder = (folderId: string) => {
    router.visit(DocumentController.index({ query: { folder: folderId } }).url);
};

const isExpanded = (folderId: string) => expandedFolders.value.has(folderId);
const isActive = (folderId: string) => props.currentFolderId === folderId;
const hasChildren = (folder: Folder) =>
    folder.children && folder.children.length > 0;
</script>

<template>
    <div class="space-y-4">
        <!-- Root / All Documents -->
        <Link
            :href="DocumentController.index().url"
            :class="[
                'flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                !currentFolderId ? 'bg-accent text-accent-foreground' : '',
            ]"
        >
            <Home class="h-4 w-4 text-muted-foreground" />
            <span>All Documents</span>
        </Link>

        <!-- My Folders -->
        <div v-if="folders.my.length > 0">
            <h4
                class="mb-2 px-2 text-xs font-semibold text-muted-foreground uppercase"
            >
                My Folders
            </h4>
            <div class="space-y-0.5">
                <template v-for="folder in folders.my" :key="folder.id">
                    <div>
                        <div
                            :class="[
                                'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                isActive(folder.id)
                                    ? 'bg-accent text-accent-foreground'
                                    : '',
                            ]"
                            @click="navigateToFolder(folder.id)"
                        >
                            <button
                                v-if="hasChildren(folder)"
                                type="button"
                                class="shrink-0 rounded p-0.5 hover:bg-muted"
                                @click.stop="toggleFolder(folder.id, $event)"
                            >
                                <ChevronDown
                                    v-if="isExpanded(folder.id)"
                                    class="h-3 w-3"
                                />
                                <ChevronRight v-else class="h-3 w-3" />
                            </button>
                            <span v-else class="w-4" />

                            <FolderOpen
                                v-if="
                                    isExpanded(folder.id) || isActive(folder.id)
                                "
                                class="h-4 w-4 text-muted-foreground"
                            />
                            <FolderIcon
                                v-else
                                class="h-4 w-4 text-muted-foreground"
                            />
                            <span class="flex-1 truncate">{{
                                folder.name
                            }}</span>
                            <Users
                                v-if="folder.visibility === 'organization'"
                                class="h-3 w-3 text-muted-foreground"
                            />
                            <Lock
                                v-else
                                class="h-3 w-3 text-muted-foreground opacity-0 group-hover:opacity-100"
                            />
                        </div>

                        <!-- Children (Level 1) -->
                        <div
                            v-if="isExpanded(folder.id) && hasChildren(folder)"
                            class="ml-4"
                        >
                            <template
                                v-for="child in folder.children"
                                :key="child.id"
                            >
                                <div>
                                    <div
                                        :class="[
                                            'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                            isActive(child.id)
                                                ? 'bg-accent text-accent-foreground'
                                                : '',
                                        ]"
                                        @click="navigateToFolder(child.id)"
                                    >
                                        <button
                                            v-if="hasChildren(child)"
                                            type="button"
                                            class="shrink-0 rounded p-0.5 hover:bg-muted"
                                            @click.stop="
                                                toggleFolder(child.id, $event)
                                            "
                                        >
                                            <ChevronDown
                                                v-if="isExpanded(child.id)"
                                                class="h-3 w-3"
                                            />
                                            <ChevronRight
                                                v-else
                                                class="h-3 w-3"
                                            />
                                        </button>
                                        <span v-else class="w-4" />

                                        <FolderOpen
                                            v-if="
                                                isExpanded(child.id) ||
                                                isActive(child.id)
                                            "
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <FolderIcon
                                            v-else
                                            class="h-4 w-4 text-muted-foreground"
                                        />
                                        <span class="flex-1 truncate">{{
                                            child.name
                                        }}</span>
                                        <Users
                                            v-if="
                                                child.visibility ===
                                                'organization'
                                            "
                                            class="h-3 w-3 text-muted-foreground"
                                        />
                                        <Lock
                                            v-else
                                            class="h-3 w-3 text-muted-foreground opacity-0 group-hover:opacity-100"
                                        />
                                    </div>

                                    <!-- Children (Level 2) -->
                                    <div
                                        v-if="
                                            isExpanded(child.id) &&
                                            hasChildren(child)
                                        "
                                        class="ml-4"
                                    >
                                        <div
                                            v-for="grandchild in child.children"
                                            :key="grandchild.id"
                                            :class="[
                                                'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                                isActive(grandchild.id)
                                                    ? 'bg-accent text-accent-foreground'
                                                    : '',
                                            ]"
                                            @click="
                                                navigateToFolder(grandchild.id)
                                            "
                                        >
                                            <span class="w-4" />
                                            <FolderOpen
                                                v-if="isActive(grandchild.id)"
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <FolderIcon
                                                v-else
                                                class="h-4 w-4 text-muted-foreground"
                                            />
                                            <span class="flex-1 truncate">{{
                                                grandchild.name
                                            }}</span>
                                            <Users
                                                v-if="
                                                    grandchild.visibility ===
                                                    'organization'
                                                "
                                                class="h-3 w-3 text-muted-foreground"
                                            />
                                            <Lock
                                                v-else
                                                class="h-3 w-3 text-muted-foreground opacity-0 group-hover:opacity-100"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Organization Folders -->
        <div v-if="folders.organization.length > 0">
            <h4
                class="mb-2 px-2 text-xs font-semibold text-muted-foreground uppercase"
            >
                Organization
            </h4>
            <div class="space-y-0.5">
                <template
                    v-for="folder in folders.organization"
                    :key="folder.id"
                >
                    <div>
                        <div
                            :class="[
                                'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                isActive(folder.id)
                                    ? 'bg-accent text-accent-foreground'
                                    : '',
                            ]"
                            @click="navigateToFolder(folder.id)"
                        >
                            <button
                                v-if="hasChildren(folder)"
                                type="button"
                                class="shrink-0 rounded p-0.5 hover:bg-muted"
                                @click.stop="toggleFolder(folder.id, $event)"
                            >
                                <ChevronDown
                                    v-if="isExpanded(folder.id)"
                                    class="h-3 w-3"
                                />
                                <ChevronRight v-else class="h-3 w-3" />
                            </button>
                            <span v-else class="w-4" />

                            <FolderOpen
                                v-if="
                                    isExpanded(folder.id) || isActive(folder.id)
                                "
                                class="h-4 w-4 text-muted-foreground"
                            />
                            <FolderIcon
                                v-else
                                class="h-4 w-4 text-muted-foreground"
                            />
                            <span class="flex-1 truncate">{{
                                folder.name
                            }}</span>
                            <Users class="h-3 w-3 text-muted-foreground" />
                        </div>

                        <!-- Children -->
                        <div
                            v-if="isExpanded(folder.id) && hasChildren(folder)"
                            class="ml-4"
                        >
                            <div
                                v-for="child in folder.children"
                                :key="child.id"
                                :class="[
                                    'group flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent',
                                    isActive(child.id)
                                        ? 'bg-accent text-accent-foreground'
                                        : '',
                                ]"
                                @click="navigateToFolder(child.id)"
                            >
                                <span class="w-4" />
                                <FolderOpen
                                    v-if="isActive(child.id)"
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                <FolderIcon
                                    v-else
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                <span class="flex-1 truncate">{{
                                    child.name
                                }}</span>
                                <Users class="h-3 w-3 text-muted-foreground" />
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
