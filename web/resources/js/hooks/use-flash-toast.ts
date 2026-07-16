import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

function subscribeToFlashToasts() {
    return router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (data) {
            toast[data.type](data.message);
        }
    });
}

export function useFlashToast(): void {
    useEffect(() => subscribeToFlashToasts(), []);
}
