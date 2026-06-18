<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import maplibregl from 'maplibre-gl';
import { layers, namedFlavor } from '@protomaps/basemaps';
import { Protocol } from 'pmtiles';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Link } from '@inertiajs/vue3';
import MaterialIcon from '@/components/MaterialIcon.vue';

const mapContainer = useTemplateRef<HTMLDivElement>('mapContainer');
const map = ref<maplibregl.Map>();

onMounted(() => {
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    map.value = new maplibregl.Map({
        container: mapContainer.value!,
        center: [4.9041, 52.3676], // Amsterdam
        zoom: 11,
        attributionControl: { compact: true },
        style: {
            version: 8,
            glyphs: 'https://protomaps.github.io/basemaps-assets/fonts/{fontstack}/{range}.pbf',
            sprite: 'https://protomaps.github.io/basemaps-assets/sprites/v4/light',
            sources: {
                protomaps: {
                    type: 'vector',
                    url: 'pmtiles:///tiles/basemap-nl.pmtiles',
                    attribution:
                        '<a href="https://protomaps.com">Protomaps</a> © <a href="https://openstreetmap.org">OpenStreetMap</a>',
                },
            },
            layers: layers('protomaps', namedFlavor('light'), { lang: 'nl' }),
        },
    });
});

onBeforeUnmount(() => {
    map.value?.remove();
    maplibregl.removeProtocol('pmtiles');
});

function zoomIn(): void {
    map.value?.zoomIn();
}

function zoomOut(): void {
    map.value?.zoomOut();
}
</script>

<template>
    <div
        class="relative mt-stack-xxl h-96 overflow-hidden rounded-xl border border-outline-variant bg-surface-container-high"
    >
        <div
            ref="mapContainer"
            class="z-0"
            style="position: absolute; inset: 0"
        ></div>
        <div
            class="pointer-events-none absolute inset-0 bg-gradient-to-t from-primary/40 to-transparent"
        ></div>
        <div
            class="absolute right-stack-lg bottom-stack-lg left-stack-lg flex items-end justify-between"
        >
            <div
                class="max-w-[24rem] rounded-xl border border-outline-variant bg-white/90 p-stack-lg shadow-[0_12px_32px_rgba(0,32,91,0.08)] backdrop-blur-md"
            >
                <h3 class="font-headline-md text-headline-md text-primary">
                    Bekijk op de kaart
                </h3>
                <p class="mt-2 text-body-md text-on-surface-variant">
                    Navigeer door uw buurt en zie in één oogopslag waar gewerkt
                    wordt aan de weg.
                </p>
                <Link
                    href="/kaart"
                    class="mt-4 inline-block rounded-lg bg-primary px-stack-lg py-2 font-label-md text-on-primary"
                >
                    Interactieve Kaart Openen
                </Link>
            </div>
            <div class="flex flex-col gap-2">
                <button
                    type="button"
                    aria-label="Inzoomen"
                    class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white shadow-[0_12px_32px_rgba(0,32,91,0.08)] transition-colors hover:bg-surface-container-high"
                    @click="zoomIn"
                >
                    <MaterialIcon name="add" class="text-primary" />
                </button>
                <button
                    type="button"
                    aria-label="Uitzoomen"
                    class="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full bg-white shadow-[0_12px_32px_rgba(0,32,91,0.08)] transition-colors hover:bg-surface-container-high"
                    @click="zoomOut"
                >
                    <MaterialIcon name="remove" class="text-primary" />
                </button>
            </div>
        </div>
    </div>
</template>
