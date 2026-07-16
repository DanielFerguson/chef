import { router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, UsersRound } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useIsMobile } from '@/hooks/use-mobile';

export function NavUser() {
    const { auth } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    if (!auth.user) {
        return null;
    }

    const switchTeam = (teamId: number) => {
        if (teamId === auth.currentTeam?.id) {
            return;
        }

        router.put(`/teams/${teamId}/current`, undefined, {
            preserveScroll: true,
        });
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                            tooltip="Family and account"
                            aria-label="Open family and account menu"
                            data-test="sidebar-family-account-button"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-sidebar-accent text-sidebar-accent-foreground">
                                <UsersRound className="size-4" />
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span
                                    className="block truncate text-sm font-medium"
                                    data-sidebar-family-name
                                >
                                    {auth.currentTeam?.name ?? auth.user.name}
                                </span>
                                <span
                                    className="block truncate text-xs text-muted-foreground"
                                    data-sidebar-user-name
                                >
                                    {auth.currentTeam
                                        ? auth.user.name
                                        : auth.user.email}
                                </span>
                            </span>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="start"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'right'
                                  : 'top'
                        }
                        data-test="sidebar-family-account-menu"
                    >
                        <DropdownMenuLabel className="p-0 font-normal">
                            <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                                <UserInfo user={auth.user} showEmail />
                            </div>
                        </DropdownMenuLabel>
                        {auth.currentTeam && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>
                                    Family workspace
                                </DropdownMenuLabel>
                                {auth.teams.map((team) => (
                                    <DropdownMenuItem
                                        key={team.id}
                                        onSelect={() => switchTeam(team.id)}
                                    >
                                        <span className="truncate">
                                            {team.name}
                                        </span>
                                        {team.id === auth.currentTeam?.id && (
                                            <Check className="ml-auto size-4" />
                                        )}
                                    </DropdownMenuItem>
                                ))}
                            </>
                        )}
                        <DropdownMenuSeparator />
                        <UserMenuContent
                            user={auth.user}
                            showUserInfo={false}
                        />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
