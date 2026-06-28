import { type Flavor, layers, namedFlavor } from '@protomaps/basemaps';
import type { StyleSpecification } from 'maplibre-gl';

const GLYPHS =
    'https://protomaps.github.io/basemaps-assets/fonts/{fontstack}/{range}.pbf';
const SPRITE = 'https://protomaps.github.io/basemaps-assets/sprites/v4/light';
const TILES_URL = 'pmtiles:///tiles/basemap-nl.pmtiles';
const ATTRIBUTION =
    '<a href="https://protomaps.com">Protomaps</a> © <a href="https://openstreetmap.org">OpenStreetMap</a>';

/**
 * App-branded basemap flavor: the Protomaps `light` flavor nudged toward the
 * voormijndeur palette (navy labels, the hero-teaser blue water, soft greens,
 * clean white roads). Keeps brand amber reserved for status markers so the
 * basemap never competes with them.
 */
const brandedFlavor: Flavor = {
    ...namedFlavor('light'),

    // Land — match the app's blue-grey surface so the map sits in the page.
    background: '#eef2f7',
    earth: '#e9ecee',

    // Water — the hero map-teaser blue (#AEC9DF).
    water: '#aec9df',

    // Greens — the teaser park green (#CFE3C4) with a slightly deeper wood.
    park_a: '#d4e6c8',
    park_b: '#cfe3c4',
    wood_a: '#cfe3c4',
    wood_b: '#c4dbb7',
    scrub_a: '#d4e6c8',
    scrub_b: '#cfe3c4',

    // Buildings — quiet, on the outline-variant tone.
    buildings: '#e2e7ee',

    // Roads — clean white fills on light navy-grey casings.
    minor_service: '#ffffff',
    minor_a: '#ffffff',
    minor_b: '#ffffff',
    minor_service_casing: '#e2e7ee',
    minor_casing: '#dbe2ec',
    link: '#ffffff',
    link_casing: '#dbe2ec',
    major: '#ffffff',
    major_casing_early: '#d3dce8',
    major_casing_late: '#d3dce8',
    // Highways — faint warm tint nods to brand amber without shouting.
    highway: '#fdeecb',
    highway_casing_early: '#ecd79a',
    highway_casing_late: '#ecd79a',

    // Labels — brand navy with white halos.
    city_label: '#0b2c5e',
    city_label_halo: '#ffffff',
    state_label: '#1c4c8c',
    state_label_halo: '#eef2f7',
    country_label: '#143c73',
    subplace_label: '#5a6577',
    subplace_label_halo: '#ffffff',
    roads_label_major: '#3a4658',
    roads_label_major_halo: '#ffffff',
    roads_label_minor: '#6b7688',
    roads_label_minor_halo: '#ffffff',
    ocean_label: '#5a7fa3',

    // Boundaries — muted navy-grey.
    boundaries: '#9fb0c9',
};

/**
 * Build the shared MapLibre style for every map in the app. Pass a flavor
 * override only for special cases (e.g. labels-only overlays).
 */
export function buildMapStyle(
    flavor: Flavor = brandedFlavor,
): StyleSpecification {
    return {
        version: 8,
        glyphs: GLYPHS,
        sprite: SPRITE,
        sources: {
            protomaps: {
                type: 'vector',
                url: TILES_URL,
                attribution: ATTRIBUTION,
            },
        },
        layers: layers('protomaps', flavor, { lang: 'nl' }),
    };
}
