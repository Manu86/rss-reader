import { ApiError, NetworkError } from '../api/client.js';

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
const BLOCKED_PROPERTIES = new Set(['inner' + 'HTML', 'outer' + 'HTML']);
const DOM_PROPERTIES = new Set([
    'accept',
    'alt',
    'autocomplete',
    'autofocus',
    'checked',
    'content',
    'disabled',
    'download',
    'hidden',
    'href',
    'id',
    'indeterminate',
    'max',
    'maxLength',
    'min',
    'multiple',
    'name',
    'open',
    'placeholder',
    'readOnly',
    'rel',
    'required',
    'role',
    'selected',
    'src',
    'step',
    'tabIndex',
    'target',
    'title',
    'type',
    'value',
]);
const RESERVED_OPTIONS = new Set([
    'ariaLabel',
    'attributes',
    'attrs',
    'children',
    'className',
    'dataset',
    'events',
    'icon',
    'iconName',
    'properties',
    'text',
]);
function isRecord(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function isNode(value) {
    return value !== null
        && typeof value === 'object'
        && typeof value.nodeType === 'number'
        && typeof value.appendChild === 'function';
}

function entriesOf(value) {
    return isRecord(value) ? Object.entries(value) : [];
}

function attributeName(name) {
    if (name === 'htmlFor') {
        return 'for';
    }
    if (/^aria[A-Z]/.test(name)) {
        return `aria-${name.slice(4).replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`;
    }
    if (/^data[A-Z]/.test(name)) {
        return `data-${name.slice(4).replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`)}`;
    }
    return name;
}

function classText(value) {
    if (Array.isArray(value)) {
        return value.filter((item) => item !== null && item !== undefined && item !== '').join(' ');
    }

    return value === null || value === undefined ? '' : String(value);
}

function setAttributeValue(element, name, value) {
    const normalizedName = attributeName(String(name));
    if (/^on[a-z]/i.test(normalizedName)) {
        return;
    }
    if (value === null || value === undefined) {
        element.removeAttribute(normalizedName);
        return;
    }
    if (value === false && !normalizedName.startsWith('aria-')) {
        element.removeAttribute(normalizedName);
        return;
    }

    element.setAttribute(normalizedName, value === true && !normalizedName.startsWith('aria-')
        ? ''
        : String(value));
}

function setEventHandlers(element, events) {
    entriesOf(events).forEach(([name, handler]) => {
        if (typeof handler === 'function') {
            element.addEventListener(String(name), handler);
        }
    });
}

function setProperties(element, properties) {
    entriesOf(properties).forEach(([name, value]) => {
        if (BLOCKED_PROPERTIES.has(name) || /^on[a-z]/i.test(name)) {
            return;
        }

        element[name] = name === 'textContent' && value === null ? '' : value;
    });
}

function setTopLevelOptions(element, options) {
    if (options.ariaLabel !== null && options.ariaLabel !== undefined) {
        setAttributeValue(element, 'aria-label', options.ariaLabel);
    }

    Object.entries(options).forEach(([name, value]) => {
        if (RESERVED_OPTIONS.has(name) || name === 'ariaLabel') {
            return;
        }
        if (name.startsWith('on') && name.length > 2 && typeof value === 'function') {
            const eventName = name.slice(2);
            element.addEventListener(eventName.charAt(0).toLowerCase() + eventName.slice(1), value);
            return;
        }
        if (DOM_PROPERTIES.has(name)) {
            setProperties(element, { [name]: value });
            return;
        }

        setAttributeValue(element, name, value);
    });
}

function appendChild(element, child) {
    if (Array.isArray(child)) {
        child.forEach((item) => appendChild(element, item));
        return;
    }

    if (isNode(child)) {
        element.appendChild(child);
        return;
    }

    if (child === null || child === undefined || typeof child === 'boolean') {
        return;
    }

    element.appendChild(document.createTextNode(String(child)));
}

export function el(tag, options = {}, children = []) {
    if (typeof document === 'undefined') {
        throw new Error('DOM indisponible.');
    }

    const settings = isRecord(options) ? options : {};
    const element = document.createElement(String(tag));
    const optionChildren = !isRecord(options) && options !== null && options !== undefined
        ? options
        : settings.children;
    const elementChildren = children === undefined
        ? optionChildren
        : Array.isArray(children) && children.length === 0 && optionChildren !== undefined
            ? optionChildren
            : children;

    if (settings.className !== undefined) {
        element.className = classText(settings.className);
    }

    entriesOf(settings.dataset).forEach(([name, value]) => {
        if (value === null || value === undefined) {
            delete element.dataset[name];
        } else {
            element.dataset[name] = String(value);
        }
    });

    entriesOf(settings.attributes).forEach(([name, value]) => {
        setAttributeValue(element, name, value);
    });
    entriesOf(settings.attrs).forEach(([name, value]) => {
        setAttributeValue(element, name, value);
    });

    setEventHandlers(element, settings.events);
    setTopLevelOptions(element, settings);
    setChildren(element, elementChildren);
    setProperties(element, settings.properties);
    if (settings.text !== undefined) {
        element.textContent = settings.text === null ? '' : String(settings.text);
    }
    return element;
}

export function clear(element) {
    if (!isNode(element)) {
        throw new TypeError('Un élément DOM est requis.');
    }

    element.textContent = '';
    return element;
}

export function setChildren(element, children) {
    clear(element);
    appendChild(element, children);
    return element;
}

export function icon(name, accessibleName = '') {
    if (typeof document === 'undefined') {
        throw new Error('DOM indisponible.');
    }

    const iconName = String(name || '').trim().replace(/^#/, '').replace(/^icon-/, '');
    if (!/^[A-Za-z0-9_-]+$/.test(iconName)) {
        throw new TypeError('Nom d’icône invalide.');
    }

    const svg = document.createElementNS(SVG_NAMESPACE, 'svg');
    const iconOptions = isRecord(accessibleName) ? accessibleName : {};
    const rawLabel = isRecord(accessibleName)
        ? accessibleName.accessibleName ?? accessibleName.label
        : accessibleName;
    const label = rawLabel === null || rawLabel === undefined
        ? ''
        : String(rawLabel).trim();

    svg.setAttribute('class', classText(iconOptions.className || 'icon'));
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('focusable', 'false');

    if (label === '') {
        svg.setAttribute('aria-hidden', 'true');
    } else {
        const title = document.createElementNS(SVG_NAMESPACE, 'title');
        title.textContent = label;
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', label);
        svg.appendChild(title);
    }

    const use = document.createElementNS(SVG_NAMESPACE, 'use');
    use.setAttribute('href', `#icon-${iconName}`);
    svg.appendChild(use);
    return svg;
}

export function button(label = '', options = {}) {
    let settings;
    let content = label;

    if (isRecord(label) && !isNode(label)) {
        settings = { ...label };
        content = settings.label === undefined ? '' : settings.label;
        delete settings.label;
    } else {
        settings = typeof options === 'function'
            ? { onClick: options }
            : isRecord(options)
                ? { ...options }
                : {};
    }

    const {
        icon: iconName,
        iconName: alternateIconName,
        accessibleName,
        ariaLabel,
        children: additionalChildren,
        className = 'button',
        type = 'button',
        attrs,
        attributes,
        events,
        handler,
        onClick,
        onclick,
        properties,
        ...elementOptions
    } = settings;
    const selectedIcon = iconName === undefined ? alternateIconName : iconName;
    const buttonChildren = [];

    if (selectedIcon !== undefined && selectedIcon !== null && selectedIcon !== '') {
        buttonChildren.push(icon(selectedIcon));
    }

    if (content !== null && content !== undefined && content !== '') {
        if (isNode(content) || Array.isArray(content)) {
            buttonChildren.push(content);
        } else {
            buttonChildren.push(el('span', { className: 'button-label' }, String(content)));
        }
    }

    if (additionalChildren !== null && additionalChildren !== undefined) {
        buttonChildren.push(additionalChildren);
    }

    const buttonAttributes = {
        ...(isRecord(attrs) ? attrs : {}),
        ...(isRecord(attributes) ? attributes : {}),
    };
    const accessibleValue = accessibleName === undefined ? ariaLabel : accessibleName;
    if (
        accessibleValue !== null
        && accessibleValue !== undefined
        && String(accessibleValue) !== ''
        && buttonAttributes['aria-label'] === undefined
    ) {
        buttonAttributes['aria-label'] = String(accessibleValue);
    }
    if (
        buttonChildren.length === 0
        && buttonAttributes['aria-label'] === undefined
    ) {
        buttonAttributes['aria-label'] = 'Action';
    }

    const buttonEvents = isRecord(events) ? { ...events } : {};
    const clickHandler = handler || onClick || onclick;
    if (typeof clickHandler === 'function') {
        buttonEvents.click = clickHandler;
    }

    return el('button', {
        ...elementOptions,
        className: classText(className),
        attributes: buttonAttributes,
        events: buttonEvents,
        properties: {
            type,
            ...(isRecord(properties) ? properties : {}),
        },
    }, buttonChildren);
}

export function spinnerBlock(message = 'Chargement…') {
    return el('div', {
        className: 'state-block',
        attributes: {
            role: 'status',
            'aria-live': 'polite',
            'aria-busy': 'true',
        },
    }, [
        el('div', { className: 'spinner', attributes: { 'aria-hidden': 'true' } }),
        el('p', { className: 'state-message' }, String(message)),
    ]);
}

function normalizedTone(value) {
    const tone = String(value || 'neutral');
    return /^[A-Za-z0-9_-]+$/.test(tone) ? tone : 'neutral';
}

export function stateBlock(title, message = '', options = {}) {
    const settings = isRecord(title) && !isNode(title)
        ? { ...title }
        : { ...(isRecord(options) ? options : {}), title, message };
    const {
        title: stateTitle,
        message: stateMessage,
        description,
        icon: iconName,
        iconName: alternateIconName,
        action,
        tone = 'neutral',
        headingLevel = 2,
        className = '',
        ...blockOptions
    } = settings;
    const level = Math.min(6, Math.max(1, Number(headingLevel) || 2));
    const selectedIcon = iconName === undefined ? alternateIconName : iconName;
    const children = [];

    if (selectedIcon !== undefined && selectedIcon !== null && selectedIcon !== '') {
        children.push(el('div', { className: 'state-icon', attributes: { 'aria-hidden': 'true' } }, icon(selectedIcon)));
    }

    if (stateTitle !== null && stateTitle !== undefined && stateTitle !== '') {
        children.push(el(`h${level}`, { className: 'state-title' }, String(stateTitle)));
    }

    const text = description === undefined ? stateMessage : description;
    if (text !== null && text !== undefined && text !== '') {
        children.push(isNode(text) || Array.isArray(text)
            ? text
            : el('p', { className: 'state-message' }, String(text)));
    }

    if (action !== null && action !== undefined) {
        children.push(action);
    }

    return el('div', {
        ...blockOptions,
        className: classText(['state-block', `state-block--${normalizedTone(tone)}`, className]),
    }, children);
}

function apiErrorText(error) {
    const status = Number(error.status || error.statusCode || 0);
    const code = String(error.code || '').toUpperCase();

    if (status === 401 || code === 'INVALID_CREDENTIALS' || code === 'AUTHENTICATION_FAILED') {
        return 'Identifiant ou mot de passe incorrect.';
    }
    if (status === 403) {
        return 'Vous n’êtes pas autorisé à effectuer cette action.';
    }
    if (status === 404) {
        return 'La ressource demandée est introuvable.';
    }
    if (status === 409) {
        return 'Cette action est incompatible avec l’état actuel des données.';
    }
    if (status === 413) {
        return 'Le contenu envoyé est trop volumineux.';
    }
    if (status === 415) {
        return 'Ce type de contenu n’est pas pris en charge.';
    }
    if (status === 429 || code === 'TOO_MANY_ATTEMPTS') {
        return 'Trop de tentatives. Réessayez plus tard.';
    }
    if (status === 400 || status === 422) {
        return 'Les informations saisies sont invalides.';
    }
    if (status >= 500) {
        return 'Le serveur rencontre un problème. Réessayez plus tard.';
    }

    return 'Une erreur est survenue. Réessayez plus tard.';
}

export function errorMessage(error) {
    if (error instanceof NetworkError || error?.name === 'NetworkError' || error?.code === 'NETWORK_ERROR') {
        return 'Impossible de joindre le serveur. Vérifiez votre connexion, puis réessayez.';
    }

    if (error?.name === 'AbortError' || error?.code === 20) {
        return 'La demande a été annulée.';
    }

    if (error instanceof ApiError || error?.name === 'ApiError') {
        return apiErrorText(error);
    }

    return 'Une erreur inattendue est survenue. Réessayez plus tard.';
}
