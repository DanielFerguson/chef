import AppLogoIcon from '@/components/app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-lg border bg-white">
                <AppLogoIcon className="size-6" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="font-editorial mb-0.5 truncate text-base leading-tight font-medium">
                    Chef
                </span>
            </div>
        </>
    );
}
