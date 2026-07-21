import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

const EMPTY_BREADCRUMBS: BreadcrumbItem[] = [];

export default function AppLayout({
    breadcrumbs = EMPTY_BREADCRUMBS,
    children,
    headerBackLink,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
    headerBackLink?: BreadcrumbItem;
}) {
    return (
        <AppLayoutTemplate
            breadcrumbs={breadcrumbs}
            headerBackLink={headerBackLink}
        >
            {children}
        </AppLayoutTemplate>
    );
}
