import { API_VERSION, COPY_FEEDBACK_MS, NOTHING_SELECTED, SELECTION_LAYERS } from './config.js';
import { element, flyToParcel, formatDate, numberFormat, revealPoint, setHashParam } from './util.js';

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

    const actions = element('div', undefined, 'detail-actions');
    actions.append(
        copyButton('Kopírovat označení', `parc. č. ${parcel.number}, k. ú. ${parcel.cadastral_area.name} [${parcel.cadastral_area.code}]`),
        copyButton('Kopírovat odkaz', `${location.origin}/#parcel=${parcel.id}`),
    );

    const link = element('a', 'Otevřít v Nahlížení do KN');
    link.href = parcel.cadastre_url;
    link.target = '_blank';
    link.rel = 'noopener noreferrer';

    return [element('h2', `Parcela ${parcel.number}`), list, actions, link];
}

function copyButton(label, text) {
    const button = element('button', label);
    button.type = 'button';
    let reset = null;

    button.addEventListener('click', async () => {
        clearTimeout(reset);
        try {
            await navigator.clipboard.writeText(text);
            button.textContent = 'Zkopírováno ✓';
        } catch (error) {
            console.error(error);
            button.textContent = 'Kopírování selhalo';
        }
        reset = setTimeout(() => {
            button.textContent = label;
        }, COPY_FEEDBACK_MS);
    });

    return button;
}

export function createDetailPanel(map) {
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

    const open = async (id, { fly = false, reveal = null, back = null } = {}) => {
        request?.abort();
        const controller = new AbortController();
        request = controller;

        const header = [];
        if (back !== null) {
            const backButton = element('button', '← Zpět na výsledky', 'back-link');
            backButton.type = 'button';
            backButton.addEventListener('click', back);
            header.push(backButton);
        }

        select(['==', ['id'], id]);
        setHashParam('parcel', String(id));
        content.replaceChildren(...header, element('p', 'Načítám…'));
        panel.hidden = false;

        try {
            const response = await fetch(`/api/parcels/${encodeURIComponent(id)}?v=${API_VERSION}`, { signal: controller.signal });
            if (!response.ok) {
                throw new Error(response.status === 404 ? 'Parcela nebyla nalezena.' : `Chyba serveru (${response.status}).`);
            }
            const parcel = await response.json();
            content.replaceChildren(...header, ...renderParcel(parcel));
            if (fly) {
                flyToParcel(map, parcel.bbox);
            } else if (reveal !== null) {
                revealPoint(map, reveal);
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error(error);
            content.replaceChildren(...header, element('p', error.message || 'Detail se nepodařilo načíst.', 'status-error'));
        }
    };

    const show = (nodes, ids) => {
        request?.abort();
        request = null;
        select(ids.length > 0 ? ['in', ['id'], ['literal', ids]] : NOTHING_SELECTED);
        setHashParam('parcel', null);
        content.replaceChildren(...nodes);
        panel.hidden = false;
    };

    document.getElementById('detail-close').addEventListener('click', close);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close();
        }
    });

    return { open, show, close };
}
