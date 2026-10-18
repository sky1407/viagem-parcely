export const DISTRICT_BOUNDS = [[15.104, 50.275], [15.742, 50.547]];
export const API_VERSION = '3';
export const MIN_PARCEL_ZOOM = 14;
export const LABEL_ZOOM = 17;

export const LAND_TYPES = [
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
export const UNKNOWN_LAND_TYPE_COLOR = '#bbbbbb';
export const MAX_SUGGESTIONS = 8;
export const MAX_LIST_ITEMS = 300;
export const COPY_FEEDBACK_MS = 1500;
export const MOBILE_QUERY = '(max-width: 600px)';
export const MAP_MARGIN = 40;
export const LOADING_DELAY_MS = 300;
export const ERROR_VISIBLE_MS = 6000;
export const NOTHING_SELECTED = ['==', ['id'], -1];
export const SELECTION_LAYERS = ['parcels-selected-fill', 'parcels-selected-casing', 'parcels-selected-line'];
export const SELECTION_COLOR = '#00b4ff';
export const DISTRICT_COLOR = '#ff00a8';
export const AREA_SELECTION_LAYERS = ['cadastral-area-selected-casing', 'cadastral-area-selected-line'];
export const AREA_SELECTION_COLOR = '#e8590c';
export const AREA_MAX_ZOOM = 15;
export const DRAW_COLOR = '#1f3a5f';
export const MAX_DRAWN_VERTICES = 200;
export const MIN_DRAWN_VERTICES = { line: 2, polygon: 3 };
export const IDENTIFY_HINTS = {
    point: 'Klikněte na parcelu v mapě.',
    line: 'Klikáním zadejte body linie. Dvojklikem nebo tlačítkem Dokončit ji ukončete.',
    polygon: 'Klikáním zadejte vrcholy oblasti. Dvojklikem nebo tlačítkem Dokončit ji uzavřete.',
};
export const EMPTY_DRAWING = { type: 'FeatureCollection', features: [] };
export const BASEMAPS = {
    osm: { parcelOpacity: 0.55 },
    orthophoto: { parcelOpacity: 0.2 },
};
