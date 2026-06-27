<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { reactive, ref, useTemplateRef } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import MaterialIcon from '@/components/MaterialIcon.vue';
import RoadworkMap, {
    type MapFilters,
    type RoadworkFeatureProps,
} from '@/components/RoadworkMap.vue';

const filters = reactive<MapFilters>({ q: '', kind: [], status: [] });
const facets = ref<Record<string, Record<string, number>>>({});
const total = ref(0);
const selected = ref<RoadworkFeatureProps | null>(null);
const searchInput = ref('');

const mapRef = useTemplateRef<InstanceType<typeof RoadworkMap>>('mapRef');

const KIND_LABELS: Record<string, string> = {
    WORK: 'Werkzaamheden',
    EVENT: 'Evenementen',
};

const SEVERITY_LABELS: Record<string, string> = {
    high: 'In Uitvoering',
    medium: 'Gepland',
    low: 'Informatie',
};

function submitSearch(): void {
    filters.q = searchInput.value;
    mapRef.value?.search();
}

function toggleKind(kind: string): void {
    const index = filters.kind.indexOf(kind);
    if (index === -1) {
        filters.kind.push(kind);
    } else {
        filters.kind.splice(index, 1);
    }
}

function severityColor(severity: string | null): string {
    return severity === 'high'
        ? 'bg-error'
        : severity === 'medium'
          ? 'bg-secondary'
          : 'bg-primary-container';
}
</script>

<template>
    <Head title="Interactieve Kaart | VoorMijnDeur" />

    <div
        class="bg-background text-on-surface flex h-screen flex-col overflow-hidden"
    >
        <AppHeader full-width />

        <main class="relative flex flex-1 overflow-hidden">
            <!-- Filters sidebar -->
            <aside
                class="bg-surface-container-low border-outline-variant z-40 hidden h-full w-64 flex-col border-r md:flex"
            >
                <div class="p-stack-md border-outline-variant border-b">
                    <h2 class="font-headline-md text-headline-md text-primary">
                        Filters
                    </h2>
                    <p
                        class="font-label-md text-label-md text-on-surface-variant"
                    >
                        Verfijn resultaten voor uw buurt
                    </p>
                    <form
                        class="mt-stack-md relative"
                        @submit.prevent="submitSearch"
                    >
                        <input
                            v-model="searchInput"
                            type="text"
                            placeholder="Zoek op straat of plaats..."
                            class="bg-white border-outline-variant text-label-md focus:ring-primary-container w-full rounded-full border py-2 pr-10 pl-4 focus:ring-2 focus:outline-none"
                        >
                        <button
                            type="submit"
                            aria-label="Zoeken"
                            class="text-on-surface-variant absolute top-1.5 right-3"
                        >
                            <MaterialIcon name="search" />
                        </button>
                    </form>
                </div>

                <nav
                    class="p-stack-md gap-stack-sm flex flex-1 flex-col overflow-y-auto"
                >
                    <p
                        class="font-label-md text-label-md text-on-surface-variant px-1 uppercase"
                    >
                        Categorie
                    </p>
                    <button
                        v-for="(label, kind) in KIND_LABELS"
                        :key="kind"
                        type="button"
                        class="flex items-center gap-3 rounded-lg p-3 transition-colors"
                        :class="
                            filters.kind.includes(kind)
                                ? 'bg-primary-fixed text-on-primary-fixed'
                                : 'text-on-surface-variant hover:bg-surface-container-high'
                        "
                        @click="toggleKind(kind)"
                    >
                        <MaterialIcon
                            :name="kind === 'EVENT' ? 'celebration' : 'construction'"
                        />
                        <span class="font-label-md text-label-md"
                            >{{ label }}</span
                        >
                        <span
                            v-if="facets.kind?.[kind]"
                            class="bg-primary text-on-primary ml-auto rounded-full px-1.5 py-0.5 text-[10px]"
                            >{{ facets.kind[kind] }}</span
                        >
                    </button>

                    <div
                        class="mt-stack-xl p-stack-md border-outline-variant rounded-xl border bg-white shadow-sm"
                    >
                        <h3
                            class="font-label-md text-label-md text-primary mb-stack-md tracking-wider uppercase"
                        >
                            In Beeld
                        </h3>
                        <p class="font-body-md text-body-md text-on-surface">
                            <span class="text-primary font-bold"
                                >{{ total.toLocaleString('nl-NL') }}</span
                            >
                            werkzaamheden in dit gebied.
                        </p>
                        <p
                            class="font-caption text-caption text-on-surface-variant mt-1"
                        >
                            Versleep of zoom de kaart om het gebied aan te
                            passen.
                        </p>
                    </div>
                </nav>
            </aside>

            <!-- Map -->
            <section class="bg-surface-container-high relative flex-1">
                <RoadworkMap
                    ref="mapRef"
                    :filters="filters"
                    :selected-id="selected?.id ?? null"
                    @select="selected = $event"
                    @facets="facets = $event"
                    @total="total = $event"
                />

                <!-- Map legend for the geometry line styles. -->
                <div
                    class="border-outline-variant/40 absolute bottom-6 left-6 z-20 rounded-xl border bg-surface/85 p-stack-md shadow-lg backdrop-blur-md"
                >
                    <p
                        class="font-label-md text-label-md text-primary mb-stack-sm uppercase"
                    >
                        Op de kaart
                    </p>
                    <ul class="gap-stack-sm flex flex-col">
                        <li class="gap-stack-sm flex items-center">
                            <span
                                class="bg-primary-container h-1 w-6 rounded-full"
                            ></span>
                            <span
                                class="font-caption text-caption text-on-surface-variant"
                                >Werkzaamheden</span
                            >
                        </li>
                        <li class="gap-stack-sm flex items-center">
                            <span class="bg-error h-1 w-6 rounded-full"></span>
                            <span
                                class="font-caption text-caption text-on-surface-variant"
                                >Afsluiting / werkvak</span
                            >
                        </li>
                        <li class="gap-stack-sm flex items-center">
                            <span
                                class="border-secondary w-6 border-t-2 border-dashed"
                            ></span>
                            <span
                                class="font-caption text-caption text-on-surface-variant"
                                >Omleiding</span
                            >
                        </li>
                    </ul>
                </div>

                <!-- Detail panel -->
                <div
                    v-if="selected"
                    class="border-outline-variant absolute top-6 bottom-6 right-6 z-20 flex w-96 max-w-[calc(100%-3rem)] flex-col rounded-2xl border bg-surface/95 shadow-2xl backdrop-blur-md"
                >
                    <div
                        class="p-stack-lg border-outline-variant flex items-start justify-between border-b"
                    >
                        <div>
                            <span
                                class="bg-primary-fixed text-on-primary-fixed rounded px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase"
                                >{{ SEVERITY_LABELS[selected.severity ?? ''] ?? 'Werkzaamheden' }}</span
                            >
                            <h3
                                class="font-headline-md text-headline-md text-primary mt-stack-xs"
                            >
                                {{ selected.title }}
                            </h3>
                        </div>
                        <button
                            type="button"
                            aria-label="Sluiten"
                            class="text-on-surface-variant hover:bg-surface-container-high rounded-full p-1"
                            @click="selected = null"
                        >
                            <MaterialIcon name="close" />
                        </button>
                    </div>

                    <div
                        class="p-stack-lg flex-1 space-y-stack-md overflow-y-auto"
                    >
                        <div
                            class="border-outline-variant flex justify-between border-b pb-2"
                        >
                            <span
                                class="font-label-md text-label-md text-on-surface-variant"
                                >Wegbeheerder</span
                            >
                            <span
                                class="font-label-md text-label-md text-primary"
                                >{{ selected.authority ?? '—' }}</span
                            >
                        </div>
                        <div
                            class="border-outline-variant flex justify-between border-b pb-2"
                        >
                            <span
                                class="font-label-md text-label-md text-on-surface-variant"
                                >Status</span
                            >
                            <span
                                class="text-secondary font-label-md flex items-center gap-1 font-bold"
                            >
                                <span
                                    class="h-2 w-2 rounded-full"
                                    :class="severityColor(selected.severity)"
                                ></span>
                                {{ SEVERITY_LABELS[selected.severity ?? ''] ?? 'Onbekend' }}
                            </span>
                        </div>
                    </div>

                    <div class="p-stack-lg border-outline-variant border-t">
                        <Link
                            :href="`/${selected.slug}`"
                            class="bg-primary text-on-primary font-label-md hover:bg-primary-container flex w-full items-center justify-center gap-2 rounded-xl py-3 shadow-md transition-all"
                        >
                            <MaterialIcon name="open_in_new" />
                            Bekijk project
                        </Link>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
