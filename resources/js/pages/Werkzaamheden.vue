<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

const props = defineProps<{
    results: App.Data.RoadworkCard[];
    facets: Record<string, App.Data.FacetGroup>;
    filters: { q: string; sort: string };
    total: number;
    page: number;
    hasMore: boolean;
}>();

const qInput = ref(props.filters.q);
const sortSel = ref(props.filters.sort);
const layout = ref<'list' | 'grid'>('list');

const groupOrder = ['status', 'gemeente', 'provincie', 'type', 'authority'];
const facetGroups = computed(() =>
    groupOrder.map((key) => props.facets[key]).filter(Boolean),
);

const hasActive = computed(
    () =>
        qInput.value.trim().length > 0 ||
        facetGroups.value.some((group) =>
            group.options.some((option) => option.checked),
        ),
);

const countWord = computed(() =>
    props.total === 1 ? 'werkzaamheid' : 'werkzaamheden',
);

/** Current pretty path; query refinements (q/sort/page) layer on top of it. */
function currentPath(): string {
    return window.location.pathname;
}

function query(
    extra: Record<string, string | number> = {},
): Record<string, string | number> {
    const payload: Record<string, string | number> = { ...extra };
    const q = qInput.value.trim();
    if (q) {
        payload.q = q;
    }
    if (sortSel.value !== 'start') {
        payload.sort = sortSel.value;
    }
    return payload;
}

/** Navigate to an option's precomputed clean URL, keeping q/sort. */
function go(url: string): void {
    router.get(url, query(), { preserveScroll: true, reset: ['results'] });
}

function applyQuery(): void {
    router.get(currentPath(), query(), {
        preserveState: true,
        preserveScroll: true,
        reset: ['results'],
    });
}

function loadMore(): void {
    router.get(currentPath(), query({ page: props.page + 1 }), {
        preserveState: true,
        preserveScroll: true,
        only: ['results', 'page', 'hasMore'],
    });
}

function clearAll(): void {
    qInput.value = '';
    router.get('/werkzaamheden', query(), {
        preserveScroll: true,
        reset: ['results'],
    });
}

const chips = computed(() => {
    const list: { label: string; url: string }[] = [];
    for (const group of facetGroups.value) {
        for (const option of group.options) {
            if (option.checked) {
                list.push({ label: option.label, url: option.url });
            }
        }
    }
    return list;
});
</script>

<template>
    <Head title="Werkzaamheden in de buurt | voormijndeur" />

    <!-- Breadcrumb -->
    <div class="border-b border-outline-variant bg-white">
        <div
            class="mx-auto max-w-7xl px-margin-desktop py-2.5 text-[13px] font-medium text-on-surface-variant"
        >
            <Link href="/" class="text-primary hover:underline">Home</Link>
            &nbsp;›&nbsp;
            <span class="font-semibold text-on-surface">Werkzaamheden</span>
        </div>
    </div>

    <!-- Search band -->
    <section class="border-b-[3px] border-secondary-container bg-primary">
        <div class="mx-auto max-w-7xl px-margin-desktop pt-6 pb-7">
            <h1
                class="mb-1 font-display text-[28px] font-extrabold tracking-tight text-on-primary"
            >
                Werkzaamheden in de buurt
            </h1>
            <p class="mb-4.5 text-[15px] text-on-primary-container">
                Filter op status, soort werk en uitvoerder om te vinden wat uw
                straat raakt.
            </p>
            <form class="flex flex-wrap gap-2.5" @submit.prevent="applyQuery">
                <div
                    class="flex min-w-[280px] flex-1 gap-2.5 rounded-[11px] bg-white p-1.5 shadow-[0_10px_28px_rgba(0,0,0,0.2)]"
                >
                    <div class="flex items-center pl-2.5 text-outline">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <input
                        v-model="qInput"
                        type="text"
                        placeholder="Zoek op straat of soort werk"
                        class="flex-1 bg-transparent px-1.5 py-2.5 text-[15px] text-on-surface outline-none"
                    >
                </div>
                <button
                    type="submit"
                    class="rounded-[10px] bg-secondary-container px-6 text-[15px] font-bold text-on-secondary-container"
                >
                    Zoeken
                </button>
            </form>
        </div>
    </section>

    <!-- Body -->
    <div
        class="mx-auto grid max-w-7xl items-start gap-6 px-margin-desktop py-6 md:grid-cols-[268px_1fr]"
    >
        <!-- Facet sidebar -->
        <aside
            class="overflow-hidden rounded-[14px] bg-white shadow-[0_1px_3px_rgba(10,30,60,0.08)] md:sticky md:top-[88px]"
        >
            <div
                class="flex items-center justify-between border-b border-outline-variant px-4.5 py-4"
            >
                <div class="text-[15px] font-bold text-primary">
                    <i class="fa-solid fa-sliders mr-2 text-[13px]"></i>Filters
                </div>
                <button
                    v-if="hasActive"
                    type="button"
                    class="text-[12.5px] font-semibold text-[#2F6BD8] hover:underline"
                    @click="clearAll"
                >
                    Wis alles
                </button>
            </div>

            <div
                v-for="group in facetGroups"
                :key="group.key"
                class="border-b border-outline-variant px-4.5 py-4 last:border-b-0"
            >
                <div
                    class="mb-3 text-[12px] font-bold tracking-wide text-on-surface-variant uppercase"
                >
                    {{ group.title }}
                </div>
                <div class="flex flex-col gap-2.5">
                    <!-- biome-ignore lint/a11y/noLabelWithoutControl: the checkbox input is nested directly inside the label -->
                    <label
                        v-for="option in group.options"
                        :key="option.key"
                        class="flex cursor-pointer items-center gap-2.5 transition-opacity"
                        :class="option.count === 0 && !option.checked ? 'opacity-40' : 'opacity-100'"
                    >
                        <input
                            type="checkbox"
                            :checked="option.checked"
                            class="h-4 w-4 flex-shrink-0 cursor-pointer accent-primary"
                            @change="go(option.url)"
                        >
                        <span
                            v-if="option.dot"
                            class="h-2 w-2 flex-shrink-0 rounded-full"
                            :style="{ background: option.dot }"
                        ></span>
                        <span
                            class="flex-1 text-[14px] font-medium text-[#2A3442]"
                            >{{ option.label }}</span
                        >
                        <span class="text-[12.5px] font-semibold text-outline"
                            >{{ option.count }}</span
                        >
                    </label>
                    <p
                        v-if="group.options.length === 0"
                        class="text-[13px] text-outline"
                    >
                        Geen opties
                    </p>
                </div>
            </div>
        </aside>

        <!-- Results -->
        <section>
            <div
                class="mb-3.5 flex flex-wrap items-center justify-between gap-3.5"
            >
                <div class="text-[14px] font-semibold text-on-surface-variant">
                    <strong class="text-[16px] text-primary"
                        >{{ total.toLocaleString('nl-NL') }}</strong
                    >
                    {{ countWord }}
                    gevonden
                </div>
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex items-center gap-2 rounded-[9px] border border-outline-variant bg-white px-3 py-2"
                    >
                        <span class="text-[13px] font-semibold text-outline"
                            >Sorteer</span
                        >
                        <select
                            v-model="sortSel"
                            class="cursor-pointer bg-transparent text-[13.5px] font-bold text-primary outline-none"
                            @change="applyQuery"
                        >
                            <option value="start">Startdatum</option>
                            <option value="status">Status</option>
                        </select>
                    </div>
                    <div
                        class="flex overflow-hidden rounded-[9px] border border-outline-variant bg-white"
                    >
                        <button
                            type="button"
                            aria-label="Lijst"
                            class="px-3 py-2 text-[14px]"
                            :class="layout === 'list' ? 'bg-primary text-on-primary' : 'text-outline'"
                            @click="layout = 'list'"
                        >
                            <i class="fa-solid fa-list"></i>
                        </button>
                        <button
                            type="button"
                            aria-label="Raster"
                            class="border-l border-outline-variant px-3 py-2 text-[14px]"
                            :class="layout === 'grid' ? 'bg-primary text-on-primary' : 'text-outline'"
                            @click="layout = 'grid'"
                        >
                            <i class="fa-solid fa-table-cells-large"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Active chips -->
            <div v-if="chips.length" class="mb-4 flex flex-wrap gap-2">
                <button
                    v-for="chip in chips"
                    :key="chip.label"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border border-[#C9DBF6] bg-white py-1.5 pr-2.5 pl-3 text-[12.5px] font-semibold text-primary hover:bg-[#F0F5FD]"
                    @click="go(chip.url)"
                >
                    {{ chip.label }}
                    <span class="text-[14px] leading-none text-[#7E94B8]"
                        >×</span
                    >
                </button>
            </div>

            <!-- Results grid -->
            <div
                v-if="results.length"
                class="grid gap-3.5"
                :class="layout === 'grid' ? 'grid-cols-1 lg:grid-cols-2' : 'grid-cols-1'"
            >
                <Link
                    v-for="work in results"
                    :key="work.id"
                    :href="work.slug ? `/${work.slug}` : '/kaart'"
                    class="flex items-start gap-4 rounded-[14px] bg-white p-4.5 shadow-[0_1px_3px_rgba(10,30,60,0.08)] transition-shadow hover:shadow-[0_6px_20px_rgba(10,30,60,0.14)]"
                >
                    <div
                        class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-[11px] bg-surface-container-high"
                    >
                        <i
                            class="fa-solid text-[20px] text-primary"
                            :class="work.icon"
                        ></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="mb-1.5 flex flex-wrap items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide"
                                :style="{ background: work.chipBg, color: work.chipText }"
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :style="{ background: work.markerColor }"
                                ></span>
                                {{ work.statusLabel }}
                            </span>
                            <span
                                class="inline-flex items-center rounded-full bg-surface-container-high px-2.5 py-1 text-[11px] font-semibold text-on-surface-variant"
                            >
                                {{ work.typeLabel }}
                            </span>
                        </div>
                        <div
                            class="mb-1 font-display text-[17px] font-bold tracking-tight text-primary"
                        >
                            {{ work.title }}
                        </div>
                        <div class="mb-2.5 text-[14px] text-on-surface-variant">
                            {{ work.locationLabel }}
                        </div>
                        <div
                            class="flex flex-wrap gap-4.5 border-t border-outline-variant pt-2.5 text-[12.5px] text-on-surface-variant"
                        >
                            <span
                                ><i
                                    class="fa-regular fa-calendar mr-1.5 text-outline"
                                ></i>{{ work.period }}</span
                            >
                        </div>
                    </div>
                </Link>
            </div>

            <!-- Empty state -->
            <div
                v-else
                class="rounded-[14px] bg-white px-6 py-12 text-center shadow-[0_1px_3px_rgba(10,30,60,0.08)]"
            >
                <div class="mb-3 text-[34px] text-[#C5CEDA]">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <div class="mb-1.5 text-[17px] font-bold text-primary">
                    Geen werkzaamheden gevonden
                </div>
                <div class="mb-4.5 text-[14px] text-on-surface-variant">
                    Probeer een filter te verwijderen of een andere zoekterm.
                </div>
                <button
                    type="button"
                    class="rounded-[9px] bg-primary px-5 py-2.5 text-[14px] font-bold text-on-primary"
                    @click="clearAll"
                >
                    Wis alle filters
                </button>
            </div>

            <!-- Load more -->
            <div v-if="hasMore" class="mt-5 flex justify-center">
                <button
                    type="button"
                    class="rounded-[10px] border border-outline-variant bg-white px-6 py-3 text-[14px] font-bold text-primary hover:bg-surface-container-low"
                    @click="loadMore"
                >
                    Meer laden
                </button>
            </div>
        </section>
    </div>
</template>
