import type { Auth } from '@/types/auth';

type RecentMealPlan = {
    id: number;
    title: string;
    starts_on: string;
    ends_on: string;
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
            sidebarOpen: boolean;
            recentMealPlans: RecentMealPlan[];
            flash: {
                invitationUrl: string | null;
            };
            [key: string]: unknown;
        };
    }
}
