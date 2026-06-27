<script setup lang="ts">
import MaterialIcon from '@/components/MaterialIcon.vue';

// Documents are not part of the Melvin feed; static placeholder list.
const documents = [
    {
        icon: 'picture_as_pdf',
        title: 'Definitief Ontwerp',
        meta: 'PDF, 14.2 MB',
    },
    { icon: 'schedule', title: 'Bereikbaarheidsplan', meta: 'PDF, 2.5 MB' },
    { icon: 'eco', title: 'Klimaateffect rapport', meta: 'PDF, 5.1 MB' },
    { icon: 'newspaper', title: 'Laatste Bewonersbrief', meta: 'PDF, 0.8 MB' },
];
</script>

<template>
    <section class="bg-surface-container py-stack-xxl">
        <div class="px-margin-desktop mx-auto max-w-7xl">
            <h2
                class="font-headline-lg text-headline-lg text-primary mb-stack-lg"
            >
                Documenten &amp; Downloads
            </h2>
            <div
                class="gap-stack-md grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4"
            >
                <a
                    v-for="document in documents"
                    :key="document.title"
                    href="#"
                    class="gap-stack-md p-stack-md border-outline-variant group flex items-center rounded-xl border bg-white transition-all hover:shadow-md"
                >
                    <MaterialIcon
                        :name="document.icon"
                        class="text-primary transition-transform group-hover:scale-110"
                    />
                    <div>
                        <p class="font-label-md text-label-md font-bold">
                            {{ document.title }}
                        </p>
                        <p
                            class="font-caption text-caption text-on-surface-variant"
                        >
                            {{ document.meta }}
                        </p>
                    </div>
                </a>
            </div>
        </div>
    </section>
</template>
