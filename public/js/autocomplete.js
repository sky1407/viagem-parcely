import { MAX_LIST_ITEMS, MAX_SUGGESTIONS } from './config.js';
import { element } from './util.js';

function withoutDiacritics(text) {
    return text.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLocaleLowerCase('cs');
}

export function searchKey(text) {
    return withoutDiacritics(text).trim();
}

export function parcelNumberKey(text) {
    return withoutDiacritics(text).replace(/[\s.]/g, '');
}

function highlightMatch(name, query) {
    const key = withoutDiacritics(name);
    const start = key.indexOf(query);
    if (query === '' || start === -1 || key.length !== name.length) {
        return [name];
    }
    const end = start + query.length;
    return [name.slice(0, start), element('mark', name.slice(start, end)), name.slice(end)];
}

export function createAutocomplete(input, list, toggle, { key, label, note, emptyText, onChoose }) {
    let entries = [];
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
        toggle.setAttribute('aria-expanded', 'false');
    };

    const choose = (item) => {
        input.value = label(item);
        close();
        onChoose(item);
    };

    const render = (showAll) => {
        const query = showAll ? '' : key(input.value);
        if (entries.length === 0 || (query === '' && !showAll)) {
            close();
            return;
        }

        const found = showAll
            ? entries
            : entries
                .filter((entry) => entry.key.includes(query))
                .sort((a, b) => Number(!a.key.startsWith(query)) - Number(!b.key.startsWith(query)));
        const limit = showAll ? MAX_LIST_ITEMS : MAX_SUGGESTIONS;
        matches = found.slice(0, limit).map((entry) => entry.item);

        const options = matches.map((match, index) => {
            const option = element('li');
            option.id = `${list.id}-${index}`;
            option.setAttribute('role', 'option');
            option.append(...highlightMatch(label(match), query));
            const text = note(match);
            if (text !== null) {
                option.append(element('small', text));
            }
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(match);
            });
            return option;
        });
        if (options.length === 0) {
            options.push(element('li', emptyText, 'empty'));
        } else if (showAll && found.length > limit) {
            options.push(element('li', `… a dalších ${found.length - limit}, pište pro zúžení`, 'empty'));
        }

        list.replaceChildren(...options);
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        toggle.setAttribute('aria-expanded', 'true');
        const current = key(input.value);
        setActive(showAll ? matches.findIndex((match) => key(label(match)) === current) : (matches.length > 0 ? 0 : -1));
    };

    input.addEventListener('input', () => render(false));
    input.addEventListener('blur', close);
    toggle.addEventListener('mousedown', (event) => event.preventDefault());
    toggle.addEventListener('click', () => {
        if (!list.hidden) {
            close();
            return;
        }
        input.focus();
        render(true);
    });
    input.addEventListener('keydown', (event) => {
        if (list.hidden) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                render(key(input.value) === '');
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

    return {
        setItems(items) {
            entries = items.map((item) => ({ item, key: key(label(item)) }));
            toggle.hidden = items.length === 0;
            if (document.activeElement === input) {
                render(false);
            } else {
                close();
            }
        },
    };
}
