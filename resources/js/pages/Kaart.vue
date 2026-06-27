<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref, useTemplateRef } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import RoadworkMap, {
    type MapFilters,
    type RoadworkFeatureProps,
} from '@/components/RoadworkMap.vue';
import {
    periodLabel,
    type StatusKey,
    statusView,
    typeView,
} from '@/lib/roadwork';

const filters = reactive<MapFilters>({ q: '', kind: [], status: [] });
const total = ref(0);
const visible = ref<RoadworkFeatureProps[]>([]);
const selected = ref<RoadworkFeatureProps | null>(null);
const searchInput = ref('');
const statusFilter = ref<'all' | StatusKey>('all');

const mapRef = useTemplateRef<InstanceType<typeof RoadworkMap>>('mapRef');

const STATUS_FILTERS: { key: 'all' | StatusKey; label: string }[] = [
    { key: 'all', label: 'Alles' },
    { key: 'active', label: 'In uitvoering' },
    { key: 'planned', label: 'Gepland' },
    { key: 'done', label: 'Afgerond' },
];

function decorate(feature: RoadworkFeatureProps) {
    return {
        raw: feature,
        status: statusView(
            feature.status,
            feature.startTs ?? null,
            feature.endTs ?? null,
        ),
        type: typeView(feature.activityType ?? null, feature.title),
        period: periodLabel(feature.startTs ?? null, feature.endTs ?? null),
    };
}

const rows = computed(() =>
    visible.value
        .map(decorate)
        .filter(
            (row) =>
                statusFilter.value === 'all' ||
                row.status.key === statusFilter.value,
        ),
);

const popup = computed(() =>
    selected.value ? decorate(selected.value) : null,
);

function submitSearch(): void {
    filters.q = searchInput.value;
    mapRef.value?.search();
}

function selectFeature(feature: RoadworkFeatureProps): void {
    selected.value = feature;
    mapRef.value?.focus(feature.id);
}
</script>

<template>
    <Head title="Werkzaamheden op de kaart | voormijndeur" />

    <div
        class="flex h-screen flex-col overflow-hidden bg-background text-on-surface"
    >
        <AppHeader full-width />

        <!-- Breadcrumb -->
        <div class="border-b border-outline-variant bg-white">
            <div
                class="mx-auto px-margin-desktop py-2.5 text-[13px] font-medium text-on-surface-variant"
            >
                <Link href="/" class="text-primary hover:underline">Home</Link>
                &nbsp;›&nbsp; Werkzaamheden &nbsp;›&nbsp;
                <span class="font-semibold text-on-surface"
                    >Amsterdam-Centrum</span
                >
            </div>
        </div>

        <main class="relative flex flex-1 overflow-hidden">
            <!-- List panel -->
            <aside
                class="flex w-90 flex-shrink-0 flex-col border-r border-outline-variant bg-white"
            >
                <div class="border-b border-outline-variant px-5 pt-4.5 pb-3.5">
                    <h1
                        class="font-display text-[20px] font-bold tracking-tight text-primary"
                    >
                        Werkzaamheden op de kaart
                    </h1>
                    <p class="mb-3 text-[12.5px] text-on-surface-variant">
                        <span class="font-bold text-primary"
                            >{{ total.toLocaleString('nl-NL') }}</span
                        >
                        in dit gebied
                    </p>
                    <form class="relative mb-3" @submit.prevent="submitSearch">
                        <input
                            v-model="searchInput"
                            type="text"
                            placeholder="Zoek op straat of plaats..."
                            class="w-full rounded-full border border-outline-variant bg-white py-2 pr-10 pl-4 text-[14px] focus:ring-2 focus:ring-primary/30 focus:outline-none"
                        >
                        <button
                            type="submit"
                            aria-label="Zoeken"
                            class="absolute top-1.5 right-3 text-on-surface-variant"
                        >
                            <i class="fa-solid fa-magnifying-glass"></i>
                        </button>
                    </form>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="filter in STATUS_FILTERS"
                            :key="filter.key"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors"
                            :class="
                                statusFilter === filter.key
                                    ? 'border-primary bg-primary text-on-primary'
                                    : 'border-outline-variant bg-white text-on-surface-variant hover:bg-surface-container-high'
                            "
                            @click="statusFilter = filter.key"
                        >
                            {{ filter.label }}
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto p-2.5">
                    <div
                        v-if="rows.length === 0"
                        class="p-stack-lg text-center text-[13px] text-on-surface-variant"
                    >
                        Geen werkzaamheden in dit gebied. Versleep of zoom de
                        kaart.
                    </div>
                    <button
                        v-for="row in rows"
                        :key="row.raw.id"
                        type="button"
                        class="mb-2 flex w-full items-start gap-3.5 rounded-[11px] border p-3.5 text-left transition-colors hover:bg-surface-container-low"
                        :class="
                            selected?.id === row.raw.id
                                ? 'border-[#C9DBF6] bg-[#F0F5FD]'
                                : 'border-transparent'
                        "
                        @click="selectFeature(row.raw)"
                    >
                        <div
                            class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-[10px] bg-surface-container-high"
                        >
                            <i
                                class="fa-solid text-[17px] text-primary"
                                :class="row.type.icon"
                            ></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div
                                class="mb-0.5 truncate font-display text-[15px] font-bold tracking-tight text-primary"
                            >
                                {{ row.raw.title }}
                            </div>
                            <div
                                class="mb-1.5 truncate text-[13px] text-on-surface-variant"
                            >
                                {{ row.raw.authority ?? 'Onbekende wegbeheerder' }}
                            </div>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10.5px] font-bold tracking-wide"
                                :style="{ background: row.status.chipBg, color: row.status.chipText }"
                            >
                                <span
                                    class="h-[5px] w-[5px] rounded-full"
                                    :style="{ background: row.status.markerColor }"
                                ></span>
                                {{ row.status.label }}
                            </span>
                        </div>
                    </button>
                </div>
            </aside>

            <!-- Map -->
            <section class="relative flex-1 bg-surface-container-high">
                <RoadworkMap
                    ref="mapRef"
                    :filters="filters"
                    :selected-id="selected?.id ?? null"
                    @select="selected = $event"
                    @total="total = $event"
                    @visible="visible = $event"
                />

                <!-- Legend -->
                <div
                    class="absolute right-6 bottom-6 z-20 rounded-xl bg-white/90 p-3.5 text-[12.5px] text-on-surface shadow-lg backdrop-blur-md"
                >
                    <div class="mb-2 text-[12px] font-bold text-primary">
                        Status
                    </div>
                    <div class="mb-1.5 flex items-center gap-2">
                        <span
                            class="h-2.5 w-2.5 rounded-full bg-[#FFC400]"
                        ></span>In uitvoering
                    </div>
                    <div class="mb-1.5 flex items-center gap-2">
                        <span
                            class="h-2.5 w-2.5 rounded-full bg-[#2F6BD8]"
                        ></span>Gepland
                    </div>
                    <div class="mb-2.5 flex items-center gap-2">
                        <span
                            class="h-2.5 w-2.5 rounded-full bg-[#1F8A5B]"
                        ></span>Afgerond
                    </div>
                    <div
                        class="flex flex-col gap-1.5 border-t border-outline-variant pt-2.5"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="h-1 w-6 rounded-full bg-primary-container"
                            ></span>Werkzaamheden
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="h-1 w-6 rounded-full bg-error"
                            ></span>Afsluiting / werkvak
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                class="w-6 border-t-2 border-dashed border-secondary"
                            ></span>Omleiding
                        </div>
                    </div>
                </div>

                <!-- Popup -->
                <div
                    v-if="popup"
                    class="absolute bottom-6 left-1/2 z-40 w-[410px] max-w-[calc(100%-3rem)] -translate-x-1/2 overflow-hidden rounded-[15px] bg-white shadow-2xl"
                >
                    <div
                        class="h-1.5"
                        :style="{ background: popup.status.markerColor }"
                    ></div>
                    <div class="px-5 py-4.5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3.5">
                                <div
                                    class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-[11px] bg-surface-container-high"
                                >
                                    <i
                                        class="fa-solid text-[19px] text-primary"
                                        :class="popup.type.icon"
                                    ></i>
                                </div>
                                <div>
                                    <span
                                        class="mb-1.5 inline-flex items-center gap-1.5 rounded-full px-2 py-1 text-[10.5px] font-bold tracking-wide"
                                        :style="{ background: popup.status.chipBg, color: popup.status.chipText }"
                                    >
                                        <span
                                            class="h-[5px] w-[5px] rounded-full"
                                            :style="{ background: popup.status.markerColor }"
                                        ></span>
                                        {{ popup.status.label }}
                                    </span>
                                    <div
                                        class="font-display text-[17px] font-bold tracking-tight text-primary"
                                    >
                                        {{ popup.raw.title }}
                                    </div>
                                    <div
                                        class="mt-0.5 text-[13px] text-on-surface-variant"
                                    >
                                        {{ popup.raw.authority ?? 'Onbekende wegbeheerder' }}
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                aria-label="Sluiten"
                                class="px-1 text-[20px] leading-none text-outline"
                                @click="selected = null"
                            >
                                ×
                            </button>
                        </div>
                        <div
                            class="my-4 flex gap-5 border-t border-outline-variant pt-3.5"
                        >
                            <div>
                                <div
                                    class="mb-0.5 text-[11px] font-semibold tracking-wide text-outline uppercase"
                                >
                                    Periode
                                </div>
                                <div
                                    class="text-[13.5px] font-bold text-on-surface"
                                >
                                    {{ popup.period }}
                                </div>
                            </div>
                            <div>
                                <div
                                    class="mb-0.5 text-[11px] font-semibold tracking-wide text-outline uppercase"
                                >
                                    Soort
                                </div>
                                <div
                                    class="text-[13.5px] font-bold text-on-surface"
                                >
                                    {{ popup.type.label }}
                                </div>
                            </div>
                        </div>
                        <Link
                            v-if="popup.raw.slug"
                            :href="`/${popup.raw.slug}`"
                            class="block w-full rounded-[9px] bg-primary py-3 text-center text-[14px] font-bold text-on-primary"
                        >
                            Bekijk details →
                        </Link>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
