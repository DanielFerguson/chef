import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg border bg-white">
                <AppLogoIcon className="size-6" />
            </div>
            <div className="ml-1 flex h-8 min-w-0 flex-1 items-center text-left">
                <span className="truncate font-editorial text-[1.0625rem] leading-none font-medium">
                    Chef
                </span>
            </div>
        </>
    );
}
