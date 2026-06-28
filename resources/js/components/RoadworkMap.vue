<script setup lang="ts">
import maplibregl from 'maplibre-gl';
import { Protocol } from 'pmtiles';
import { onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';
import { buildMapStyle } from '@/lib/mapStyle';
import { MARKER_ICONS, whiteGlyphSvg } from '@/lib/markerIcons';
import { typeView } from '@/lib/roadwork';

export interface RoadworkFeatureProps {
    id: number;
    title: string;
    kind: string | null;
    severity: string | null;
    status: string | null;
    authority: string | null;
    slug?: string;
    activityType?: string | null;
    startTs?: number | null;
    endTs?: number | null;
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
    (event: 'visible', features: RoadworkFeatureProps[]): void;
}>();

const mapContainer = useTemplateRef<HTMLDivElement>('mapContainer');
const map = ref<maplibregl.Map>();
// Roadwork under the cursor; lights up its geometry like the selection does.
const hoveredId = ref<number | null>(null);

const SOURCE = 'roadworks';
const GEOM_SOURCE = 'roadwork-geom';
const EMPTY_GEOJSON: GeoJSON.FeatureCollection = {
    type: 'FeatureCollection',
    features: [],
};

// Extent of the bundled basemap (scripts/basemap-pmtiles.sh): west,south,east,north.
const PMTILES_BOUNDS: [[number, number], [number, number]] = [
    [2.8, 50.4],
    [7.8, 54.0],
];
const PMTILES_MAX_ZOOM = 15;

// Remember the last overview position so returning from a detail page (browser
// back / Inertia history) drops you back where you were, not at the default.
const VIEW_STORAGE_KEY = 'vmd:map-view';
const DEFAULT_CENTER: [number, number] = [5.1214, 52.0907]; // Utrecht
const DEFAULT_ZOOM = 11;

interface SavedView {
    center: [number, number];
    zoom: number;
}

function readSavedView(): SavedView | null {
    try {
        const raw = localStorage.getItem(VIEW_STORAGE_KEY);
        if (!raw) {
            return null;
        }
        const parsed = JSON.parse(raw);
        const { lng, lat, zoom } = parsed;
        if (
            typeof lng === 'number' &&
            typeof lat === 'number' &&
            typeof zoom === 'number'
        ) {
            return { center: [lng, lat], zoom };
        }
    } catch {
        // Ignore unavailable/corrupt storage and fall back to the default view.
    }
    return null;
}

function saveView(instance: maplibregl.Map): void {
    try {
        const center = instance.getCenter();
        localStorage.setItem(
            VIEW_STORAGE_KEY,
            JSON.stringify({
                lng: center.lng,
                lat: center.lat,
                zoom: instance.getZoom(),
            }),
        );
    } catch {
        // Storage may be unavailable (private mode); persistence is best-effort.
    }
}

// Marker fill per lifecycle status — matches the design palette and the /kaart
// legend (in uitvoering / gepland / afgerond).
const STATUS_ACTIVE = '#FFC400';
const STATUS_PLANNED = '#2F6BD8';
const STATUS_DONE = '#1F8A5B';

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
    props.filters.kind.forEach((value) => {
        params.append('kind[]', value);
    });
    props.filters.status.forEach((value) => {
        params.append('status[]', value);
    });
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
    const source = map.value?.getSource(SOURCE) as
        | maplibregl.GeoJSONSource
        | undefined;
    if (!source) {
        return;
    }
    const params = filterParams();
    params.set('bbox', COUNTRY_BBOX);
    const data = await apiFetch(params);
    // Tag each point with its Font Awesome icon name so the symbol layer can
    // draw the per-type glyph (mirrors App\Roadworks\RoadworkType server-side).
    for (const feature of data.features) {
        const featureProps = (feature.properties ?? {}) as Record<
            string,
            unknown
        >;
        featureProps.icon = typeView(
            (featureProps.activityType as string | null) ?? null,
            (featureProps.title as string | null) ?? null,
        ).icon;
        feature.properties = featureProps;
    }
    source.setData({ type: 'FeatureCollection', features: data.features });
}

// Snap a bounding box outward to a coarse grid (~0.02°) after padding it, so
// small pans/zooms resolve to the same box and skip refetching.
const BBOX_GRID = 0.02;
const BBOX_MARGIN = 0.35;

function snappedBbox(b: maplibregl.LngLatBounds): string {
    const padX = (b.getEast() - b.getWest()) * BBOX_MARGIN;
    const padY = (b.getNorth() - b.getSouth()) * BBOX_MARGIN;
    const down = (v: number) =>
        Math.floor((v - BBOX_GRID) / BBOX_GRID) * BBOX_GRID;
    const up = (v: number) =>
        Math.ceil((v + BBOX_GRID) / BBOX_GRID) * BBOX_GRID;
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
    const geomSource = map.value?.getSource(GEOM_SOURCE) as
        | maplibregl.GeoJSONSource
        | undefined;
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
    geomSource.setData(
        withGeometry ? (data.geometry ?? EMPTY_GEOJSON) : EMPTY_GEOJSON,
    );
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

// Roadworks whose point markers are currently rendered in the viewport, capped
// and deduped, for the list panel beside the map.
const VISIBLE_LIMIT = 40;

function emitVisible(): void {
    const m = map.value;
    if (!m?.getLayer('points')) {
        return;
    }
    const seen = new Set<number>();
    const list: RoadworkFeatureProps[] = [];
    for (const feature of m.queryRenderedFeatures({ layers: ['points'] })) {
        const featureProps =
            feature.properties as unknown as RoadworkFeatureProps;
        if (seen.has(featureProps.id)) {
            continue;
        }
        seen.add(featureProps.id);
        list.push(featureProps);
        if (list.length >= VISIBLE_LIMIT) {
            break;
        }
    }
    emit('visible', list);
}

// Fly to a roadwork's point (used when a list row is clicked), leaving room for
// the list panel and popup.
function focus(id: number): void {
    const m = map.value;
    const feature = m
        ?.querySourceFeatures(SOURCE)
        .find((candidate) => (candidate.properties?.id as number) === id);
    if (m && feature && feature.geometry.type === 'Point') {
        m.easeTo({
            center: feature.geometry.coordinates as [number, number],
            zoom: Math.max(m.getZoom(), ICON_MIN_ZOOM),
            padding: { left: 360, right: 440, top: 0, bottom: 0 },
            duration: 500,
        });
    }
}

defineExpose({ search, focus });

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
    const isActive = [
        '==',
        ['get', 'roadworkId'],
        activeRoadworkId(),
    ] as maplibregl.FilterSpecification;
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
    if (!mapContainer.value) {
        return;
    }
    const container = mapContainer.value;
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const savedView = readSavedView();
    const instance = new maplibregl.Map({
        container,
        center: savedView?.center ?? DEFAULT_CENTER,
        zoom: savedView?.zoom ?? DEFAULT_ZOOM,
        maxBounds: PMTILES_BOUNDS,
        maxZoom: PMTILES_MAX_ZOOM,
        canvasContextAttributes: { antialias: true },
        attributionControl: { compact: true },
        style: buildMapStyle(),
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
            instance.addImage('restriction-marker', crossImage, {
                pixelRatio: 2,
            });
        }

        // One white glyph image per work type, keyed by its `fa-*` name so the
        // marker symbol layer can pick it via icon-image: ['get', 'icon'].
        await Promise.all(
            Object.entries(MARKER_ICONS).map(async ([name, glyph]) => {
                if (instance.hasImage(name)) {
                    return;
                }
                const image = await loadSvgImage(whiteGlyphSvg(glyph), 36);
                instance.addImage(name, image, { pixelRatio: 2 });
            }),
        );

        instance.addSource(SOURCE, { type: 'geojson', data: EMPTY_GEOJSON });
        instance.addSource(GEOM_SOURCE, {
            type: 'geojson',
            data: EMPTY_GEOJSON,
        });

        // Greyed-out geometry for every roadwork (2024 *-line-disabled).
        instance.addLayer({
            id: 'geom-disabled',
            type: 'line',
            source: GEOM_SOURCE,
            minzoom: GEOMETRY_MIN_ZOOM,
            layout: { 'line-join': 'round', 'line-cap': 'round' },
            paint: {
                'line-color': '#a1a1a1',
                'line-opacity': 0.3,
                'line-width': 8,
            },
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

        // Arrows along the highlighted detour (2024 detour-markers).
        instance.addLayer({
            id: 'detour-markers',
            type: 'symbol',
            source: GEOM_SOURCE,
            minzoom: ICON_MIN_ZOOM,
            filter: [
                'all',
                ['==', ['get', 'role'], 'detour'],
                ['==', ['get', 'roadworkId'], -1],
            ],
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
            filter: [
                'all',
                ['==', ['get', 'role'], 'restriction'],
                ['==', ['get', 'roadworkId'], -1],
            ],
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

        // One marker per roadwork, coloured by lifecycle status (mirrors
        // App\Data\RoadworkStatus: running → in uitvoering, final → afgerond,
        // else derived from the start/end dates), with the design's white ring.
        const nowTs = Math.floor(Date.now() / 1000);
        instance.addLayer({
            id: 'points',
            type: 'circle',
            source: SOURCE,
            paint: {
                'circle-color': [
                    'case',
                    ['==', ['get', 'status'], 'running'],
                    STATUS_ACTIVE,
                    ['==', ['get', 'status'], 'final'],
                    STATUS_DONE,
                    ['>', ['number', ['get', 'startTs'], 0], nowTs],
                    STATUS_PLANNED,
                    ['<', ['number', ['get', 'endTs'], 9999999999], nowTs],
                    STATUS_DONE,
                    STATUS_ACTIVE,
                ],
                // Reaches the design's 38px pin (radius 19) at city zoom, scaling
                // down when zoomed out so country view doesn't turn to mush.
                'circle-radius': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    7,
                    5,
                    12,
                    13,
                    15,
                    19,
                ],
                'circle-stroke-width': 3,
                'circle-stroke-color': '#ffffff',
            },
        });

        // White per-type glyph centred on each marker, from a bit further in so
        // the discs don't overlap into mush at country zoom.
        instance.addLayer({
            id: 'point-icons',
            type: 'symbol',
            source: SOURCE,
            minzoom: 11,
            layout: {
                'icon-image': ['get', 'icon'],
                // ~15px glyph inside the 38px pin at city zoom. The source image
                // is added at pixelRatio 2 (18px base), so sizes run ~0.55–0.85.
                'icon-size': [
                    'interpolate',
                    ['linear'],
                    ['zoom'],
                    11,
                    0.55,
                    15,
                    0.85,
                ],
                'icon-allow-overlap': true,
                'icon-ignore-placement': true,
            },
        });

        instance.on('click', 'points', (event) => {
            const feature = event.features?.[0];
            if (!feature) {
                return;
            }
            emit(
                'select',
                feature.properties as unknown as RoadworkFeatureProps,
            );
            instance.easeTo({
                center: (feature.geometry as GeoJSON.Point).coordinates as [
                    number,
                    number,
                ],
                zoom: Math.max(instance.getZoom(), ICON_MIN_ZOOM),
                padding: { left: 320, right: 384, top: 0, bottom: 0 },
                duration: 500,
            });
        });

        // Hovering a point or any geometry line lights up that roadwork.
        const hoverLayers = ['points', 'geom-disabled', 'geom-line'];
        instance.on('mousemove', (event) => {
            const features = instance.queryRenderedFeatures(event.point, {
                layers: hoverLayers,
            });
            const feature = features[0];
            instance.getCanvas().style.cursor =
                feature?.layer.id === 'points' ? 'pointer' : '';
            const id = feature
                ? ((feature.properties.roadworkId ??
                      feature.properties.id) as number)
                : null;
            setHovered(id ?? null);
        });

        instance.on('moveend', () => {
            updateViewport();
            saveView(instance);
        });
        instance.on('idle', () => emitVisible());
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
