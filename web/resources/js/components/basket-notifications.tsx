import { router, useHttp, usePage } from '@inertiajs/react';
import { Bell, Check, ShoppingBasket } from 'lucide-react';
import { useEffect } from 'react';
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
import { show as showBasketRun } from '@/routes/basket-runs';
import { read as readNotification } from '@/routes/notifications';

export function BasketNotifications() {
    const { notifications } = usePage().props;
    const readRequest = useHttp<
        Record<string, never>,
        { read_at: string | null }
    >({});

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({
                    only: ['notifications'],
                });
            }
        }, 15_000);

        return () => window.clearInterval(interval);
    }, []);

    const openNotification = async (
        notificationId: string,
        basketRunId: number,
    ) => {
        await readRequest.put(readNotification.url(notificationId));
        router.get(showBasketRun.url(basketRunId));
    };

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton tooltip="Notifications">
                            <span className="relative">
                                <Bell />
                                {notifications.unread_count > 0 && (
                                    <span className="absolute -top-1 -right-1 size-2 rounded-full bg-primary ring-2 ring-sidebar" />
                                )}
                            </span>
                            <span>Notifications</span>
                            {notifications.unread_count > 0 && (
                                <span className="ml-auto text-xs font-medium">
                                    {notifications.unread_count}
                                </span>
                            )}
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="end"
                        className="w-80 max-w-[calc(100vw-2rem)]"
                    >
                        <DropdownMenuLabel>Basket updates</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        {notifications.items.length === 0 ? (
                            <p className="px-2 py-5 text-center text-sm text-muted-foreground">
                                No basket updates yet.
                            </p>
                        ) : (
                            notifications.items.map((notification) => (
                                <DropdownMenuItem
                                    key={notification.id}
                                    className="items-start py-3"
                                    onSelect={() =>
                                        void openNotification(
                                            notification.id,
                                            notification.basket_run_id,
                                        )
                                    }
                                >
                                    {notification.read_at === null ? (
                                        <ShoppingBasket className="mt-0.5 text-primary" />
                                    ) : (
                                        <Check className="mt-0.5" />
                                    )}
                                    <span className="min-w-0">
                                        <span className="block font-medium">
                                            {notification.title}
                                        </span>
                                        <span className="mt-0.5 block text-xs leading-5 text-muted-foreground">
                                            {notification.message}
                                        </span>
                                    </span>
                                </DropdownMenuItem>
                            ))
                        )}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
