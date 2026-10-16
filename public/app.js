'use strict';

const DISTRICT_BOUNDS = [[15.104, 50.275], [15.742, 50.547]];
const MIN_PARCEL_ZOOM = 14;
const LABEL_ZOOM = 17;

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
const MAX_SUGGESTIONS = 8;
const NOTHING_SELECTED = ['==', ['id'], -1];
const SELECTION_LAYERS = ['parcels-selected-fill', 'parcels-selected-casing', 'parcels-selected-line'];
const SELECTION_COLOR = '#00b4ff';
const DISTRICT_COLOR = '#ff00a8';
const BASEMAPS = {
    osm: { parcelOpacity: 0.55 },
    orthophoto: { parcelOpacity: 0.2 },
};

const numberFormat = new Intl.NumberFormat('cs-CZ');
const dateFormat = new Intl.DateTimeFormat('cs-CZ');

function hashParam(name) {
    for (const part of location.hash.slice(1).split('&')) {
        const [key, value] = part.split('=');
        if (key === name) {
            return decodeURIComponent(value ?? '');
        }
    }
    return null;
}

function setHashParam(name, value) {
    const parts = location.hash.slice(1).split('&').filter((part) => part !== '' && part.split('=')[0] !== name);
    if (value !== null) {
        parts.push(`${name}=${encodeURIComponent(value)}`);
    }
    history.replaceState(history.state, '', `#${parts.join('&')}`);
}

function flyToParcel(map, bbox) {
    const camera = map.cameraForBounds(bbox, { padding: 80, maxZoom: 18 });
    map.flyTo({ ...camera, zoom: Math.max(camera?.zoom ?? MIN_PARCEL_ZOOM, MIN_PARCEL_ZOOM) });
}

function formatDate(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);
    return dateFormat.format(new Date(year, month - 1, day));
}

function renderLegend() {
    const list = document.getElementById('legend-items');
    for (const [, name, color] of LAND_TYPES) {
        const swatch = element('span', undefined, 'swatch');
        swatch.style.background = color;
        const item = element('li');
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
        fitBoundsOptions: { padding: { top: 20, bottom: 20, right: 60, left: window.innerWidth > 600 ? 280 : 20 } },
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
                    tiles: [`${location.origin}/api/tiles/{z}/{x}/{y}.pbf`],
                    maxzoom: 16,
                    attribution: '© <a href="https://cuzk.gov.cz">ČÚZK</a>',
                },
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
            ],
        },
    });
}

function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) {
        node.textContent = text;
    }
    if (className !== undefined) {
        node.className = className;
    }
    return node;
}

function renderParcel(parcel) {
    const rows = [
        ['Katastrální území', `${parcel.cadastral_area.name} (${parcel.cadastral_area.code})`],
        ['Obec', parcel.municipality.name],
        ['Výměra', `${numberFormat.format(parcel.area_m2)} m²`],
        ['Druh pozemku', parcel.land_type],
        ['Způsob využití', parcel.land_use ?? '—'],
        ['Platí od', formatDate(parcel.valid_from)],
    ];

    const list = element('dl');
    for (const [label, value] of rows) {
        list.append(element('dt', label), element('dd', value));
    }

    const protections = element('dd');
    if (parcel.protections.length === 0) {
        protections.textContent = '—';
    } else {
        const items = element('ul');
        for (const name of parcel.protections) {
            items.append(element('li', name));
        }
        protections.append(items);
    }
    list.append(element('dt', 'Ochrana'), protections);

    const link = element('a', 'Otevřít v Nahlížení do KN');
    link.href = parcel.cadastre_url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';

    return [element('h2', `Parcela ${parcel.number}`), list, link];
}

function createDetailPanel(map) {
    const panel = document.getElementById('detail');
    const content = document.getElementById('detail-content');
    let request = null;

    const select = (filter) => {
        for (const layer of SELECTION_LAYERS) {
            map.setFilter(layer, filter);
        }
    };

    const close = () => {
        request?.abort();
        request = null;
        panel.hidden = true;
        select(NOTHING_SELECTED);
        setHashParam('parcel', null);
    };

    const open = async (id, { fly = false } = {}) => {
        request?.abort();
        const controller = new AbortController();
        request = controller;

        select(['==', ['id'], id]);
        setHashParam('parcel', String(id));
        content.replaceChildren(element('p', 'Načítám…'));
        panel.hidden = false;

        try {
            const response = await fetch(`/api/parcels/${encodeURIComponent(id)}`, { signal: controller.signal });
            if (!response.ok) {
                throw new Error(response.status === 404 ? 'Parcela nebyla nalezena.' : `Chyba serveru (${response.status}).`);
            }
            const parcel = await response.json();
            content.replaceChildren(...renderParcel(parcel));
            if (fly) {
                flyToParcel(map, parcel.bbox);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error(error);
            content.replaceChildren(element('p', error.message || 'Detail se nepodařilo načíst.', 'status-error'));
        }
    };

    document.getElementById('detail-close').addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });

    return { open, close };
}

function withoutDiacritics(text) {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLocaleLowerCase('cs');
}

function searchKey(text) {
    return withoutDiacritics(text).trim();
}

function highlightMatch(name, query) {
    const key = withoutDiacritics(name);
    const start = key.indexOf(query);
    if (start === -1 || key.length !== name.length) {
        return [name];
    }
    const end = start + query.length;
    return [name.slice(0, start), element('mark', name.slice(start, end)), name.slice(end)];
}

function createAreaAutocomplete(input, list, areas, onChoose) {
    const entries = areas.map((area) => ({ area, key: searchKey(area.name) }));
    let matches = [];
    let active = -1;

    const setActive = (index) => {
        active = index;
        for (const [i, item] of [...list.children].entries()) {
            item.setAttribute('aria-selected', String(i === index));
        }
        if (index === -1) {
            input.removeAttribute('aria-activedescendant');
            return;
        }
        input.setAttribute('aria-activedescendant', list.children[index].id);
        list.children[index].scrollIntoView({ block: 'nearest' });
    };

    const close = () => {
        matches = [];
        setActive(-1);
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
    };

    const choose = (area) => {
        input.value = area.name;
        close();
        onChoose(area);
    };

    const render = () => {
        const query = searchKey(input.value);
        if (query === '') {
            close();
            return;
        }

        matches = entries
            .filter((entry) => entry.key.includes(query))
            .sort((a, b) => Number(!a.key.startsWith(query)) - Number(!b.key.startsWith(query)))
            .slice(0, MAX_SUGGESTIONS)
            .map((entry) => entry.area);

        const items = matches.map((area, index) => {
            const item = element('li');
            item.id = `area-option-${index}`;
            item.setAttribute('role', 'option');
            item.append(...highlightMatch(area.name, query));
            if (area.municipality !== area.name) {
                item.append(element('small', `obec ${area.municipality}`));
            }
            item.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(area);
            });
            return item;
        });
        if (items.length === 0) {
            items.push(element('li', 'Žádné katastrální území neodpovídá', 'empty'));
        }

        list.replaceChildren(...items);
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        setActive(matches.length > 0 ? 0 : -1);
    };

    input.addEventListener('input', render);
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (event) => {
        if (list.hidden) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                render();
            }
            return;
        }

        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && matches.length > 0) {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            setActive((active + step + matches.length) % matches.length);
        } else if (event.key === 'Enter' && active !== -1) {
            event.preventDefault();
            choose(matches[active]);
        } else if (event.key === 'Escape') {
            event.stopPropagation();
            close();
        }
    });
}

async function createSearch(map, detail) {
    const form = document.getElementById('search');
    const areaInput = document.getElementById('search-area');
    const numberInput = document.getElementById('search-number');
    const button = form.querySelector('button');
    const status = document.getElementById('search-status');
    const areas = new Map();

    const showStatus = (message) => {
        status.textContent = message;
        status.hidden = message === '';
    };

    try {
        const response = await fetch('/api/cadastral-areas');
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const list = await response.json();
        for (const area of list) {
            areas.set(searchKey(area.name), area);
        }
        createAreaAutocomplete(areaInput, document.getElementById('area-suggestions'), list, () => numberInput.focus());
    } catch (error) {
        console.error(error);
        showStatus('Seznam katastrálních území se nepodařilo načíst.');
        button.disabled = true;
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const area = areas.get(searchKey(areaInput.value));
        if (area === undefined) {
            showStatus('Vyberte katastrální území ze seznamu.');
            return;
        }

        showStatus('');
        button.disabled = true;
        try {
            const query = new URLSearchParams({ cadastral_area: area.code, number: numberInput.value });
            const response = await fetch(`/api/parcels/search?${query}`);
            if (response.status === 404) {
                showStatus(`Parcela ${numberInput.value.trim()} v k. ú. ${area.name} neexistuje.`);
                return;
            }
            if (response.status === 400) {
                showStatus('Zadejte číslo ve tvaru „st. 12/3“ nebo „791/34“.');
                return;
            }
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const parcel = await response.json();
            flyToParcel(map, parcel.bbox);
            detail.open(parcel.id);
        } catch (error) {
            console.error(error);
            showStatus('Hledání se nepodařilo, zkuste to znovu.');
        } finally {
            button.disabled = false;
        }
    });
}

function init() {
    if (typeof maplibregl === 'undefined') {
        document.body.replaceChildren(
            element('p', 'Knihovnu mapy se nepodařilo načíst. Zkontrolujte připojení k internetu.', 'error'),
        );
        return;
    }

    const sharedParcel = hashParam('parcel');
    const sharedPosition = hashParam('map') !== null;

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

    const detail = createDetailPanel(map);
    map.on('click', (event) => {
        const [feature] = map.queryRenderedFeatures(event.point, { layers: ['parcels-fill'] });
        if (feature?.id === undefined) {
            detail.close();
            return;
        }
        detail.open(feature.id);
    });
    if (sharedParcel !== null && /^\d{1,18}$/.test(sharedParcel)) {
        map.once('load', () => detail.open(Number(sharedParcel), { fly: !sharedPosition }));
    } else if (sharedParcel !== null) {
        setHashParam('parcel', null);
    }
    createSearch(map, detail);
    for (const input of document.querySelectorAll('input[name="basemap"]')) {
        input.addEventListener('change', () => {
            for (const id of Object.keys(BASEMAPS)) {
                map.setLayoutProperty(id, 'visibility', id === input.value ? 'visible' : 'none');
            }
            map.setPaintProperty('parcels-fill', 'fill-opacity', BASEMAPS[input.value].parcelOpacity);
        });
    }
    map.on('mouseenter', 'parcels-fill', () => {
        map.getCanvas().style.cursor = 'pointer';
    });
    map.on('mouseleave', 'parcels-fill', () => {
        map.getCanvas().style.cursor = '';
    });

    map.on('error', (event) => console.error(event.error));
}

init();
