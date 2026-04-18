import { InertiaLinkProps } from '@inertiajs/vue3';
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function urlIsActive(
    urlToCheck: NonNullable<InertiaLinkProps['href']>,
    currentUrl: string,
) {
    const target = toUrl(urlToCheck);
    if (!target) return false;

    const [targetPath] = target.split('?');
    const [currentPath] = currentUrl.split('?');

    return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`);
}

export function toUrl(href: NonNullable<InertiaLinkProps['href']>) {
    return typeof href === 'string' ? href : href?.url;
}
