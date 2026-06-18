<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const links = [
    { label: 'Overzicht', href: '/' },
    { label: 'Kaart', href: '/kaart' },
    { label: 'Planning', href: '#' },
    { label: 'Projecten', href: '#' },
];

const page = usePage();

const isActive = computed(() => (href: string): boolean => {
    if (href === '/') {
        return page.url === '/';
    }
    return href !== '#' && page.url.startsWith(href);
});
</script>

<template>
    <nav
        class="sticky top-0 z-50 w-full border-b border-outline-variant bg-surface"
    >
        <div
            class="mx-auto flex w-full max-w-7xl items-center justify-between px-margin-desktop py-4"
        >
            <div
                class="font-headline-md text-headline-md font-extrabold tracking-tight text-primary"
            >
                VoorMijnDeur
            </div>

            <div class="hidden items-center gap-stack-xl md:flex">
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="cursor-pointer font-body-md text-body-md transition-all duration-200"
                    :class="
                        isActive(link.href)
                            ? 'border-b-2 border-primary pb-1 text-primary'
                            : 'text-on-surface-variant transition-colors hover:text-primary'
                    "
                    >{{ link.label }}</Link
                >
            </div>

            <button
                class="cursor-pointer rounded-full bg-primary px-stack-lg py-2 font-label-md text-label-md text-on-primary transition-all hover:bg-primary-container"
            >
                Mijn Buurt
            </button>
        </div>
    </nav>
</template>
