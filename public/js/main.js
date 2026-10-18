import { BASEMAPS, MIN_PARCEL_ZOOM } from './config.js';
import { createDetailPanel } from './detail.js';
import { createIdentify } from './identify.js';
import { createMap, enableHover, enableMapStatus, renderLegend } from './map.js';
import { createSearch } from './search.js';
import { element, hashParam, isMobile, setHashParam } from './util.js';

function init() {
    if (typeof maplibregl === 'undefined') {
        document.body.replaceChildren(
            element('p', 'Knihovnu mapy se nepodařilo načíst. Zkontrolujte připojení k internetu.', 'error'),
        );
        return;
    }

    const sharedParcel = hashParam('parcel');
    const sharedPosition = hashParam('map') !== null;

    if (isMobile()) {
        for (const panel of document.querySelectorAll('[data-collapse-on-mobile]')) {
            panel.open = false;
        }
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

    const detail = createDetailPanel(map);
    const identify = createIdentify(map, detail);
    map.on('click', (event) => {
        if (identify.handlesClick(event)) {
            return;
        }
        const [feature] = map.queryRenderedFeatures(event.point, { layers: ['parcels-fill'] });
        if (feature?.id === undefined) {
            detail.close();
            return;
        }
        detail.open(feature.id, { reveal: event.lngLat });
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
    enableHover(map);

    enableMapStatus(map);
}

init();
