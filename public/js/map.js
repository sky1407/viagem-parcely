import {
    API_VERSION,
    AREA_SELECTION_COLOR,
    BASEMAPS,
    DISTRICT_BOUNDS,
    DISTRICT_COLOR,
    DRAW_COLOR,
    EMPTY_DRAWING,
    ERROR_VISIBLE_MS,
    LABEL_ZOOM,
    LAND_TYPES,
    LOADING_DELAY_MS,
    MIN_PARCEL_ZOOM,
    NOTHING_SELECTED,
    SELECTION_COLOR,
    UNKNOWN_LAND_TYPE_COLOR,
} from './config.js';
import { element, isMobile } from './util.js';

export function renderLegend() {
    const list = document.getElementById('legend-items');
    for (const [, name, color] of LAND_TYPES) {
        const swatch = element('span', undefined, 'swatch');
        swatch.style.background = color;
        const item = element('li');
        item.append(swatch, name);
        list.append(item);
    }
}

export function createMap() {
    const landTypeColor = ['match', ['get', 'land_type_code']];
    for (const [code, , color] of LAND_TYPES) {
        landTypeColor.push(code, color);
    }
    landTypeColor.push(UNKNOWN_LAND_TYPE_COLOR);

    return new maplibregl.Map({
        container: 'map',
        bounds: DISTRICT_BOUNDS,
        fitBoundsOptions: { padding: { top: 20, bottom: 20, right: 60, left: isMobile() ? 20 : 280 } },
        minZoom: 8,
        maxZoom: 20,
        hash: 'map',
        style: {
            version: 8,
            glyphs: `${location.origin}/fonts/{fontstack}/{range}.pbf`,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    maxzoom: 19,
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                },
                orthophoto: {
                    type: 'raster',
                    tiles: ['https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/tile/{z}/{y}/{x}'],
                    tileSize: 256,
                    maxzoom: 20,
                    attribution: '© <a href="https://cuzk.gov.cz">ČÚZK</a> ortofoto',
                },
                cadastre: {
                    type: 'vector',
                    tiles: [`${location.origin}/api/tiles/{z}/{x}/{y}.pbf?v=${API_VERSION}`],
                    maxzoom: 16,
                    attribution: '© <a href="https://cuzk.gov.cz">ČÚZK</a>',
                },
                drawing: { type: 'geojson', data: EMPTY_DRAWING },
            },
            layers: [
                { id: 'osm', type: 'raster', source: 'osm' },
                { id: 'orthophoto', type: 'raster', source: 'orthophoto', layout: { visibility: 'none' } },
                {
                    id: 'parcels-fill',
                    type: 'fill',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    paint: { 'fill-color': landTypeColor, 'fill-opacity': BASEMAPS.osm.parcelOpacity },
                },
                {
                    id: 'parcels-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    paint: {
                        'line-color': '#5b4636',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 14, 0.3, 18, 1.2],
                    },
                },
                {
                    id: 'parcels-hover',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    paint: {
                        'line-color': '#1f3a5f',
                        'line-width': ['case', ['boolean', ['feature-state', 'hover'], false], 2.5, 0],
                    },
                },
                {
                    id: 'cadastral-areas-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'cadastral_areas',
                    paint: {
                        'line-color': '#b03a2e',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1, 14, 2.5],
                    },
                },
                {
                    id: 'district-casing',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'district',
                    layout: { 'line-join': 'round' },
                    paint: {
                        'line-color': '#ffffff',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 6, 14, 10],
                        'line-opacity': 0.9,
                    },
                },
                {
                    id: 'district-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'district',
                    layout: { 'line-join': 'round' },
                    paint: {
                        'line-color': DISTRICT_COLOR,
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 3, 14, 5],
                    },
                },
                {
                    id: 'cadastral-area-selected-casing',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'cadastral_areas',
                    filter: NOTHING_SELECTED,
                    layout: { 'line-join': 'round' },
                    paint: {
                        'line-color': '#ffffff',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 5, 14, 9],
                        'line-opacity': 0.9,
                    },
                },
                {
                    id: 'cadastral-area-selected-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'cadastral_areas',
                    filter: NOTHING_SELECTED,
                    layout: { 'line-join': 'round' },
                    paint: {
                        'line-color': AREA_SELECTION_COLOR,
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 2.5, 14, 5],
                    },
                },
                {
                    id: 'parcels-selected-fill',
                    type: 'fill',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    filter: NOTHING_SELECTED,
                    paint: { 'fill-color': SELECTION_COLOR, 'fill-opacity': 0.45 },
                },
                {
                    id: 'parcels-selected-casing',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    filter: NOTHING_SELECTED,
                    layout: { 'line-join': 'round' },
                    paint: { 'line-color': '#ffffff', 'line-width': 8 },
                },
                {
                    id: 'parcels-selected-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    filter: NOTHING_SELECTED,
                    layout: { 'line-join': 'round' },
                    paint: { 'line-color': SELECTION_COLOR, 'line-width': 4 },
                },
                {
                    id: 'parcel-labels',
                    type: 'symbol',
                    source: 'cadastre',
                    'source-layer': 'parcel_labels',
                    minzoom: LABEL_ZOOM,
                    layout: {
                        'text-field': ['get', 'label'],
                        'text-font': ['NotoSansMedium'],
                        'text-size': ['interpolate', ['linear'], ['zoom'], LABEL_ZOOM, 11, 19, 15],
                        'text-padding': 1,
                    },
                    paint: {
                        'text-color': '#2b2b2b',
                        'text-halo-color': 'rgba(255, 255, 255, 0.9)',
                        'text-halo-width': 1.5,
                    },
                },
                {
                    id: 'drawing-fill',
                    type: 'fill',
                    source: 'drawing',
                    filter: ['==', ['geometry-type'], 'Polygon'],
                    paint: { 'fill-color': DRAW_COLOR, 'fill-opacity': 0.12 },
                },
                {
                    id: 'drawing-line',
                    type: 'line',
                    source: 'drawing',
                    filter: ['!=', ['geometry-type'], 'Point'],
                    layout: { 'line-join': 'round' },
                    paint: { 'line-color': DRAW_COLOR, 'line-width': 2.5, 'line-dasharray': [2, 1] },
                },
                {
                    id: 'drawing-vertices',
                    type: 'circle',
                    source: 'drawing',
                    filter: ['==', ['geometry-type'], 'Point'],
                    paint: {
                        'circle-radius': 4,
                        'circle-color': '#ffffff',
                        'circle-stroke-color': DRAW_COLOR,
                        'circle-stroke-width': 2,
                    },
                },
            ],
        },
    });
}

export function enableMapStatus(map) {
    const status = document.getElementById('map-status');
    let loadingTimer = null;
    let errorTimer = null;

    const show = (message, isError) => {
        status.textContent = message;
        status.classList.toggle('status-error', isError);
        status.hidden = false;
    };

    map.on('sourcedataloading', (event) => {
        if (event.sourceId !== 'cadastre' || loadingTimer !== null || errorTimer !== null) {
            return;
        }
        loadingTimer = setTimeout(() => show('Načítám parcely…', false), LOADING_DELAY_MS);
    });

    map.on('idle', () => {
        clearTimeout(loadingTimer);
        loadingTimer = null;
        if (errorTimer === null) {
            status.hidden = true;
        }
    });

    map.on('error', (event) => {
        console.error(event.error);
        if (event.sourceId !== 'cadastre') {
            return;
        }
        clearTimeout(loadingTimer);
        loadingTimer = null;
        clearTimeout(errorTimer);
        show('Data parcel se nepodařilo načíst. Zkuste to prosím později.', true);
        errorTimer = setTimeout(() => {
            errorTimer = null;
            status.hidden = true;
        }, ERROR_VISIBLE_MS);
    });
}

export function enableHover(map) {
    const tooltip = new maplibregl.Popup({ closeButton: false, closeOnClick: false, className: 'parcel-tooltip', offset: 14 });
    let hovered = null;

    const setHovered = (id) => {
        if (hovered === id) {
            return;
        }
        if (hovered !== null) {
            map.setFeatureState({ source: 'cadastre', sourceLayer: 'parcels', id: hovered }, { hover: false });
        }
        hovered = id;
        if (id !== null) {
            map.setFeatureState({ source: 'cadastre', sourceLayer: 'parcels', id }, { hover: true });
        }
    };

    map.on('mousemove', 'parcels-fill', (event) => {
        const [feature] = event.features ?? [];
        if (feature?.id === undefined) {
            return;
        }
        setHovered(feature.id);
        map.getCanvas().style.cursor = 'pointer';

        const label = feature.properties.label;
        if (label === undefined) {
            tooltip.remove();
            return;
        }
        tooltip.setLngLat(event.lngLat).setText(label).addTo(map);
    });

    map.on('mouseleave', 'parcels-fill', () => {
        setHovered(null);
        tooltip.remove();
        map.getCanvas().style.cursor = '';
    });
}
