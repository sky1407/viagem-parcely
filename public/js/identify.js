import { IDENTIFY_HINTS, MAX_DRAWN_VERTICES, MIN_DRAWN_VERTICES } from './config.js';
import { element, numberFormat } from './util.js';

function parcelCount(count) {
    if (count === 1) {
        return '1 parcela';
    }
    return `${numberFormat.format(count)} ${count >= 2 && count <= 4 ? 'parcely' : 'parcel'}`;
}

function renderIntersected(kind, result, detail) {
    const ids = result.parcels.map((parcel) => parcel.id);
    const total = result.parcels.reduce((sum, parcel) => sum + parcel.area_m2, 0);
    const nodes = [element('h2', kind === 'polygon' ? 'Parcely v oblasti' : 'Parcely na linii')];

    if (result.parcels.length === 0) {
        nodes.push(element('p', 'Nakreslený tvar nezasahuje žádnou parcelu.'));
        return { nodes, ids };
    }

    let summary = `${parcelCount(result.parcels.length)}, celkem ${numberFormat.format(total)} m²`;
    if (result.truncated) {
        summary += '. Zobrazeno jen prvních 500, zmenšete oblast.';
    }
    nodes.push(element('p', summary, 'results-summary'));

    const list = element('ul', undefined, 'results');
    for (const parcel of result.parcels) {
        const button = element('button');
        button.type = 'button';
        button.append(
            element('span', parcel.number),
            element('span', `${numberFormat.format(parcel.area_m2)} m²`),
            element('small', `${parcel.cadastral_area} · ${parcel.land_type}`),
        );
        button.addEventListener('click', () => {
            detail.open(parcel.id, { fly: true, back: () => detail.show(nodes, ids) });
        });
        const item = element('li');
        item.append(button);
        list.append(item);
    }
    nodes.push(list);

    return { nodes, ids };
}

export function createIdentify(map, detail) {
    const buttons = [...document.querySelectorAll('.segmented [data-mode]')];
    const hint = document.getElementById('identify-hint');
    const actions = document.getElementById('identify-actions');
    const finishButton = document.getElementById('identify-finish');
    let mode = 'point';
    let vertices = [];
    let cursor = null;
    let finished = false;
    let request = null;

    const draw = () => {
        const features = vertices.map((coordinates) => ({ type: 'Feature', geometry: { type: 'Point', coordinates } }));
        const path = cursor !== null ? [...vertices, cursor] : vertices;
        if (mode === 'polygon' && path.length >= 3) {
            features.push({ type: 'Feature', geometry: { type: 'Polygon', coordinates: [[...path, path[0]]] } });
        } else if (path.length >= 2) {
            features.push({ type: 'Feature', geometry: { type: 'LineString', coordinates: path } });
        }
        map.getSource('drawing')?.setData({ type: 'FeatureCollection', features });
        finishButton.disabled = finished || vertices.length < (MIN_DRAWN_VERTICES[mode] ?? Infinity);
    };

    const reset = () => {
        request?.abort();
        vertices = [];
        cursor = null;
        finished = false;
        hint.textContent = IDENTIFY_HINTS[mode];
        draw();
    };

    const setMode = (next) => {
        mode = next;
        for (const button of buttons) {
            button.setAttribute('aria-pressed', String(button.dataset.mode === mode));
        }
        actions.hidden = mode === 'point';
        map.getContainer().classList.toggle('drawing', mode !== 'point');
        if (mode === 'point') {
            map.doubleClickZoom.enable();
        } else {
            map.doubleClickZoom.disable();
        }
        reset();
    };

    const finish = async () => {
        if (finished || vertices.length < (MIN_DRAWN_VERTICES[mode] ?? Infinity)) {
            return;
        }
        finished = true;
        cursor = null;
        draw();

        const kind = mode;
        const coordinates = vertices.map(([lng, lat]) => [Number(lng.toFixed(6)), Number(lat.toFixed(6))]);
        const geometry = kind === 'polygon'
            ? { type: 'Polygon', coordinates: [[...coordinates, coordinates[0]]] }
            : { type: 'LineString', coordinates };

        request?.abort();
        const controller = new AbortController();
        request = controller;
        detail.show([element('p', 'Hledám parcely…')], []);
        try {
            const response = await fetch('/api/parcels/intersect', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(geometry),
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error(response.status === 400 ? 'Nakreslete tvar uvnitř okresu Jičín.' : `Chyba serveru (${response.status}).`);
            }
            const { nodes, ids } = renderIntersected(kind, await response.json(), detail);
            detail.show(nodes, ids);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error(error);
            detail.show([element('p', error.message || 'Parcely se nepodařilo najít.', 'status-error')], []);
        }
    };

    for (const button of buttons) {
        button.addEventListener('click', () => setMode(button.dataset.mode));
    }
    finishButton.addEventListener('click', finish);
    document.getElementById('identify-cancel').addEventListener('click', () => {
        reset();
        detail.close();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mode !== 'point') {
            reset();
        }
    });
    map.on('mousemove', (event) => {
        if (mode === 'point' || finished || vertices.length === 0) {
            return;
        }
        cursor = [event.lngLat.lng, event.lngLat.lat];
        draw();
    });
    map.on('dblclick', (event) => {
        if (mode !== 'point') {
            event.preventDefault();
            finish();
        }
    });

    return {
        handlesClick(event) {
            if (mode === 'point') {
                return false;
            }
            if (event.originalEvent.detail > 1) {
                return true;
            }
            if (finished) {
                reset();
            }
            if (vertices.length >= MAX_DRAWN_VERTICES) {
                hint.textContent = `Nejvýše ${MAX_DRAWN_VERTICES} bodů. Tvar dokončete.`;
                return true;
            }
            vertices.push([event.lngLat.lng, event.lngLat.lat]);
            draw();
            return true;
        },
    };
}
