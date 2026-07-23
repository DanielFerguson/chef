import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CirclePlus,
    Clock3,
    ShoppingBasket,
    Utensils,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { PlanRailItem } from '@/features/meal-plans/plan-rail-item';
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
    const { recentMealPlans } = usePage().props;
    const activeShoppingPlan = recentMealPlans.find(
        (plan) =>
            (plan.phase === 'Confirmed' || plan.phase === 'Preparing') &&
            plan.has_shopping_list,
    );
    const shoppingHref = activeShoppingPlan
        ? `/meal-plans/${activeShoppingPlan.id}?phase=shopping`
        : (() => {
              const confirmedPlan = recentMealPlans.find(
                  (plan) =>
                      plan.phase === 'Confirmed' || plan.phase === 'Preparing',
              );

              return confirmedPlan
                  ? `/meal-plans/${confirmedPlan.id}`
                  : '/shopping';
          })();

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
                                asChild
                                tooltip="Start a new meal plan"
                                className="bg-sidebar-primary text-sidebar-primary-foreground hover:bg-sidebar-primary/90 hover:text-sidebar-primary-foreground disabled:opacity-70"
                            >
                                <Link
                                    href="/meal-plans"
                                    method="post"
                                    as="button"
                                >
                                    <CirclePlus />
                                    <span>New meal plan</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>
                <NavMain items={mainNavItems} />
                <div className="px-2">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild tooltip="Recipes">
                                <Link href="/recipes">
                                    <BookOpen />
                                    <span>Recipes</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                        <SidebarMenuItem>
                            <SidebarMenuButton asChild tooltip="Shopping">
                                <Link href={shoppingHref}>
                                    <ShoppingBasket />
                                    <span>Shopping</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </div>
                <div className="mt-5 px-4 group-data-[collapsible=icon]:hidden">
                    <div className="mb-2 flex items-center gap-2 text-xs font-medium text-muted-foreground">
                        <Utensils className="size-3.5" />
                        Plans
                    </div>
                    {recentMealPlans.length === 0 ? (
                        <p className="text-xs leading-5 text-muted-foreground/80">
                            Your meal plans will live here.
                        </p>
                    ) : (
                        <nav
                            className="space-y-0.5"
                            aria-label="Recent meal plans"
                        >
                            {recentMealPlans.map((plan) => (
                                <PlanRailItem key={plan.id} plan={plan} />
                            ))}
                        </nav>
                    )}
                </div>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
