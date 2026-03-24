<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, Check, LogOut, Plus, Settings, UserCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

interface Props {
    user: User;
}

defineProps<Props>();

const { t } = useI18n();
const page = usePage();
const auth = computed(() => page.props.auth);

const handleLogout = () => {
    router.flushAll();
};

const switchAccount = (organizationId: string | null) => {
    router.post('/account/switch', { organization_id: organizationId }, {
        preserveScroll: true,
    });
};
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuLabel class="text-xs text-muted-foreground">{{ t('user_menu.accounts') }}</DropdownMenuLabel>
        <DropdownMenuItem
            class="cursor-pointer"
            @click="switchAccount(null)"
        >
            <UserCircle class="mr-2 h-4 w-4" />
            {{ t('user_menu.personal_account') }}
            <Check v-if="!user.organization_id" class="ml-auto h-4 w-4" />
        </DropdownMenuItem>
        <DropdownMenuItem
            v-for="membership in auth.memberships"
            :key="membership.organization_id"
            class="cursor-pointer"
            @click="switchAccount(membership.organization_id)"
        >
            <Building2 class="mr-2 h-4 w-4" />
            {{ membership.organization_name }}
            <Check v-if="user.organization_id === membership.organization_id" class="ml-auto h-4 w-4" />
        </DropdownMenuItem>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" href="/settings/organization/create" as="button">
                <Plus class="mr-2 h-4 w-4" />
                {{ t('user_menu.create_organization') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="edit()" prefetch as="button">
                <Settings class="mr-2 h-4 w-4" />
                {{ t('user_menu.settings') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <Link
            class="block w-full"
            :href="logout()"
            @click="handleLogout"
            as="button"
            data-test="logout-button"
        >
            <LogOut class="mr-2 h-4 w-4" />
            {{ t('user_menu.log_out') }}
        </Link>
    </DropdownMenuItem>
</template>
