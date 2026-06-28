<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProjectMap from '@/components/project/ProjectMap.vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

interface AccessItem {
    icon: string;
    title: string;
    text: string;
}

const props = defineProps<{ project: App.Data.ProjectDetail }>();

const access = computed(() => props.project.access as AccessItem[]);

const facts = computed(() => [
    { label: 'Duur', value: props.project.duration },
    { label: 'Soort', value: props.project.typeLabel },
    { label: 'Fase', value: props.project.phaseLabel },
    { label: 'Uitvoerder', value: props.project.authority ?? 'Onbekend' },
]);

// Tone for the hindrance banner, scaled to the hindrance class (0 → 4).
const impact = computed(() => {
    const level = props.project.hindranceLevel;
    if (level < 0) {
        return {
            icon: 'fa-circle-question',
            bg: '#EEF1F5',
            border: '#9AA6B8',
            text: '#3D5078',
        };
    }
    if (level === 0) {
        return {
            icon: 'fa-circle-check',
            bg: '#E2F1E9',
            border: '#1F8A5B',
            text: '#14633F',
        };
    }
    if (level <= 2) {
        return {
            icon: 'fa-triangle-exclamation',
            bg: '#FFF4C2',
            border: '#C99700',
            text: '#7A5B00',
        };
    }
    return {
        icon: 'fa-circle-exclamation',
        bg: '#FBE5E5',
        border: '#C0392B',
        text: '#8A211A',
    };
});
</script>

<template>
    <Head :title="`${project.title} | voormijndeur`" />

    <!-- Breadcrumb -->
    <div class="border-b border-outline-variant bg-white">
        <div
            class="mx-auto max-w-[880px] px-margin-desktop py-2.5 text-[13px] font-medium text-on-surface-variant"
        >
            <Link href="/" class="text-primary hover:underline">Home</Link>
            &nbsp;›&nbsp;
            <Link href="/kaart" class="text-primary hover:underline"
                >Werkzaamheden</Link
            >
            &nbsp;›&nbsp;
            <span class="font-semibold text-on-surface"
                >{{ project.locationLabel }}</span
            >
        </div>
    </div>

    <div class="mx-auto max-w-[880px] px-margin-desktop pt-5 pb-stack-xxl">
        <Link
            href="/kaart"
            class="mb-4 inline-flex items-center gap-2 text-[14px] font-semibold text-primary hover:underline"
        >
            ← Terug naar de kaart
        </Link>

        <div
            class="overflow-hidden rounded-2xl bg-white shadow-[0_1px_4px_rgba(10,30,60,0.1)]"
        >
            <!-- Status banner -->
            <div class="px-7 py-6" :style="{ background: project.bannerBg }">
                <div
                    class="mb-2.5 flex items-center gap-2 text-[12px] font-bold tracking-wider"
                    :style="{ color: project.bannerText }"
                >
                    <span
                        class="h-2.5 w-2.5 rounded-full"
                        :style="{ background: project.markerColor, boxShadow: `0 0 0 4px ${project.ringColor}` }"
                    ></span>
                    {{ project.statusLabel.toUpperCase() }}
                </div>
                <h1
                    class="font-display text-[32px] leading-tight font-extrabold tracking-tight text-primary"
                >
                    {{ project.title }}
                </h1>
                <p
                    class="mt-1.5 mb-4.5 text-[15px] font-semibold"
                    :style="{ color: project.bannerText }"
                >
                    {{ project.locationLabel }}
                    · uitgevoerd door {{ project.authority ?? 'onbekend' }}
                </p>
                <div
                    class="relative h-2 max-w-[520px] overflow-hidden rounded-full bg-primary/15"
                >
                    <div
                        class="absolute top-0 left-0 h-full rounded-full bg-primary"
                        :style="{ width: `${project.progress}%` }"
                    ></div>
                </div>
                <div
                    class="mt-1.5 flex max-w-[520px] justify-between text-[12px] font-semibold"
                    :style="{ color: project.bannerText }"
                >
                    <span>{{ project.startLabel }}</span
                    ><span>{{ project.endLabel }}</span>
                </div>
            </div>

            <!-- Fact cards -->
            <div class="grid grid-cols-2 gap-3 px-7 pt-5 pb-1.5 md:grid-cols-4">
                <div
                    v-for="fact in facts"
                    :key="fact.label"
                    class="rounded-[11px] bg-surface-container-low px-4 py-3.5"
                >
                    <div
                        class="mb-1.5 text-[11px] font-semibold tracking-wide text-outline uppercase"
                    >
                        {{ fact.label }}
                    </div>
                    <div class="text-[16px] font-bold text-primary">
                        {{ fact.value }}
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div class="px-7 pt-4.5 pb-1">
                <h2
                    class="my-2.5 border-l-4 border-secondary-container pl-3 font-display text-[18px] font-bold text-primary"
                >
                    Wat gaat er gebeuren?
                </h2>
                <p
                    class="text-[15.5px] leading-relaxed text-on-surface-variant"
                >
                    {{ project.description }}
                </p>
            </div>

            <!-- Accessibility -->
            <div class="px-7 pt-4.5 pb-1">
                <h2
                    class="mt-2.5 mb-3.5 border-l-4 border-secondary-container pl-3 font-display text-[18px] font-bold text-primary"
                >
                    Blijft mijn huis bereikbaar?
                </h2>
                <div
                    class="mb-3.5 flex items-center gap-3 rounded-[11px] border-l-4 px-4 py-3"
                    :style="{ background: impact.bg, borderColor: impact.border }"
                >
                    <i
                        class="fa-solid text-[16px]"
                        :class="impact.icon"
                        :style="{ color: impact.border }"
                    ></i>
                    <div
                        class="text-[14px] font-semibold"
                        :style="{ color: impact.text }"
                    >
                        {{ project.hindranceLabel }}
                        <span class="font-medium opacity-80"
                            >· ernst:
                            {{ project.severityLabel.toLowerCase() }}</span
                        >
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div
                        v-for="item in access"
                        :key="item.title"
                        class="flex items-start gap-3.5 rounded-[11px] bg-surface-container-low px-4 py-3.5"
                    >
                        <div
                            class="flex h-[34px] w-[34px] flex-shrink-0 items-center justify-center rounded-[9px] bg-[#FFF4C2]"
                        >
                            <i
                                class="fa-solid text-[15px] text-primary"
                                :class="item.icon"
                            ></i>
                        </div>
                        <div>
                            <div
                                class="mb-0.5 text-[14px] font-bold text-on-surface"
                            >
                                {{ item.title }}
                            </div>
                            <div
                                class="text-[13px] leading-snug text-on-surface-variant"
                            >
                                {{ item.text }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Location -->
            <div class="px-7 pt-4.5 pb-1.5">
                <div
                    class="mt-2.5 mb-3 flex items-center justify-between gap-2"
                >
                    <h2
                        class="border-l-4 border-secondary-container pl-3 font-display text-[18px] font-bold text-primary"
                    >
                        Locatie
                    </h2>
                    <span
                        class="rounded border border-outline-variant bg-white px-2 py-1 text-[10px] font-bold text-primary"
                        >LIVE KAART</span
                    >
                </div>
                <ProjectMap
                    :roadwork-id="project.id"
                    :latitude="project.latitude"
                    :longitude="project.longitude"
                    :marker-color="project.markerColor"
                    :icon="project.icon"
                />
            </div>

            <!-- Contact -->
            <div
                class="m-7 flex items-center justify-between gap-4 rounded-[13px] bg-primary px-6 py-5"
            >
                <div>
                    <div class="mb-0.5 text-[16px] font-bold text-on-primary">
                        Iets onduidelijk of overlast?
                    </div>
                    <div class="text-[13.5px] text-on-primary-container">
                        {{ project.contact }}
                    </div>
                </div>
                <button
                    type="button"
                    class="rounded-[9px] bg-secondary-container px-5 py-3 text-[14px] font-bold whitespace-nowrap text-on-secondary-container"
                >
                    Stel een vraag
                </button>
            </div>
        </div>
    </div>
</template>
