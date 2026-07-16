import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path
                d="M16 14C7.5 20 7.5 44 16 50"
                stroke="var(--brand-sage)"
                strokeWidth="6"
                strokeLinecap="round"
            />
            <rect
                x="19"
                y="21"
                width="26"
                height="22"
                rx="8"
                fill="var(--brand-paprika)"
            />
            <path
                d="M48 14C56.5 20 56.5 44 48 50"
                stroke="var(--brand-oat)"
                strokeWidth="6"
                strokeLinecap="round"
            />
        </svg>
    );
}
