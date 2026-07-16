import { Link } from '@inertiajs/react';
import {
    CalendarDays,
    CirclePlus,
    Clock3,
    Search,
    ShoppingBasket,
    Utensils,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { TeamSwitcher } from '@/components/team-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Today',
        href: dashboard(),
        icon: Clock3,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <div className="px-2 pb-2">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton
                                disabled
                                tooltip="Available with conversational planning"
                                className="bg-sidebar-primary text-sidebar-primary-foreground hover:bg-sidebar-primary/90 hover:text-sidebar-primary-foreground disabled:opacity-70"
                            >
                                <CirclePlus />
                                <span>New meal plan</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>
                <NavMain items={mainNavItems} />
                <div className="px-2">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton disabled tooltip="Calendar">
                                <CalendarDays />
                                <span>Calendar</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton disabled tooltip="Shopping">
                                <ShoppingBasket />
                                <span>Shopping</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton disabled tooltip="Search">
                                <Search />
                                <span>Search</span>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>
                <div className="mt-5 px-4 group-data-[collapsible=icon]:hidden">
                    <div className="mb-2 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                        <Utensils className="size-3.5" />
                        Plans
                    </div>
                    <p className="text-xs leading-5 text-muted-foreground/80">
                        Your meal plans will live here.
                    </p>
                </div>
            </SidebarContent>

            <SidebarFooter>
                <TeamSwitcher />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
