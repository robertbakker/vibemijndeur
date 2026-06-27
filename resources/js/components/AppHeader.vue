<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

withDefaults(
    defineProps<{
        fullWidth?: boolean;
    }>(),
    {
        fullWidth: false,
    },
);

const links = [
    { label: 'Home', href: '/' },
    { label: 'Kaart', href: '/kaart' },
    { label: 'Over werkzaamheden', href: '#' },
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
    <header
        class="sticky top-0 z-50 bg-primary shadow-[0_1px_0_rgba(255,255,255,0.06)]"
    >
        <div
            class="mx-auto flex h-16 items-center justify-between px-margin-desktop"
            :class="fullWidth ? '' : 'max-w-7xl'"
        >
            <Link href="/" class="flex items-center gap-2.5">
                <span
                    class="h-6 w-6 rounded-[5px] bg-secondary-container"
                ></span>
                <span
                    class="font-display text-[20px] font-extrabold tracking-tight text-on-primary"
                    >voormijndeur</span
                >
            </Link>

            <nav class="flex items-center gap-1.5">
                <Link
                    v-for="link in links"
                    :key="link.label"
                    :href="link.href"
                    class="rounded-md px-3.5 py-2 font-label-md text-label-md font-semibold transition-colors hover:bg-white/10"
                    :class="isActive(link.href) ? 'text-on-primary' : 'text-on-primary/70'"
                    >{{ link.label }}</Link
                >
                <Link
                    href="/kaart"
                    class="ml-2 flex items-center gap-1.5 rounded-lg bg-secondary-container px-4 py-2.5 font-label-md text-label-md font-bold text-on-secondary-container transition-transform hover:-translate-y-0.5"
                >
                    Mijn buurt
                </Link>
            </nav>
        </div>
    </header>
</template>
