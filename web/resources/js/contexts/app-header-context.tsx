import { createContext, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';

const AppHeaderContext = createContext<HTMLElement | null | undefined>(
    undefined,
);

export function AppHeaderProvider({
    children,
}: {
    children: (content: ReactNode) => ReactNode;
}) {
    const [target, setTarget] = useState<HTMLDivElement | null>(null);

    return (
        <AppHeaderContext.Provider value={target}>
            {children(
                <div
                    ref={setTarget}
                    className="flex min-w-0 flex-1 items-center"
                />,
            )}
        </AppHeaderContext.Provider>
    );
}

export function useAppHeader(content: ReactNode) {
    const target = useContext(AppHeaderContext);

    if (target === undefined) {
        throw new Error('useAppHeader must be used within AppHeaderProvider.');
    }

    return target ? createPortal(content, target) : null;
}
