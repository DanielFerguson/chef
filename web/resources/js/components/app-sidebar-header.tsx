import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

const EMPTY_BREADCRUMBS: BreadcrumbItemType[] = [];

export function AppSidebarHeader({
    backLink,
    breadcrumbs = EMPTY_BREADCRUMBS,
    content,
}: {
    backLink?: BreadcrumbItemType;
    breadcrumbs?: BreadcrumbItemType[];
    content?: ReactNode;
}) {
    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex shrink-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {backLink && (
                    <Button asChild size="sm" variant="ghost">
                        <Link href={backLink.href}>
                            <ArrowLeft /> {backLink.title}
                        </Link>
                    </Button>
                )}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            {content}
        </header>
    );
}
