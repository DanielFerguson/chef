import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { AppHeaderProvider } from '@/contexts/app-header-context';
import type { AppLayoutProps } from '@/types';

const EMPTY_BREADCRUMBS: NonNullable<AppLayoutProps['breadcrumbs']> = [];

export default function AppSidebarLayout({
    children,
    headerBackLink,
    breadcrumbs = EMPTY_BREADCRUMBS,
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppHeaderProvider>
                    {(headerContent) => (
                        <>
                            <AppSidebarHeader
                                backLink={headerBackLink}
                                breadcrumbs={breadcrumbs}
                                content={headerContent}
                            />
                            {children}
                        </>
                    )}
                </AppHeaderProvider>
            </AppContent>
        </AppShell>
    );
}
