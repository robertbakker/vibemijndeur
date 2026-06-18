<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

const filters = [
    { label: 'Werkzaamheden', icon: 'construction', active: true },
    { label: 'Wegafsluitingen', icon: 'block', active: false },
    { label: 'Omleidingen', icon: 'alt_route', active: false },
    { label: 'Parkeren', icon: 'local_parking', active: false },
    { label: 'Contact', icon: 'support_agent', active: false },
];
</script>

<template>
    <aside
        class="sticky top-24 hidden h-full w-64 flex-col gap-base rounded-xl border border-outline-variant bg-surface-container-low p-stack-md lg:flex"
    >
        <div class="px-2 py-4">
            <h3
                class="font-headline-md text-headline-md font-bold text-primary"
            >
                Filters
            </h3>
            <p class="font-label-md text-label-md text-on-surface-variant">
                Verfijn resultaten
            </p>
        </div>
        <nav class="flex flex-col gap-1">
            <div
                v-for="filter in filters"
                :key="filter.label"
                class="flex cursor-pointer items-center gap-3 rounded-lg px-4 py-3 transition-colors duration-200"
                :class="
                    filter.active
                        ? 'bg-primary-fixed text-on-primary-fixed'
                        : 'text-on-surface-variant hover:bg-surface-container-high'
                "
            >
                <MaterialIcon :name="filter.icon" />
                <span class="font-label-md text-label-md">{{
                    filter.label
                }}</span>
            </div>
        </nav>
    </aside>
</template>
