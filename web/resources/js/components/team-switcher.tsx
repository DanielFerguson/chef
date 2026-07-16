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
} from '@/components/ui/sidebar';

export function TeamSwitcher() {
    const { auth } = usePage().props;

    if (!auth.currentTeam) {
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
                        <SidebarMenuButton size="lg" tooltip="Switch family">
                            <span className="flex size-8 items-center justify-center rounded-lg bg-sidebar-accent text-sidebar-accent-foreground">
                                <UsersRound className="size-4" />
                            </span>
                            <span className="min-w-0 flex-1 text-left">
                                <span className="block truncate text-sm font-medium">
                                    {auth.currentTeam.name}
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    Family workspace
                                </span>
                            </span>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="top"
                        align="start"
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56"
                    >
                        <DropdownMenuLabel>Your families</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {auth.teams.map((team) => (
                            <DropdownMenuItem
                                key={team.id}
                                onSelect={() => switchTeam(team.id)}
                            >
                                <span className="truncate">{team.name}</span>
                                {team.id === auth.currentTeam?.id && (
                                    <Check className="ml-auto size-4" />
                                )}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
