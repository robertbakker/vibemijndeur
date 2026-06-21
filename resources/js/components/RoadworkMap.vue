<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef, watch } from 'vue';
import maplibregl from 'maplibre-gl';
import { layers, namedFlavor } from '@protomaps/basemaps';
import { Protocol } from 'pmtiles';
import { MapboxOverlay } from '@deck.gl/mapbox';
import { GeoJsonLayer, IconLayer, ScatterplotLayer } from '@deck.gl/layers';
import { CollisionFilterExtension } from '@deck.gl/extensions';
import type { Layer } from '@deck.gl/core';
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
let overlay: MapboxOverlay | undefined;

const EMPTY_GEOJSON: GeoJSON.FeatureCollection = { type: 'FeatureCollection', features: [] };

// Rendered data, kept reactive so layers rebuild whenever it changes.
const pointFeatures = ref<GeoJSON.Feature[]>([]);
const geomFeatures = ref<GeoJSON.Feature[]>([]);
// Icon positions sampled along the lines; recomputed only when geometry changes,
// not on every hover (the active tint is applied per-render via getColor).
const arrowMarkers = ref<LineMarker[]>([]);
const crossMarkers = ref<LineMarker[]>([]);
// Roadwork currently under the cursor; lights up its geometry like selection does.
const hoveredId = ref<number | null>(null);

type RgbColor = [number, number, number];

// Palette lifted from the 2024 map (ts/shared/src/map/layers.ts):
// detours/restrictions sit greyed-out (#a1a1a1 @ 0.3) until their roadwork is
// hovered/selected, then light up — a darker outline "casing" under a brighter
// line, blue for detours, red for restrictions.
const DISABLED_COLOR: RgbColor = [161, 161, 161]; // #a1a1a1
const DISABLED_ALPHA = 77; // 0.3

// Material palette shared with the rest of the app, as deck.gl RGB arrays.
const SEVERITY_COLOR: Record<string, RgbColor> = {
    high: [186, 26, 26], // #ba1a1a
    medium: [119, 90, 0], // #775a00
};
const DEFAULT_COLOR: RgbColor = [0, 32, 91]; // #00205b
// Bright "line" colours.
const ROLE_COLOR: Record<string, RgbColor> = {
    restriction: [240, 80, 57], // #F05039
    detour: [27, 93, 255], // #1b5dff
};
// Darker "outline"/casing colours drawn under the line.
const ROLE_OUTLINE: Record<string, RgbColor> = {
    restriction: [134, 23, 23], // #861717
    detour: [31, 68, 156], // #1F449C
};

function severityColor(feature: GeoJSON.Feature): RgbColor {
    const severity = (feature.properties?.severity as string) ?? '';
    return SEVERITY_COLOR[severity] ?? DEFAULT_COLOR;
}

function roleColor(feature: GeoJSON.Feature): RgbColor {
    const role = (feature.properties?.role as string) ?? '';
    return ROLE_COLOR[role] ?? DEFAULT_COLOR;
}

function roleOutline(feature: GeoJSON.Feature): RgbColor {
    const role = (feature.properties?.role as string) ?? '';
    return ROLE_OUTLINE[role] ?? [0, 16, 46]; // darker default blue
}

function pointPosition(feature: GeoJSON.Feature): [number, number] {
    return (feature.geometry as GeoJSON.Point).coordinates as [number, number];
}

// Detour = arrow (points along the route), restriction = cross — mirroring the
// bordered, gradient-filled symbol-along-line markers from the 2024 map. Each has
// an "active" (full colour) and a muted-grey variant. All four are baked into ONE
// static atlas loaded once; getIcon then switches by NAME, so hovering never
// reloads a texture (which caused a whole-canvas white flash) and every icon is
// always drawn (deck.gl IconLayer does no collision/declutter — they overlap).
//
// Atlas layout: 4 cells of 32px on a 128x32 sheet. Arrows point up (rotated to
// the segment bearing at render); crosses are upright X's.
const ICON_ATLAS =
    'data:image/svg+xml;charset=utf-8,' +
    encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="32" viewBox="0 0 128 32">' +
            '<defs><linearGradient id="ag" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0961ed"/><stop offset="1" stop-color="#00298a"/></linearGradient></defs>' +
            '<path transform="translate(0,0)" d="M16 3 L28 28 L16 21 L4 28 Z" fill="url(#ag)" stroke="#001a4d" stroke-width="2" stroke-linejoin="round"/>' +
            '<path transform="translate(32,0)" d="M16 3 L28 28 L16 21 L4 28 Z" fill="#9aa3b2" stroke="#5b6473" stroke-width="2" stroke-linejoin="round"/>' +
            '<g transform="translate(64,0)"><path fill="none" stroke="#2e0a0a" stroke-width="8" stroke-linecap="round" d="M8 8 L24 24 M24 8 L8 24"/><path fill="none" stroke="#861717" stroke-width="5" stroke-linecap="round" d="M8 8 L24 24 M24 8 L8 24"/></g>' +
            '<g transform="translate(96,0)"><path fill="none" stroke="#5b6473" stroke-width="8" stroke-linecap="round" d="M8 8 L24 24 M24 8 L8 24"/><path fill="none" stroke="#9aa3b2" stroke-width="5" stroke-linecap="round" d="M8 8 L24 24 M24 8 L8 24"/></g>' +
            '</svg>',
    );
const ICON_MAPPING = {
    'arrow-active': { x: 0, y: 0, width: 32, height: 32 },
    'arrow-muted': { x: 32, y: 0, width: 32, height: 32 },
    'cross-active': { x: 64, y: 0, width: 32, height: 32 },
    'cross-muted': { x: 96, y: 0, width: 32, height: 32 },
};

interface LineMarker {
    position: [number, number];
    angle: number; // deck.gl getAngle (CCW degrees); 0 = icon drawn upright
    roadworkId: number;
}

// Distance (m) along the line between successive icons, per role.
const ARROW_SPACING_M = 120;
const CROSS_SPACING_M = 90;

function toRad(deg: number): number {
    return (deg * Math.PI) / 180;
}

function haversine(a: [number, number], b: [number, number]): number {
    const R = 6371000;
    const dLat = toRad(b[1] - a[1]);
    const dLon = toRad(b[0] - a[0]);
    const lat1 = toRad(a[1]);
    const lat2 = toRad(b[1]);
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

// Compass bearing a->b in degrees clockwise from north.
function bearing(a: [number, number], b: [number, number]): number {
    const dLon = toRad(b[0] - a[0]);
    const lat1 = toRad(a[1]);
    const lat2 = toRad(b[1]);
    const y = Math.sin(dLon) * Math.cos(lat2);
    const x =
        Math.cos(lat1) * Math.sin(lat2) -
        Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLon);
    return (Math.atan2(y, x) * 180) / Math.PI;
}

function lineStrings(feature: GeoJSON.Feature): [number, number][][] {
    const geometry = feature.geometry;
    if (geometry.type === 'LineString') {
        return [geometry.coordinates as [number, number][]];
    }
    if (geometry.type === 'MultiLineString') {
        return geometry.coordinates as [number, number][][];
    }
    return [];
}

// Walk each line and drop a marker every `spacing` metres, oriented to the local
// segment so arrows point in the direction of travel.
function markersAlong(features: GeoJSON.Feature[], spacing: number): LineMarker[] {
    const out: LineMarker[] = [];
    for (const feature of features) {
        const roadworkId = feature.properties?.roadworkId as number;
        for (const line of lineStrings(feature)) {
            let accumulated = 0;
            let next = spacing / 2;
            for (let i = 0; i < line.length - 1; i++) {
                const a = line[i];
                const b = line[i + 1];
                const segment = haversine(a, b);
                if (segment <= 0) {
                    continue;
                }
                // Up-pointing icon rotated CW by the bearing → deck angle = -bearing.
                const angle = -bearing(a, b);
                while (next <= accumulated + segment) {
                    const t = (next - accumulated) / segment;
                    out.push({
                        position: [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t],
                        angle,
                        roadworkId,
                    });
                    next += spacing;
                }
                accumulated += segment;
            }
        }
    }
    return out;
}

// Extent of the bundled basemap (scripts/basemap-pmtiles.sh): west,south,east,north
// and its maxzoom. Panning/zooming is clamped to this so users never reach the
// empty area outside the Netherlands tiles or overzoom past available detail.
const PMTILES_BOUNDS: [[number, number], [number, number]] = [
    [2.8, 50.4],
    [7.8, 54.0],
];
const PMTILES_MAX_ZOOM = 15;

// From this zoom the detours/restrictions line geometry is requested and drawn.
// Below it only the (cheap) marker points load, so a zoomed-out view never pulls
// the heavy geometry for thousands of roadworks.
// 2024 map drew detour/restriction geometry from zoom 10.
const GEOMETRY_MIN_ZOOM = 10;

// Arrows/crosses are fine detail: they show a bit further in than the lines, and
// only for the highlighted (hovered/selected) roadwork.
const ICON_MIN_ZOOM = 12;

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

// Hovering any pickable layer (a point, a line, or an icon) lights up its whole
// roadwork — so hovering a detour/restriction also lights up the situation that
// shares the roadworkId. Cursor itself is handled by the overlay's getCursor.
type Hoverable = { roadworkId?: number; properties?: { roadworkId?: number; id?: number } };
function handleHover(info: { object?: Hoverable | null }): void {
    const o = info.object;
    hoveredId.value = o
        ? (o.roadworkId ?? o.properties?.roadworkId ?? o.properties?.id ?? null)
        : null;
}

// Build the deck.gl layer stack from the current reactive data + selection and
// push it to the overlay. Cheap to call on every data/selection change; deck.gl
// diffs props and animates the differences via each layer's `transitions`.
function rebuildLayers(): void {
    if (!overlay) {
        return;
    }

    // A roadwork "lights up" when it is hovered or selected.
    const activeIds = new Set<number>(
        [props.selectedId, hoveredId.value].filter((id): id is number => id !== null),
    );
    const isActive = (f: GeoJSON.Feature): boolean =>
        activeIds.has(f.properties?.roadworkId as number);

    // updateTriggers must change whenever the active set does, or deck.gl reuses
    // the cached colours/widths and the light-up never animates.
    const activeKey = [...activeIds].sort().join(',');

    // Shared screen-space decluttering for the arrow + cross icon layers. Typed
    // loosely because the CollisionFilterExtension props aren't on IconLayer's
    // type; spreading also avoids repeating the block. Active markers get a high
    // priority so a hovered/selected route keeps all of its icons.
    const collisionProps: Record<string, unknown> = {
        extensions: [new CollisionFilterExtension()],
        collisionEnabled: true,
        collisionGroup: 'roadwork-markers',
        collisionTestProps: { sizeScale: 2 },
        getCollisionPriority: (d: LineMarker) => (activeIds.has(d.roadworkId) ? 100 : 0),
        updateTriggers: {
            getIcon: activeKey,
            getColor: activeKey,
            getCollisionPriority: activeKey,
        },
    };

    // Icons appear only from ICON_MIN_ZOOM and only for the highlighted roadwork.
    const showIcons = (map.value?.getZoom() ?? 0) >= ICON_MIN_ZOOM;
    const activeArrows = showIcons
        ? arrowMarkers.value.filter((m) => activeIds.has(m.roadworkId))
        : [];
    const activeCrosses = showIcons
        ? crossMarkers.value.filter((m) => activeIds.has(m.roadworkId))
        : [];

    // Line widths from the 2024 map: a greyed-out "disabled" line (8px) for
    // every roadwork, and for the highlighted one a darker outline casing (10px)
    // under a brighter coloured line (5px). Widths are constant (no grow).
    const stack: Layer[] = [
        // Casing: grey disabled line by default; darker role outline when active.
        new GeoJsonLayer({
            id: 'geom-casing',
            data: { type: 'FeatureCollection', features: geomFeatures.value },
            stroked: true,
            filled: false,
            pickable: true,
            onHover: handleHover,
            lineWidthUnits: 'pixels',
            getLineColor: (f) =>
                (isActive(f) ? [...roleOutline(f), 255] : [...DISABLED_COLOR, DISABLED_ALPHA]) as [number, number, number, number],
            getLineWidth: (f) => (isActive(f) ? 10 : 8),
            lineWidthMinPixels: 3,
            lineCapRounded: true,
            lineJointRounded: true,
            updateTriggers: { getLineColor: activeKey, getLineWidth: activeKey },
            transitions: { getLineColor: 250 },
        }),
        // Bright line on top — only drawn for the highlighted roadwork.
        new GeoJsonLayer({
            id: 'geom-line',
            data: { type: 'FeatureCollection', features: geomFeatures.value },
            stroked: true,
            filled: false,
            pickable: true,
            onHover: handleHover,
            lineWidthUnits: 'pixels',
            getLineColor: (f) =>
                (isActive(f) ? [...roleColor(f), 230] : [0, 0, 0, 0]) as [number, number, number, number],
            getLineWidth: (f) => (isActive(f) ? 5 : 0),
            lineWidthMinPixels: 2,
            lineCapRounded: true,
            lineJointRounded: true,
            updateTriggers: { getLineColor: activeKey, getLineWidth: activeKey },
            transitions: { getLineColor: 250 },
        }),
        // Arrows along detour routes, pointing in the direction of travel.
        // Icon swaps muted<->active (keeps its gradient + border); size is fixed.
        new IconLayer<LineMarker>({
            id: 'detour-arrows',
            data: activeArrows,
            pickable: true,
            onHover: handleHover,
            billboard: true,
            sizeUnits: 'pixels',
            iconAtlas: ICON_ATLAS,
            iconMapping: ICON_MAPPING,
            getIcon: (d) => (activeIds.has(d.roadworkId) ? 'arrow-active' : 'arrow-muted'),
            getPosition: (d) => d.position,
            getAngle: (d) => d.angle,
            getSize: 22,
            // Fade muted icons back a touch; full opacity when active.
            getColor: (d) =>
                (activeIds.has(d.roadworkId) ? [255, 255, 255, 255] : [255, 255, 255, 170]) as [number, number, number, number],
            ...collisionProps,
        }),
        // Crosses along restriction lines.
        new IconLayer<LineMarker>({
            id: 'restriction-crosses',
            data: activeCrosses,
            pickable: true,
            onHover: handleHover,
            billboard: true,
            sizeUnits: 'pixels',
            iconAtlas: ICON_ATLAS,
            iconMapping: ICON_MAPPING,
            getIcon: (d) => (activeIds.has(d.roadworkId) ? 'cross-active' : 'cross-muted'),
            getPosition: (d) => d.position,
            getAngle: (d) => d.angle,
            getSize: 20,
            getColor: (d) =>
                (activeIds.has(d.roadworkId) ? [255, 255, 255, 255] : [255, 255, 255, 170]) as [number, number, number, number],
            ...collisionProps,
        }),
        // One marker per roadwork, kept on top so it stays clickable.
        new ScatterplotLayer<GeoJSON.Feature>({
            id: 'points',
            data: pointFeatures.value,
            pickable: true,
            stroked: true,
            radiusUnits: 'meters',
            getPosition: pointPosition,
            getFillColor: (f) => [...severityColor(f), 255] as [number, number, number, number],
            getLineColor: [255, 255, 255, 255],
            getRadius: 60,
            radiusMinPixels: 3,
            radiusMaxPixels: 9,
            lineWidthMinPixels: 1.5,
            onClick: (info) => {
                const feature = info.object as GeoJSON.Feature | undefined;
                if (!feature) {
                    return;
                }
                emit('select', feature.properties as unknown as RoadworkFeatureProps);
                // Centre it and zoom in far enough to reveal its arrows/crosses.
                map.value?.easeTo({
                    center: pointPosition(feature),
                    zoom: Math.max(map.value.getZoom(), ICON_MIN_ZOOM),
                    padding: { left: 320, right: 384, top: 0, bottom: 0 },
                    duration: 500,
                });
            },
            onHover: handleHover,
            // Fade markers in (alpha 0 -> full) on load / filter change.
            transitions: {
                getFillColor: {
                    duration: 400,
                    enter: ([r, g, b]: number[]) => [r, g, b, 0],
                },
            },
        }),
    ];

    overlay.setProps({ layers: stack });
}

// Load every roadwork point once (country-wide). deck.gl culls to the viewport,
// so panning/zooming never refetches the full set — only filter changes do.
async function loadPoints(): Promise<void> {
    const params = filterParams();
    params.set('bbox', COUNTRY_BBOX);
    const data = await apiFetch(params);
    pointFeatures.value = data.features;
    rebuildLayers();
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
    if (!map.value) {
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
    geomFeatures.value = withGeometry ? (data.geometry ?? EMPTY_GEOJSON).features : [];
    // Resample the along-line icon positions for the new geometry (cheap, and
    // independent of hover/selection, so hover rebuilds don't redo this work).
    const restrictions = geomFeatures.value.filter(
        (f) => (f.properties?.role as string) === 'restriction',
    );
    const detourLines = geomFeatures.value.filter(
        (f) => (f.properties?.role as string) === 'detour',
    );
    arrowMarkers.value = markersAlong(detourLines, ARROW_SPACING_M);
    crossMarkers.value = markersAlong(restrictions, CROSS_SPACING_M);
    emit('facets', data.facets);
    emit('total', data.total);
    rebuildLayers();
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
    () => rebuildLayers(),
);

watch(hoveredId, () => rebuildLayers());

onMounted(() => {
    const protocol = new Protocol();
    maplibregl.addProtocol('pmtiles', protocol.tile);

    const instance = new maplibregl.Map({
        container: mapContainer.value!,
        center: [5.1214, 52.0907], // Utrecht
        zoom: 11,
        maxBounds: PMTILES_BOUNDS,
        maxZoom: PMTILES_MAX_ZOOM,
        // MSAA for smooth basemap edges (deck.gl draws on its own canvas now).
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

    // deck.gl renders the roadwork data above the basemap. Overlaid (NOT
    // interleaved): deck draws on its own canvas instead of into maplibre's
    // framebuffer. Interleaved mode flashed the whole canvas white on hover
    // because the CollisionFilterExtension's extra render pass briefly cleared
    // the shared buffer. Overlaid sidesteps that; data already belongs on top.
    // getCursor drives the pointer from deck's own hover state — setting the
    // canvas cursor manually fights maplibre and flickers.
    overlay = new MapboxOverlay({
        interleaved: false,
        layers: [],
        getCursor: ({ isDragging, isHovering }) =>
            isDragging ? 'grabbing' : isHovering ? 'pointer' : 'grab',
    });
    instance.addControl(overlay as unknown as maplibregl.IControl);

    instance.on('load', () => {
        rebuildLayers();
        instance.on('moveend', () => updateViewport());
        // Toggle the icons only when crossing the threshold, not every zoom frame.
        let iconsShown = instance.getZoom() >= ICON_MIN_ZOOM;
        instance.on('zoom', () => {
            const shown = instance.getZoom() >= ICON_MIN_ZOOM;
            if (shown !== iconsShown) {
                iconsShown = shown;
                rebuildLayers();
            }
        });
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
