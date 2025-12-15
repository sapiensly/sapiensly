export type MembershipRole = 'admin' | 'member';
export type MembershipStatus = 'active' | 'inactive' | 'pending';

export interface Organization {
    id: string;
    name: string;
    slug: string | null;
    created_at: string;
    updated_at: string;
}

export interface OrganizationMembership {
    id: string;
    organization_id: string;
    user_id: number;
    role: MembershipRole;
    status: MembershipStatus;
    organization?: Organization;
    created_at: string;
    updated_at: string;
}
