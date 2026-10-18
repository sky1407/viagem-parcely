import { createAutocomplete, parcelNumberKey, searchKey } from './autocomplete.js';
import { API_VERSION, AREA_MAX_ZOOM, AREA_SELECTION_LAYERS, NOTHING_SELECTED } from './config.js';
import { isMobile, visiblePadding } from './util.js';

export async function createSearch(map, detail) {
    const form = document.getElementById('search');
    const areaInput = document.getElementById('search-area');
    const numberInput = document.getElementById('search-number');
    const button = form.querySelector('button[type="submit"]');
    const status = document.getElementById('search-status');
    const lock = document.getElementById('area-lock');
    const lockName = document.getElementById('area-lock-name');
    const combobox = document.getElementById('area-combobox');
    const areas = new Map();
    const parcelLists = new Map();
    let lockedArea = null;
    let parcelsRequest = null;

    const showStatus = (message) => {
        status.textContent = message;
        status.hidden = message === '';
    };

    const collapseOnMobile = () => {
        if (isMobile()) {
            form.closest('details').open = false;
            document.activeElement?.blur();
        }
    };

    const numberAutocomplete = createAutocomplete(
        numberInput,
        document.getElementById('number-suggestions'),
        document.getElementById('number-toggle'),
        {
            key: parcelNumberKey,
            label: (parcel) => parcel.number,
            note: () => null,
            emptyText: 'Žádná parcela neodpovídá',
            onChoose: (parcel) => {
                showStatus('');
                collapseOnMobile();
                detail.open(parcel.id, { fly: true });
            },
        },
    );

    const loadParcels = async (area) => {
        parcelsRequest?.abort();
        numberAutocomplete.setItems([]);
        const cached = parcelLists.get(area.code);
        if (cached !== undefined) {
            numberAutocomplete.setItems(cached);
            return;
        }

        const controller = new AbortController();
        parcelsRequest = controller;
        try {
            const response = await fetch(`/api/cadastral-areas/${area.code}/parcels?v=${API_VERSION}`, { signal: controller.signal });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const parcels = await response.json();
            parcelLists.set(area.code, parcels);
            if (lockedArea === area) {
                numberAutocomplete.setItems(parcels);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
            }
        }
    };

    const highlightArea = (filter) => {
        for (const id of AREA_SELECTION_LAYERS) {
            map.setFilter(id, filter);
        }
    };

    const lockArea = (area, { fit }) => {
        lockedArea = area;
        areaInput.value = area.name;
        lockName.textContent = area.municipality === area.name ? area.name : `${area.name} (obec ${area.municipality})`;
        lock.title = lockName.textContent;
        lock.hidden = false;
        combobox.hidden = true;
        highlightArea(['==', ['id'], area.code]);
        loadParcels(area);
        showStatus('');
        if (fit) {
            map.fitBounds(area.bbox, { padding: visiblePadding(), maxZoom: AREA_MAX_ZOOM });
            numberInput.focus();
        }
    };

    const unlockArea = () => {
        lockedArea = null;
        areaInput.value = '';
        lock.hidden = true;
        combobox.hidden = false;
        highlightArea(NOTHING_SELECTED);
        parcelsRequest?.abort();
        numberAutocomplete.setItems([]);
        areaInput.focus();
    };

    document.getElementById('area-unlock').addEventListener('click', unlockArea);

    try {
        const response = await fetch(`/api/cadastral-areas?v=${API_VERSION}`);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        const list = await response.json();
        for (const area of list) {
            areas.set(searchKey(area.name), area);
        }
        createAutocomplete(
            areaInput,
            document.getElementById('area-suggestions'),
            document.getElementById('area-toggle'),
            {
                key: searchKey,
                label: (area) => area.name,
                note: (area) => (area.municipality !== area.name ? `obec ${area.municipality}` : null),
                emptyText: 'Žádné katastrální území neodpovídá',
                onChoose: (area) => lockArea(area, { fit: true }),
            },
        ).setItems(list);
    } catch (error) {
        console.error(error);
        showStatus('Seznam katastrálních území se nepodařilo načíst.');
        button.disabled = true;
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const area = lockedArea ?? areas.get(searchKey(areaInput.value));
        if (area === undefined) {
            showStatus('Vyberte katastrální území ze seznamu.');
            return;
        }
        if (lockedArea === null) {
            lockArea(area, { fit: false });
        }

        showStatus('');
        button.disabled = true;
        try {
            const query = new URLSearchParams({ cadastral_area: area.code, number: numberInput.value, v: API_VERSION });
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
            collapseOnMobile();
            detail.open(parcel.id, { fly: true });
        } catch (error) {
            console.error(error);
            showStatus('Hledání se nepodařilo, zkuste to znovu.');
        } finally {
            button.disabled = false;
        }
    });
}
