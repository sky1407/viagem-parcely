'use strict';

const DISTRICT_BOUNDS = [[15.104, 50.275], [15.742, 50.547]];
const MIN_PARCEL_ZOOM = 14;

const LAND_TYPES = [
    [2, 'orná půda', '#f2e19b'],
    [3, 'chmelnice', '#c9d98a'],
    [4, 'vinice', '#b98fc9'],
    [5, 'zahrada', '#a6d98c'],
    [6, 'ovocný sad', '#6fbf73'],
    [7, 'trvalý travní porost', '#cfe8a9'],
    [10, 'lesní pozemek', '#3f8f4f'],
    [11, 'vodní plocha', '#7fbfeb'],
    [13, 'zastavěná plocha a nádvoří', '#e39b8f'],
    [14, 'ostatní plocha', '#d6d1c4'],
];
const UNKNOWN_LAND_TYPE_COLOR = '#bbbbbb';

function renderLegend() {
    const list = document.getElementById('legend-items');
    for (const [, name, color] of LAND_TYPES) {
        const item = document.createElement('li');
        const swatch = document.createElement('span');
        swatch.className = 'swatch';
        swatch.style.background = color;
        item.append(swatch, name);
        list.append(item);
    }
}

function createMap() {
    const landTypeColor = ['match', ['get', 'land_type_code']];
    for (const [code, , color] of LAND_TYPES) {
        landTypeColor.push(code, color);
    }
    landTypeColor.push(UNKNOWN_LAND_TYPE_COLOR);

    return new maplibregl.Map({
        container: 'map',
        bounds: DISTRICT_BOUNDS,
        fitBoundsOptions: { padding: 20 },
        minZoom: 8,
        maxZoom: 20,
        hash: true,
        style: {
            version: 8,
            sources: {
                osm: {
                    type: 'raster',
                    tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                    tileSize: 256,
                    maxzoom: 19,
                    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                },
                cadastre: {
                    type: 'vector',
                    tiles: [`${location.origin}/api/tiles/{z}/{x}/{y}.pbf`],
                    maxzoom: 16,
                    attribution: '© <a href="https://cuzk.gov.cz">ČÚZK</a>',
                },
            },
            layers: [
                { id: 'osm', type: 'raster', source: 'osm' },
                {
                    id: 'parcels-fill',
                    type: 'fill',
                    source: 'cadastre',
                    'source-layer': 'parcels',
                    minzoom: MIN_PARCEL_ZOOM,
                    paint: { 'fill-color': landTypeColor, 'fill-opacity': 0.55 },
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
                    id: 'cadastral-areas-line',
                    type: 'line',
                    source: 'cadastre',
                    'source-layer': 'cadastral_areas',
                    paint: {
                        'line-color': '#b03a2e',
                        'line-width': ['interpolate', ['linear'], ['zoom'], 8, 1, 14, 2.5],
                    },
                },
            ],
        },
    });
}

function init() {
    if (typeof maplibregl === 'undefined') {
        const message = document.createElement('p');
        message.className = 'error';
        message.textContent = 'Knihovnu mapy se nepodařilo načíst. Zkontrolujte připojení k internetu.';
        document.body.replaceChildren(message);
        return;
    }

    renderLegend();
    const map = createMap();
    map.addControl(new maplibregl.NavigationControl(), 'top-right');
    map.addControl(new maplibregl.ScaleControl(), 'bottom-left');

    const hint = document.getElementById('zoom-hint');
    const updateHint = () => {
        hint.hidden = map.getZoom() >= MIN_PARCEL_ZOOM;
    };
    map.on('zoomend', updateHint);
    updateHint();

    map.on('error', (event) => console.error(event.error));
}

init();
