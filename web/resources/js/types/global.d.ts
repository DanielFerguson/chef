import type { Auth } from '@/types/auth';

export type RecentMealPlan = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
    revision: number;
    phase: 'Draft' | 'Confirmed' | 'Preparing' | 'Superseded';
    can: {
        update: boolean;
        delete: boolean;
    };
};

export type PendingInvitation = {
    householdName: string;
    inviterName: string | null;
    url: string;
};

export type BasketNotification = {
    id: string;
    title: string;
    message: string;
    status: string;
    basket_run_id: number;
    read_at: string | null;
    created_at: string | null;
};

export type BasketNotifications = {
    unread_count: number;
    items: BasketNotification[];
};

declare module 'react' {
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            pendingInvitation: PendingInvitation | null;
            sidebarOpen: boolean;
            recentMealPlans: RecentMealPlan[];
            notifications: BasketNotifications;
            flash: {
                invitationUrl: string | null;
            };
            [key: string]: unknown;
        };
    }
}
