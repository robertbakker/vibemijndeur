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
    slug?: string;
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
// Roadwork under the cursor; lights up its geometry like the selection does.
const hoveredId = ref<number | null>(null);

const SOURCE = 'roadworks';
const GEOM_SOURCE = 'roadwork-geom';
const EMPTY_GEOJSON: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

// Extent of the bundled basemap (scripts/basemap-pmtiles.sh): west,south,east,north.
const PMTILES_BOUNDS: [[number, number], [number, number]] = [
    [2.8, 50.4],
    [7.8, 54.0],
];
const PMTILES_MAX_ZOOM = 15;

// Detour/restriction lines draw from this zoom (matches the 2024 map's minzoom 10).
const GEOMETRY_MIN_ZOOM = 10;
// Arrows/crosses are fine detail — a bit further in, and only for the active one.
const ICON_MIN_ZOOM = 12;

// Marker icons mirroring ts/shared/src/map/markers.ts: a blue gradient arrow for
// detours and a dark-red bordered cross for restrictions. Loaded once on map load.
// Arrow points RIGHT: maplibre aligns a line symbol's horizontal axis to the
// line, so a right-pointing icon follows the route (an up-pointing one sits
// perpendicular — the reason the 2024 layer used icon-rotate: 90).
const ARROW_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><defs><linearGradient id="ag" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#0961ed"/><stop offset="1" stop-color="#00298a"/></linearGradient></defs><path d="M35 20 L10 6 L18 20 L10 34 Z" fill="url(#ag)" stroke="#001a4d" stroke-width="2.5" stroke-linejoin="round"/></svg>';
const CROSS_SVG =
    '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40"><path d="M11 11 L29 29 M29 11 L11 29" fill="none" stroke="#2e0a0a" stroke-width="10" stroke-linecap="round"/><path d="M11 11 L29 29 M29 11 L11 29" fill="none" stroke="#861717" stroke-width="6" stroke-linecap="round"/></svg>';

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

// Snap a bounding box outward to a coarse grid (~0.02°) after padding it, so
// small pans/zooms resolve to the same box and skip refetching.
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

// Per-viewport: facet counts + total, plus the line geometry once zoomed in.
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

// The highlighted roadwork is the hovered one, or the selected one otherwise.
// Its detour/restriction lines light up (coloured outline + line + markers) while
// every other roadwork stays greyed out.
function activeRoadworkId(): number {
    return hoveredId.value ?? props.selectedId ?? -1;
}

function applyActiveFilter(): void {
    const m = map.value;
    if (!m?.getLayer('geom-line')) {
        return;
    }
    const isActive = ['==', ['get', 'roadworkId'], activeRoadworkId()] as maplibregl.FilterSpecification;
    m.setFilter('geom-outline', isActive);
    m.setFilter('geom-line', isActive);
    m.setFilter('detour-markers', [
        'all',
        ['==', ['get', 'role'], 'detour'],
        isActive,
    ] as maplibregl.FilterSpecification);
    m.setFilter('restriction-markers', [
        'all',
        ['==', ['get', 'role'], 'restriction'],
        isActive,
    ] as maplibregl.FilterSpecification);
}

function setHovered(id: number | null): void {
    if (hoveredId.value !== id) {
        hoveredId.value = id;
        applyActiveFilter();
    }
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
    () => applyActiveFilter(),
);

function loadSvgImage(svg: string, size: number): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image(size, size);
        image.onload = () => resolve(image);
        image.onerror = reject;
        image.src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;
    });
}

onMounted(() => {
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const instance = new maplibregl.Map({
        container: mapContainer.value!,
        center: [5.1214, 52.0907], // Utrecht
        zoom: 11,
        maxBounds: PMTILES_BOUNDS,
        maxZoom: PMTILES_MAX_ZOOM,
        canvasContextAttributes: { antialias: true },
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

    instance.on('load', async () => {
        const [arrowImage, crossImage] = await Promise.all([
            loadSvgImage(ARROW_SVG, 40),
            loadSvgImage(CROSS_SVG, 40),
        ]);
        if (!instance.hasImage('detour-marker')) {
            instance.addImage('detour-marker', arrowImage, { pixelRatio: 2 });
        }
        if (!instance.hasImage('restriction-marker')) {
            instance.addImage('restriction-marker', crossImage, { pixelRatio: 2 });
        }

        instance.addSource(SOURCE, { type: 'geojson', data: EMPTY_GEOJSON });
        instance.addSource(GEOM_SOURCE, { type: 'geojson', data: EMPTY_GEOJSON });

        // Greyed-out geometry for every roadwork (2024 *-line-disabled).
        instance.addLayer({
            id: 'geom-disabled',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: { 'line-color': '#a1a1a1', 'line-opacity': 0.3, 'line-width': 8 },
        });

        // Darker outline casing for the highlighted roadwork (2024 *-line-outline).
        instance.addLayer({
            id: 'geom-outline',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['==', ['get', 'roadworkId'], -1],
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-width': 10,
                'line-color': ['match', ['get', 'role'], 'restriction', '#861717', 'detour', '#1F449C', '#001a4d'],
            },
        });

        // Bright line on top for the highlighted roadwork (2024 *-line).
        instance.addLayer({
            id: 'geom-line',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            filter: ['==', ['get', 'roadworkId'], -1],
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-width': 5,
                'line-opacity': 0.9,
                'line-color': ['match', ['get', 'role'], 'restriction', '#F05039', 'detour', '#1b5dff', '#003082'],
            },
        });

        // Arrows along the highlighted detour (2024 detour-markers).
        instance.addLayer({
            id: 'detour-markers',
            type: 'symbol',
            source: GEOM_SOURCE,
            minzoom: ICON_MIN_ZOOM,
            filter: ['all', ['==', ['get', 'role'], 'detour'], ['==', ['get', 'roadworkId'], -1]],
            layout: {
                'symbol-placement': 'line',
                'symbol-spacing': 50,
                'icon-image': 'detour-marker',
                'icon-size': 1.15,
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': false,
                'icon-ignore-placement': false,
            },
        });

        // Crosses along the highlighted restriction (2024 restriction-markers).
        instance.addLayer({
            id: 'restriction-markers',
            type: 'symbol',
            source: GEOM_SOURCE,
            minzoom: ICON_MIN_ZOOM,
            filter: ['all', ['==', ['get', 'role'], 'restriction'], ['==', ['get', 'roadworkId'], -1]],
            layout: {
                'symbol-placement': 'line',
                'symbol-spacing': 70,
                'icon-image': 'restriction-marker',
                'icon-size': 1.15,
                'icon-rotation-alignment': 'map',
                'icon-allow-overlap': false,
                'icon-ignore-placement': false,
            },
        });

        // One marker per roadwork, coloured by severity, on top.
        instance.addLayer({
            id: 'points',
            type: 'circle',
            source: SOURCE,
            paint: {
                'circle-color': ['match', ['get', 'severity'], 'high', '#ba1a1a', 'medium', '#775a00', '#003082'],
                'circle-radius': ['interpolate', ['linear'], ['zoom'], 7, 3, 12, 6, 15, 9],
                'circle-stroke-width': 1.5,
                'circle-stroke-color': '#ffffff',
            },
        });

        instance.on('click', 'points', (event) => {
            const feature = event.features?.[0];
            if (!feature) {
                return;
            }
            emit('select', feature.properties as unknown as RoadworkFeatureProps);
            instance.easeTo({
                center: (feature.geometry as GeoJSON.Point).coordinates as [number, number],
                zoom: Math.max(instance.getZoom(), ICON_MIN_ZOOM),
                padding: { left: 320, right: 384, top: 0, bottom: 0 },
                duration: 500,
            });
        });

        // Hovering a point or any geometry line lights up that roadwork.
        const hoverLayers = ['points', 'geom-disabled', 'geom-line'];
        instance.on('mousemove', (event) => {
            const features = instance.queryRenderedFeatures(event.point, { layers: hoverLayers });
            const feature = features[0];
            instance.getCanvas().style.cursor = feature?.layer.id === 'points' ? 'pointer' : '';
            const id = feature
                ? ((feature.properties.roadworkId ?? feature.properties.id) as number)
                : null;
            setHovered(id ?? null);
        });

        instance.on('moveend', () => updateViewport());
        applyActiveFilter();
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
