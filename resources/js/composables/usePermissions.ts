import { usePage } from '@inertiajs/vue3';
import type { AppPageProps } from '@/types';

export function usePermissions() {
    const page = usePage<AppPageProps>();

    const can = (permission: string): boolean => {
        if (!page.props.auth.organization) {
            return true;
        }

        if (page.props.auth.roles.includes('sysadmin')) {
            return true;
        }

        return page.props.auth.permissions.includes(permission);
    };

    const hasRole = (role: string): boolean => {
        return page.props.auth.roles.includes(role);
    };

    const isSysAdmin = (): boolean => hasRole('sysadmin');

    const isOwner = (): boolean => hasRole('owner');

    const isAdmin = (): boolean => isSysAdmin() || isOwner();

    return { can, hasRole, isSysAdmin, isOwner, isAdmin };
}
