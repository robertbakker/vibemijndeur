<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import MaterialIcon from '@/components/MaterialIcon.vue';
import MapPreview from '@/components/MapPreview.vue';

defineOptions({ layout: AppLayout });

interface ProjectCard {
    id: number;
    title: string;
    description: string;
    badge: { label: string; class: string };
    meta: { icon: string; text: string; class: string }[];
    authority: string | null;
    authorityInitials: string;
    endLabel: string | null;
}

const props = defineProps<{
    projects: ProjectCard[];
    roadworksTotal: number;
}>();

const featured = computed(() => props.projects[0] ?? null);
const rest = computed(() => props.projects.slice(1));

const featuredImage =
    'https://lh3.googleusercontent.com/aida-public/AB6AXuD98XlccVTgodnL_N4YjUEIiIKpvCjWMVXE33p6qgW_4kae75tQ-8WPrVjEGashMe2ca2Z2cLdtKHuQb8NA_NuHZjLMzDt8KqMatUa0qH6RV1TGbXvqlqmIG4UEh2l1JjezA5coaNKmCk2WDHyloJbEtzXozBvlDYgI5u2-nCyA5eZIRB1c2W_LqVLUIshg51jzizh0TGjEDELktXhgTYCB20IAnrqqq4TdCJ-vjIAR6EqkWl-FM7qcxYADBaydSK0VrejKZSNNR5IF';
</script>

<template>
    <Head title="Actuele Infrastructurele Projecten" />

    <div>
        <!-- Hero -->
        <header
            class="grid grid-cols-1 items-end gap-gutter border-b border-outline-variant py-stack-xxl md:grid-cols-12"
        >
            <div class="md:col-span-8">
                <div
                    class="mb-stack-md inline-block rounded-sm bg-secondary-container px-3 py-1 font-label-md text-label-md text-on-secondary-container"
                >
                    OFFICIEEL OVERHEIDSPORTAAL
                </div>
                <h1
                    class="font-display text-display leading-tight text-primary"
                >
                    Actuele Infrastructurele <br />Projecten
                </h1>
                <p
                    class="mt-stack-md max-w-2xl font-body-lg text-body-lg text-on-surface-variant"
                >
                    Transparante informatie over wegwerkzaamheden, omleidingen
                    en stedelijke vernieuwing in uw directe omgeving.
                </p>
            </div>
            <div class="flex flex-col gap-stack-sm md:col-span-4">
                <label
                    class="font-label-md text-label-md text-primary"
                    for="search"
                    >Zoek op straat of postcode</label
                >
                <div class="relative flex items-center">
                    <MaterialIcon
                        name="search"
                        class="absolute left-4 text-outline"
                    />
                    <input
                        id="search"
                        type="text"
                        placeholder="Bijv. 1012 AB of Damrak"
                        class="w-full rounded-lg border border-outline-variant bg-white py-4 pr-4 pl-12 transition-all focus:border-transparent focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                </div>
            </div>
        </header>

        <div class="flex gap-gutter py-stack-xl">
            <AppSidebar />

            <!-- Project grid -->
            <section class="flex-1">
                <div class="mb-stack-lg flex items-center justify-between">
                    <h2 class="font-headline-lg text-headline-lg text-primary">
                        Projectoverzicht
                    </h2>
                    <span class="text-caption text-on-surface-variant">
                        {{ roadworksTotal.toLocaleString('nl-NL') }} werkzaamheden landelijk
                    </span>
                </div>

                <div
                    v-if="projects.length === 0"
                    class="rounded-xl border border-dashed border-outline-variant p-stack-xl text-center text-on-surface-variant"
                >
                    Momenteel geen lopende werkzaamheden gevonden.
                </div>

                <!-- Bento-style grid -->
                <div v-else class="grid grid-cols-1 gap-stack-lg md:grid-cols-2">
                    <!-- Featured project -->
                    <Link
                        v-if="featured"
                        :href="`/projecten/${featured.id}`"
                        class="flex h-full flex-col overflow-hidden rounded-xl border border-outline-variant bg-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_rgba(0,32,91,0.08)] md:col-span-2 md:flex-row"
                    >
                        <div class="h-64 overflow-hidden md:h-auto md:w-1/2">
                            <div
                                class="h-full w-full bg-cover bg-center"
                                :style="{
                                    backgroundImage: `url('${featuredImage}')`,
                                }"
                            ></div>
                        </div>
                        <div
                            class="flex flex-col justify-between p-stack-lg md:w-1/2"
                        >
                            <div>
                                <div
                                    class="mb-stack-sm flex items-start justify-between"
                                >
                                    <span
                                        class="rounded-full bg-secondary-container px-3 py-1 text-caption font-bold text-on-secondary-container"
                                        >LOPENDE WERKZAAMHEDEN</span
                                    >
                                    <span
                                        v-if="featured.endLabel"
                                        class="flex items-center gap-1 text-caption text-on-surface-variant"
                                    >
                                        <MaterialIcon
                                            name="calendar_today"
                                            class="text-[16px]"
                                        />
                                        {{ featured.endLabel }}
                                    </span>
                                </div>
                                <h3
                                    class="mb-stack-sm font-headline-lg text-headline-lg text-primary"
                                >
                                    {{ featured.title }}
                                </h3>
                                <p
                                    class="line-clamp-3 font-body-md text-body-md text-on-surface-variant"
                                >
                                    {{ featured.description }}
                                </p>
                            </div>
                            <div
                                class="mt-stack-lg flex items-center justify-between"
                            >
                                <div class="flex items-center gap-2">
                                    <div
                                        class="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white bg-primary-fixed text-[10px] font-bold text-on-primary-fixed"
                                    >
                                        {{ featured.authorityInitials }}
                                    </div>
                                    <span
                                        v-if="featured.authority"
                                        class="text-caption text-on-surface-variant"
                                        >{{ featured.authority }}</span
                                    >
                                </div>
                                <span
                                    class="flex items-center gap-2 font-label-md text-label-md text-primary hover:underline"
                                >
                                    Projectdetails
                                    <MaterialIcon
                                        name="arrow_forward"
                                        class="text-[18px]"
                                    />
                                </span>
                            </div>
                        </div>
                    </Link>

                    <!-- Regular projects -->
                    <Link
                        v-for="project in rest"
                        :key="project.id"
                        :href="`/projecten/${project.id}`"
                        class="group overflow-hidden rounded-xl border border-outline-variant bg-white transition-all duration-200 hover:-translate-y-0.5 hover:shadow-[0_12px_32px_rgba(0,32,91,0.08)]"
                    >
                        <div
                            class="relative flex h-32 items-center justify-center bg-surface-container-high"
                        >
                            <MaterialIcon
                                name="construction"
                                class="text-[40px] text-on-surface-variant transition-transform duration-500 group-hover:scale-110"
                            />
                            <div class="absolute top-4 left-4">
                                <span
                                    class="rounded-lg px-3 py-1 text-caption font-bold"
                                    :class="project.badge.class"
                                    >{{ project.badge.label }}</span
                                >
                            </div>
                        </div>
                        <div class="p-stack-lg">
                            <h4
                                class="mb-2 font-headline-md text-headline-md text-primary"
                            >
                                {{ project.title }}
                            </h4>
                            <p
                                class="mb-stack-md line-clamp-2 text-body-md text-on-surface-variant"
                            >
                                {{ project.description }}
                            </p>
                            <div
                                class="flex flex-wrap items-center gap-4 border-t border-outline-variant pt-stack-md text-caption text-on-surface-variant"
                            >
                                <span
                                    v-for="item in project.meta"
                                    :key="item.text"
                                    class="flex items-center gap-1"
                                    :class="item.class"
                                >
                                    <MaterialIcon
                                        :name="item.icon"
                                        class="text-[16px]"
                                    />
                                    {{ item.text }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Map preview -->
                <MapPreview />
            </section>
        </div>
    </div>
</template>
