import { MAP_MARGIN, MIN_PARCEL_ZOOM, MOBILE_QUERY } from './config.js';

export const numberFormat = new Intl.NumberFormat('cs-CZ');
const dateFormat = new Intl.DateTimeFormat('cs-CZ');

export function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) {
        node.textContent = text;
    }
    if (className !== undefined) {
        node.className = className;
    }
    return node;
}

export function hashParam(name) {
    for (const part of location.hash.slice(1).split('&')) {
        const [key, value] = part.split('=');
        if (key === name) {
            return decodeURIComponent(value ?? '');
        }
    }
    return null;
}

export function setHashParam(name, value) {
    const parts = location.hash.slice(1).split('&').filter((part) => part !== '' && part.split('=')[0] !== name);
    if (value !== null) {
        parts.push(`${name}=${encodeURIComponent(value)}`);
    }
    history.replaceState(history.state, '', `#${parts.join('&')}`);
}

export function isMobile() {
    return window.matchMedia(MOBILE_QUERY).matches;
}

export function visiblePadding() {
    const padding = { top: MAP_MARGIN, right: MAP_MARGIN, bottom: MAP_MARGIN, left: MAP_MARGIN };
    const controls = document.querySelector('.controls').getBoundingClientRect();
    const detail = document.getElementById('detail');
    const panel = detail.hidden ? null : detail.getBoundingClientRect();

    if (isMobile()) {
        padding.top += controls.bottom;
        padding.bottom += panel ? window.innerHeight - panel.top : 0;
    } else {
        padding.left += controls.right;
        padding.right += panel ? window.innerWidth - panel.left : 0;
    }
    return padding;
}

export function flyToParcel(map, bbox) {
    if (!Array.isArray(bbox) || bbox.length !== 4) {
        return;
    }
    const camera = map.cameraForBounds(bbox, { padding: visiblePadding(), maxZoom: 18 });
    if (camera === undefined) {
        map.flyTo({ center: [(bbox[0] + bbox[2]) / 2, (bbox[1] + bbox[3]) / 2], zoom: Math.max(map.getZoom(), MIN_PARCEL_ZOOM) });
        return;
    }
    map.flyTo({ ...camera, zoom: Math.max(camera.zoom, MIN_PARCEL_ZOOM) });
}

export function revealPoint(map, lngLat) {
    const padding = visiblePadding();
    const point = map.project(lngLat);
    const canvas = map.getCanvas().getBoundingClientRect();
    const covered = point.x < padding.left || point.x > canvas.width - padding.right
        || point.y < padding.top || point.y > canvas.height - padding.bottom;
    if (covered) {
        map.easeTo({ center: lngLat, offset: [(padding.left - padding.right) / 2, (padding.top - padding.bottom) / 2] });
    }
}

export function formatDate(isoDate) {
    const [year, month, day] = isoDate.split('-').map(Number);
    return dateFormat.format(new Date(year, month - 1, day));
}
