import { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from 'lucide-vue-next';

export interface Organization {
    id: string;
    name: string;
    slug: string | null;
}

export interface Membership {
    organization_id: string;
    organization_name: string;
    role: 'owner' | 'member';
}

export interface Auth {
    user: User;
    organization: Organization | null;
    memberships: Membership[];
    permissions: string[];
    roles: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    impersonating: boolean;
    sidebarOpen: boolean;
    locale: string;
    availableLocales: string[];
};

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    locale: string;
    organization_id: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
