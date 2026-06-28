<script setup lang="ts">
import maplibregl from 'maplibre-gl';
import { Protocol } from 'pmtiles';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';
import MaterialIcon from '@/components/MaterialIcon.vue';
import { buildMapStyle } from '@/lib/mapStyle';

const props = defineProps<{
    roadworkId: number;
    locationLabel: string;
    latitude: number | null;
    longitude: number | null;
}>();

const mapContainer = useTemplateRef<HTMLDivElement>('mapContainer');
const map = ref<maplibregl.Map>();

const hasLocation = computed(
    () => props.latitude !== null && props.longitude !== null,
);
const coordinates = computed(() =>
    props.latitude !== null && props.longitude !== null
        ? `${props.latitude.toFixed(5)}, ${props.longitude.toFixed(5)}`
        : null,
);

const PMTILES_BOUNDS: [[number, number], [number, number]] = [
    [2.8, 50.4],
    [7.8, 54.0],
];

// Marker icons + colours match the main /kaart map (ts/shared/src/map): a blue
// gradient arrow along detours, a dark-red cross along restrictions.
const ARROW_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><defs><linearGradient id="ag" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#0961ed"/><stop offset="1" stop-color="#00298a"/></linearGradient></defs><path d="M35 20 L10 6 L18 20 L10 34 Z" fill="url(#ag)" stroke="#001a4d" stroke-width="2.5" stroke-linejoin="round"/></svg>';
const CROSS_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><path d="M11 11 L29 29 M29 11 L11 29" fill="none" stroke="#2e0a0a" stroke-width="10" stroke-linecap="round"/><path d="M11 11 L29 29 M29 11 L11 29" fill="none" stroke="#861717" stroke-width="6" stroke-linecap="round"/></svg>';

function loadSvgImage(svg: string, size: number): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image(size, size);
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    });
}

// Grow a bounds to cover every coordinate in a geometry (point/line/polygon).
function extendBounds(bounds: maplibregl.LngLatBounds, coords: unknown): void {
    if (!Array.isArray(coords)) {
        return;
    }
    if (typeof coords[0] === 'number') {
        bounds.extend(coords as [number, number]);
        return;
    }
    coords.forEach((c) => {
        extendBounds(bounds, c);
    });
}

onMounted(async () => {
    const lon = props.longitude;
    const lat = props.latitude;
    if (
        !hasLocation.value ||
        lon === null ||
        lat === null ||
        !mapContainer.value
    ) {
        return;
    }
    const container = mapContainer.value;

    const center: [number, number] = [lon, lat];
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const instance = new maplibregl.Map({
        container,
        center,
        zoom: 14,
        maxBounds: PMTILES_BOUNDS,
        maxZoom: 15,
        // Don't hijack page scroll inside the detail card.
        scrollZoom: false,
        canvasContextAttributes: { antialias: true },
        attributionControl: { compact: true },
        style: buildMapStyle(),
    });
    map.value = instance;

    // Location pin at the representative point.
    new maplibregl.Marker({ color: '#003082' })
        .setLngLat(center)
        .addTo(instance);

    instance.on('load', async () => {
        const [arrowImage, crossImage] = await Promise.all([
            loadSvgImage(ARROW_SVG, 40),
            loadSvgImage(CROSS_SVG, 40),
        ]);
        instance.addImage('detour-marker', arrowImage, { pixelRatio: 2 });
        instance.addImage('restriction-marker', crossImage, { pixelRatio: 2 });

        const response = await fetch(
            `/api/roadworks/${props.roadworkId}/geometry`,
            {
                headers: { Accept: 'application/json' },
            },
        );
        const geometry: GeoJSON.FeatureCollection = await response.json();

        instance.addSource('geom', { type: 'geojson', data: geometry });

        // Single roadwork detail: always shown in full colour (no greyed state).
        instance.addLayer({
            id: 'geom-outline',
            type: 'line',
            source: 'geom',
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-width': 10,
                'line-color': [
                    'match',
                    ['get', 'role'],
                    'restriction',
                    '#861717',
                    'detour',
                    '#1F449C',
                    '#001a4d',
                ],
            },
        });
        instance.addLayer({
            id: 'geom-line',
            type: 'line',
            source: 'geom',
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-width': 5,
                'line-opacity': 0.9,
                'line-color': [
                    'match',
                    ['get', 'role'],
                    'restriction',
                    '#F05039',
                    'detour',
                    '#1b5dff',
                    '#003082',
                ],
            },
        });
        instance.addLayer({
            id: 'detour-markers',
            type: 'symbol',
            source: 'geom',
            filter: ['==', ['get', 'role'], 'detour'],
            layout: {
                'symbol-placement': 'line',
                'symbol-spacing': 50,
                'icon-image': 'detour-marker',
                'icon-size': 1.15,
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': false,
            },
        });
        instance.addLayer({
            id: 'restriction-markers',
            type: 'symbol',
            source: 'geom',
            filter: ['==', ['get', 'role'], 'restriction'],
            layout: {
                'symbol-placement': 'line',
                'symbol-spacing': 70,
                'icon-image': 'restriction-marker',
                'icon-size': 1.15,
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': false,
            },
        });

        // Frame the geometry if it covers more than the single point.
        const bounds = new maplibregl.LngLatBounds();
        geometry.features.forEach((f) => {
            extendBounds(
                bounds,
                (
                    f.geometry as GeoJSON.GeometryObject as {
                        coordinates?: unknown;
                    }
                ).coordinates,
            );
        });
        if (!bounds.isEmpty()) {
            instance.fitBounds(bounds, {
                padding: 48,
                maxZoom: 15,
                duration: 0,
            });
        }
    });
});

onBeforeUnmount(() => {
    map.value?.remove();
    maplibregl.removeProtocol('pmtiles');
});
</script>

<template>
    <div
        class="border-outline-variant overflow-hidden rounded-2xl border bg-white shadow-sm"
    >
        <div
            class="p-stack-md border-outline-variant bg-surface-container-low flex items-center justify-between border-b"
        >
            <h3 class="font-label-md text-label-md text-primary font-bold">
                Projectlocatie
            </h3>
            <span
                class="border-outline-variant text-primary rounded border bg-white px-2 py-1 text-[10px] font-bold"
                >LIVE KAART</span
            >
        </div>

        <div class="relative h-64">
            <div
                v-if="hasLocation"
                ref="mapContainer"
                class="h-full w-full"
            ></div>
            <div
                v-else
                class="bg-surface-container-low text-on-surface-variant flex h-full w-full flex-col items-center justify-center gap-2"
            >
                <MaterialIcon name="location_off" class="text-2xl" />
                <span class="font-caption text-caption"
                    >Locatie niet beschikbaar</span
                >
            </div>
        </div>

        <div class="p-stack-md flex items-center justify-between">
            <span
                class="font-caption text-caption text-on-surface-variant flex items-center gap-1"
            >
                <MaterialIcon name="location_on" class="text-xs" />
                {{ coordinates ?? locationLabel }}
            </span>
            <span class="text-primary font-label-md text-caption font-bold"
                >{{ locationLabel }}</span
            >
        </div>
    </div>
</template>
