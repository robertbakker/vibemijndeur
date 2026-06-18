<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import maplibregl from 'maplibre-gl';
import { layers, namedFlavor } from '@protomaps/basemaps';
import { Protocol } from 'pmtiles';
import 'maplibre-gl/dist/maplibre-gl.css';

export interface RoadworkFeatureProps {
    id: number;
    title: string;
    kind: string | null;
    severity: string | null;
    status: string | null;
    authority: string | null;
}

export interface MapFilters {
    q: string;
    kind: string[];
    status: string[];
}

const props = defineProps<{ filters: MapFilters; selectedId: number | null }>();

const emit = defineEmits<{
    (event: 'select', feature: RoadworkFeatureProps): void;
    (event: 'facets', facets: Record<string, Record<string, number>>): void;
    (event: 'total', total: number): void;
}>();

const mapContainer = useTemplateRef<HTMLDivElement>('mapContainer');
const map = ref<maplibregl.Map>();
const SOURCE = 'roadworks';
const GEOM_SOURCE = 'roadwork-geom';
const EMPTY_GEOJSON: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

// Extent of the bundled basemap (scripts/basemap-pmtiles.sh): west,south,east,north
// and its maxzoom. Panning/zooming is clamped to this so users never reach the
// empty area outside the Netherlands tiles or overzoom past available detail.
const PMTILES_BOUNDS: maplibregl.LngLatBoundsLike = [
    [2.8, 50.4],
    [7.8, 54.0],
];
const PMTILES_MAX_ZOOM = 15;

// From this zoom the detours/restrictions line geometry is requested and drawn.
// Below it only the (cheap) marker points load, so a zoomed-out view never pulls
// the heavy geometry for thousands of roadworks.
const GEOMETRY_MIN_ZOOM = 13;

interface RoadworksResponse {
    features: GeoJSON.Feature[];
    facets: Record<string, Record<string, number>>;
    total: number;
    geometry?: GeoJSON.FeatureCollection;
}

// Whole-country bounding box (matches the bundled pmtiles extent).
const COUNTRY_BBOX = `${PMTILES_BOUNDS[0][0]},${PMTILES_BOUNDS[0][1]},${PMTILES_BOUNDS[1][0]},${PMTILES_BOUNDS[1][1]}`;

function filterParams(): URLSearchParams {
    const params = new URLSearchParams();
    if (props.filters.q) {
        params.set('q', props.filters.q);
    }
    props.filters.kind.forEach((value) => params.append('kind[]', value));
    props.filters.status.forEach((value) => params.append('status[]', value));
    return params;
}

async function apiFetch(params: URLSearchParams): Promise<RoadworksResponse> {
    const response = await fetch(`/api/roadworks?${params.toString()}`, {
        headers: { Accept: 'application/json' },
    });
    return response.json();
}

// Load every roadwork point once (country-wide). Maplibre culls to the viewport,
// so panning/zooming never refetches the full set — only filter changes do.
async function loadPoints(): Promise<void> {
    const source = map.value?.getSource(SOURCE) as maplibregl.GeoJSONSource | undefined;
    if (!source) {
        return;
    }
    const params = filterParams();
    params.set('bbox', COUNTRY_BBOX);
    const data = await apiFetch(params);
    source.setData({ type: 'FeatureCollection', features: data.features });
}

// Snap a bounding box outward to a coarse grid (~0.02°, roughly 1–2 km) after
// padding it. Small pans/zooms then resolve to the same box, so we can skip
// refetching, and the padding means the already-loaded data still covers the
// new viewport edges in between fetches.
const BBOX_GRID = 0.02;
const BBOX_MARGIN = 0.35;

function snappedBbox(b: maplibregl.LngLatBounds): string {
    const padX = (b.getEast() - b.getWest()) * BBOX_MARGIN;
    const padY = (b.getNorth() - b.getSouth()) * BBOX_MARGIN;
    const down = (v: number) => Math.floor((v - BBOX_GRID) / BBOX_GRID) * BBOX_GRID;
    const up = (v: number) => Math.ceil((v + BBOX_GRID) / BBOX_GRID) * BBOX_GRID;
    return [
        down(b.getWest() - padX),
        down(b.getSouth() - padY),
        up(b.getEast() + padX),
        up(b.getNorth() + padY),
    ]
        .map((v) => v.toFixed(2))
        .join(',');
}

let lastViewportKey = '';

// Per-viewport: the "in this area" facet counts and total, plus the line
// geometry once zoomed in. Never ships the (large) point set — `points=0`.
// Skips the request when the snapped bbox + zoom band + filters are unchanged.
async function updateViewport(): Promise<void> {
    const geomSource = map.value?.getSource(GEOM_SOURCE) as maplibregl.GeoJSONSource | undefined;
    if (!geomSource || !map.value) {
        return;
    }

    const withGeometry = map.value.getZoom() >= GEOMETRY_MIN_ZOOM;
    const bbox = snappedBbox(map.value.getBounds());
    const params = filterParams();

    const key = `${bbox}|${withGeometry}|${params.toString()}`;
    if (key === lastViewportKey) {
        return;
    }
    lastViewportKey = key;

    params.set('bbox', bbox);
    params.set('points', '0');
    if (withGeometry) {
        params.set('geometry', '1');
    }

    const data = await apiFetch(params);
    geomSource.setData(withGeometry ? (data.geometry ?? EMPTY_GEOJSON) : EMPTY_GEOJSON);
    emit('facets', data.facets);
    emit('total', data.total);
}

// Used by the search box: fly to the first text match, then let moveend update.
async function search(): Promise<number> {
    const data = await apiFetch(filterParams());
    const first = data.features[0];
    if (first && first.geometry.type === 'Point') {
        const [lng, lat] = first.geometry.coordinates;
        map.value?.flyTo({ center: [lng, lat], zoom: 14 });
    }
    return data.total;
}

defineExpose({ search });

// Emphasise the selected roadwork by filtering the highlight layer to its lines.
// The geometry itself is already loaded by reload() — no extra request.
function highlightSelected(id: number | null): void {
    const m = map.value;
    if (!m?.getLayer('geom-highlight')) {
        return;
    }
    m.setFilter('geom-highlight', ['==', ['get', 'roadworkId'], id ?? -1]);
}

watch(
    () => props.filters,
    () => {
        loadPoints();
        updateViewport();
    },
    { deep: true },
);

watch(
    () => props.selectedId,
    (id) => highlightSelected(id),
);

onMounted(() => {
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const instance = new maplibregl.Map({
        container: mapContainer.value!,
        center: [5.1214, 52.0907], // Utrecht
        zoom: 11,
        maxBounds: PMTILES_BOUNDS,
        maxZoom: PMTILES_MAX_ZOOM,
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
    map.value = instance;

    instance.on('load', () => {
        // One marker per roadwork (no clustering): every roadwork is always visible.
        instance.addSource(SOURCE, {
            type: 'geojson',
            data: { type: 'FeatureCollection', features: [] },
        });
        instance.addLayer({
            id: 'points',
            type: 'circle',
            source: SOURCE,
            paint: {
                'circle-color': [
                    'match',
                    ['get', 'severity'],
                    'high', '#ba1a1a',
                    'medium', '#775a00',
                    '#00205b',
                ],
                // Smaller dots when zoomed out (lots of them), larger up close.
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 7, 3, 12, 6, 15, 9],
                'circle-stroke-width': 1.5,
                'circle-stroke-color': '#ffffff',
            },
        });

        // Situation / restriction / detour geometry for every roadwork in view.
        // Only populated (by reload) and drawn from GEOMETRY_MIN_ZOOM upward.
        instance.addSource(GEOM_SOURCE, { type: 'geojson', data: EMPTY_GEOJSON });
        instance.addLayer({
            id: 'geom-fill',
            type: 'fill',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['==', ['geometry-type'], 'Polygon'],
            paint: {
                'fill-color': ['match', ['get', 'role'], 'restriction', '#ba1a1a', 'detour', '#775a00', '#00205b'],
                'fill-opacity': 0.12,
            },
        });
        instance.addLayer({
            id: 'geom-line',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['!=', ['get', 'role'], 'detour'],
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': ['match', ['get', 'role'], 'restriction', '#ba1a1a', '#00205b'],
                'line-width': 4,
                'line-opacity': 0.7,
            },
        });
        instance.addLayer({
            id: 'geom-detour',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['==', ['get', 'role'], 'detour'],
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': '#775a00',
                'line-width': 3,
                'line-opacity': 0.7,
                'line-dasharray': [2, 1.5],
            },
        });
        // Drawn on top, filtered to the selected roadwork for emphasis.
        instance.addLayer({
            id: 'geom-highlight',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['==', ['get', 'roadworkId'], -1],
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': ['match', ['get', 'role'], 'restriction', '#ba1a1a', 'detour', '#775a00', '#00205b'],
                'line-width': 7,
                'line-opacity': 1,
            },
        });

        instance.on('click', 'points', (event) => {
            const feature = event.features?.[0];
            if (!feature) {
                return;
            }
            emit('select', feature.properties as unknown as RoadworkFeatureProps);
            // Centre it and make sure we're zoomed in enough to show its geometry.
            instance.easeTo({
                center: (feature.geometry as GeoJSON.Point).coordinates as [number, number],
                zoom: Math.max(instance.getZoom(), GEOMETRY_MIN_ZOOM),
                padding: { left: 320, right: 384, top: 0, bottom: 0 },
                duration: 500,
            });
        });

        instance.on('mouseenter', 'points', () => (instance.getCanvas().style.cursor = 'pointer'));
        instance.on('mouseleave', 'points', () => (instance.getCanvas().style.cursor = ''));

        instance.on('moveend', () => updateViewport());
        loadPoints();
        updateViewport();
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
function locate(): void {
    navigator.geolocation?.getCurrentPosition((position) => {
        map.value?.flyTo({
            center: [position.coords.longitude, position.coords.latitude],
            zoom: 14,
        });
    });
}
</script>

<template>
    <div class="relative h-full w-full">
        <!-- h-full (not absolute inset-0): maplibre-gl.css forces position:relative
             on this element, which would cancel the insets and collapse it to 0. -->
        <div ref="mapContainer" class="h-full w-full"></div>

        <!-- Floating controls -->
        <div class="absolute top-6 right-6 z-30 flex flex-col gap-2">
            <button
                type="button"
                aria-label="Inzoomen"
                class="text-primary hover:bg-surface-container-low rounded-xl bg-white p-3 shadow-md transition-colors"
                @click="zoomIn"
            >
                <span class="material-symbols-outlined">add</span>
            </button>
            <button
                type="button"
                aria-label="Uitzoomen"
                class="text-primary hover:bg-surface-container-low rounded-xl bg-white p-3 shadow-md transition-colors"
                @click="zoomOut"
            >
                <span class="material-symbols-outlined">remove</span>
            </button>
            <button
                type="button"
                aria-label="Mijn locatie"
                class="text-primary hover:bg-surface-container-low mt-4 rounded-xl bg-white p-3 shadow-md transition-colors"
                @click="locate"
            >
                <span class="material-symbols-outlined">my_location</span>
            </button>
        </div>
    </div>
</template>
