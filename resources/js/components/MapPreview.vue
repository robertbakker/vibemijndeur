<script setup lang="ts">
import maplibregl from 'maplibre-gl';
import { Protocol } from 'pmtiles';
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Link } from '@inertiajs/vue3';
import MaterialIcon from '@/components/MaterialIcon.vue';
import { buildMapStyle } from '@/lib/mapStyle';

const mapContainer = useTemplateRef<HTMLDivElement>('mapContainer');
const map = ref<maplibregl.Map>();

onMounted(() => {
    if (!mapContainer.value) {
        return;
    }
    const container = mapContainer.value;
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    map.value = new maplibregl.Map({
        container,
        center: [4.9041, 52.3676], // Amsterdam
        zoom: 11,
        attributionControl: { compact: true },
        style: buildMapStyle(),
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
