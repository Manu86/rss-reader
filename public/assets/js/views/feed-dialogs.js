import { closeDialog as closeAppDialog, openDialog as openAppDialog } from '../components/dialog.js';
import {
    button,
    clear,
    el,
    errorMessage as domErrorMessage,
    icon,
    setChildren,
    spinnerBlock,
    stateBlock,
} from '../utils/dom.js';

export { button, clear, el, icon, setChildren, spinnerBlock, stateBlock };

let elementSequence = 0;

function isRecord(value) {
    return value !== null
        && typeof value === 'object'
        && !Array.isArray(value)
        && typeof value.nodeType !== 'number';
}

function isNode(value) {
    return value !== null
        && typeof value === 'object'
        && typeof value.nodeType === 'number'
        && typeof value.appendChild === 'function';
}

function documentFor(node) {
    return node && node.ownerDocument ? node.ownerDocument : globalThis.document;
}

export function field(label, control = null, options = {}) {
    const settings = typeof options === 'string'
        ? { hint: options }
        : (isRecord(options) ? options : {});
    const resolvedControl = typeof control === 'string'
        ? el('input', { type: control })
        : isRecord(control)
            ? el('input', control)
            : control;
    if (!isNode(resolvedControl)) {
        throw new TypeError('Un contrôle de formulaire est requis.');
    }

    const controlId = String(
        resolvedControl.getAttribute('id')
        || resolvedControl.id
        || settings.id
        || ''
    ) || nextId('field');
    resolvedControl.setAttribute('id', controlId);
    const wrapper = el('div', { className: settings.className || 'field' });
    wrapper.appendChild(el('label', { for: controlId }, label ?? ''));
    wrapper.appendChild(resolvedControl);
    const describedBy = [];
    const appendDescription = (value, kind) => {
        if (value === null || value === undefined || value === '') {
            return;
        }
        const descriptionId = nextId(kind);
        const node = isNode(value)
            ? value
            : Array.isArray(value)
                ? el('div', {}, value)
                : el('p', {
                    className: kind === 'error' ? 'field-error' : 'field-hint',
                    ...(kind === 'error' ? { attributes: { role: 'alert' } } : {}),
                }, value);
        node.id = descriptionId;
        wrapper.appendChild(node);
        describedBy.push(descriptionId);
    };
    appendDescription(settings.description === undefined ? settings.hint : settings.description, 'hint');
    appendDescription(settings.error, 'error');
    if (describedBy.length > 0) {
        resolvedControl.setAttribute('aria-describedby', describedBy.join(' '));
    }
    if (settings.error !== null && settings.error !== undefined && settings.error !== '') {
        resolvedControl.setAttribute('aria-invalid', 'true');
    }
    return wrapper;
}

export function openDialog(content, options = {}) {
    let dialogContent = content;
    let dialogOptions = isRecord(options) ? options : {};
    if (isRecord(content) && !Array.isArray(content) && !content.tagName) {
        dialogOptions = content;
        dialogContent = content.content ?? content.body ?? null;
    }

    const dialog = openAppDialog({
        title: dialogOptions.title || 'Action',
        description: dialogOptions.description || '',
        content: dialogContent,
        onClose: typeof dialogOptions.onClose === 'function' ? dialogOptions.onClose : null,
    });
    if (dialog && typeof dialog.setAttribute === 'function' && !dialog.id) {
        dialog.setAttribute('id', 'app-dialog');
    }
    return dialog;
}

export function closeDialog() {
    return closeAppDialog();
}

function nextId(prefix) {
    elementSequence += 1;
    return `${prefix}-${elementSequence}`;
}

function textValue(value, fallback = '') {
    return value === null || value === undefined ? fallback : String(value);
}

function dataValue(value) {
    if (value !== null && typeof value === 'object' && value.data !== undefined) {
        return value.data;
    }
    return value;
}

function positiveId(value) {
    const number = typeof value === 'number' ? value : Number(value);
    return Number.isSafeInteger(number) && number > 0 ? number : null;
}

function isHttpUrl(value) {
    try {
        const url = new URL(String(value));
        return (url.protocol === 'http:' || url.protocol === 'https:')
            && url.username === ''
            && url.password === '';
    } catch {
        return false;
    }
}

function booleanValue(value, fallback = true) {
    if (value === true || value === 1 || value === '1' || value === 'true') {
        return true;
    }
    if (value === false || value === 0 || value === '0' || value === 'false') {
        return false;
    }
    return fallback;
}

function categoryOptions(categories, selectedId = null, emptyLabel = 'Sans catégorie') {
    const values = Array.isArray(categories)
        ? categories
        : dataValue(categories);
    const categoryValues = Array.isArray(values) ? values : [];
    const select = el('select', { name: 'category_id' });
    const empty = el('option', { value: '' }, emptyLabel);
    select.appendChild(empty);
    categoryValues.forEach((category) => {
        if (!category || typeof category !== 'object') {
            return;
        }
        const id = positiveId(category.id);
        if (id === null) {
            return;
        }
        const option = el('option', { value: String(id) }, textValue(category.name, 'Sans nom'));
        select.appendChild(option);
    });
    select.value = selectedId === null || selectedId === undefined ? '' : String(selectedId);
    return select;
}

function messageNode(className = 'form-error') {
    return el('p', { className, role: 'alert', hidden: true, 'data-dialog-message': 'true' });
}

function setMessage(node, message, successful = false) {
    if (!node) {
        return;
    }
    clear(node);
    node.hidden = !message;
    node.className = successful ? 'form-success' : 'form-error';
    node.setAttribute('role', successful ? 'status' : 'alert');
    if (message) {
        const ownerDocument = documentFor(node);
        if (ownerDocument && typeof ownerDocument.createTextNode === 'function') {
            node.appendChild(ownerDocument.createTextNode(String(message)));
        }
    }
}

function clearMessage(node) {
    setMessage(node, '', false);
}

function controlsFor(form) {
    if (!form || typeof form.querySelectorAll !== 'function') {
        return [];
    }
    return Array.from(form.querySelectorAll('button, input, select, textarea'));
}

function setFormBusy(form, busy, message = 'Traitement en cours…') {
    if (!form) {
        return;
    }
    form.setAttribute('aria-busy', String(busy));
    const controls = controlsFor(form);
    controls.forEach((control) => {
        if (busy) {
            if (!control.disabled && !control.dataset.dialogWasDisabled) {
                control.dataset.dialogWasDisabled = 'false';
            }
            if (!control.disabled) {
                control.disabled = true;
            }
        } else if (control.dataset.dialogWasDisabled === 'false') {
            control.disabled = false;
            delete control.dataset.dialogWasDisabled;
        }
    });
    const status = form.querySelector('[data-dialog-status]');
    if (status) {
        clear(status);
        status.hidden = !busy;
        if (busy) {
            setChildren(status, spinnerBlock(message));
        }
    }
}

function formError(form) {
    return form && typeof form.querySelector === 'function'
        ? form.querySelector('[data-dialog-message]')
        : null;
}

function formStatus(form) {
    return form && typeof form.querySelector === 'function'
        ? form.querySelector('[data-dialog-status]')
        : null;
}

function actionRow(children) {
    return el('div', { className: 'dialog-actions' }, children);
}

function dialogShell(title, description = '') {
    const root = el('div', { className: 'dialog-view' });
    return {
        root,
        title: String(title),
        description: String(description),
    };
}

function formFor(root) {
    const form = el('form', { className: 'dialog-form', novalidate: true });
    root.appendChild(form);
    return form;
}

function normalizeDiscovery(result) {
    const payload = dataValue(result);
    if (Array.isArray(payload)) {
        return { siteUrl: '', candidates: payload };
    }
    if (payload === null || typeof payload !== 'object') {
        return { siteUrl: '', candidates: [] };
    }
    const candidates = Array.isArray(payload.feeds) ? payload.feeds : [];
    return {
        siteUrl: textValue(payload.site_url, ''),
        candidates: candidates.filter((candidate) => (
            candidate !== null
            && typeof candidate === 'object'
            && textValue(candidate.url, '') !== ''
        )),
    };
}

function normalizeCreateResult(result) {
    return dataValue(result);
}

async function submitDialogForm(form, task, successMessage = '') {
    const error = formError(form);
    const status = formStatus(form);
    const submit = form.querySelector('button[type="submit"]');
    if (!submit || submit.disabled) {
        return false;
    }

    clearMessage(error);
    if (status) {
        clear(status);
        status.hidden = true;
    }
    setFormBusy(form, true);
    try {
        const result = await task();
        if (result === false) {
            setMessage(error, successMessage || 'L’opération n’a pas pu être terminée.');
            return false;
        }
        closeDialog();
        return result;
    } catch (failure) {
        setMessage(error, domErrorMessage(failure));
        return false;
    } finally {
        setFormBusy(form, false);
    }
}

function addUrlStep({ discover, onDiscovered }) {
    const shell = dialogShell(
        'Ajouter un flux',
        'Saisissez l’adresse d’un site web ou d’un flux RSS/Atom.',
    );
    const form = formFor(shell.root);
    const inputId = nextId('feed-url');
    const input = el('input', {
        id: inputId,
        name: 'url',
        type: 'url',
        required: true,
        autocomplete: 'url',
        placeholder: 'https://example.org/feed.xml',
        autofocus: true,
    });
    form.appendChild(field('Adresse du site ou du flux', input, {
        hint: 'La découverte peut proposer plusieurs flux.',
    }));
    form.appendChild(el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-dialog-status': 'true',
        hidden: true,
    }));
    form.appendChild(messageNode());
    form.appendChild(actionRow([
        button('Annuler', { onClick: () => closeDialog() }),
        button('Rechercher les flux', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'search',
        }),
    ]));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const url = input.value.trim();
        input.value = url;
        const error = formError(form);
        clearMessage(error);
        if (
            url === ''
            || !isHttpUrl(url)
            || (typeof input.checkValidity === 'function' && !input.checkValidity())
        ) {
            setMessage(error, 'Saisissez une adresse valide.');
            input.focus();
            return;
        }
        if (typeof discover !== 'function') {
            setMessage(error, 'La découverte des flux est indisponible.');
            return;
        }

        setFormBusy(form, true, 'Recherche des flux…');
        try {
            const result = await discover(url);
            const normalized = normalizeDiscovery(result);
            if (normalized.candidates.length === 0) {
                setFormBusy(form, false);
                setMessage(error, 'Aucun flux RSS ou Atom n’a été trouvé.');
                return;
            }
            setFormBusy(form, false);
            if (typeof onDiscovered === 'function') {
                await onDiscovered(normalized);
            }
        } catch (failure) {
            setFormBusy(form, false);
            setMessage(error, domErrorMessage(failure));
        }
    });

    openDialog(shell.root, { title: shell.title, description: shell.description });
    return shell.root;
}

function addCandidateStep({ candidates, siteUrl, createFeed, categories, onCreated, onBack }) {
    const shell = dialogShell(
        'Choisir un flux',
        siteUrl
            ? `Flux détecté sur ${siteUrl}.`
            : 'Sélectionnez explicitement le flux auquel vous souhaitez vous abonner.',
    );
    const form = formFor(shell.root);
    const fieldset = el('fieldset', { className: 'candidate-list' });
    fieldset.appendChild(el('legend', {}, 'Flux disponibles'));
    const groupName = nextId('candidate');

    candidates.forEach((candidate) => {
        const candidateId = nextId('candidate-option');
        const radio = el('input', {
            id: candidateId,
            name: groupName,
            type: 'radio',
            value: textValue(candidate.url),
            checked: candidates.length === 1,
        });
        const label = el('label', { className: 'candidate-option', for: candidateId }, [
            radio,
            el('span', { className: 'candidate-copy' }, [
                el('strong', {}, textValue(candidate.title, 'Flux sans titre')),
                el('span', { className: 'candidate-url' }, textValue(candidate.url)),
                candidate.type ? el('small', {}, String(candidate.type)) : null,
            ]),
        ]);
        fieldset.appendChild(label);
    });
    form.appendChild(fieldset);

    const nameId = nextId('feed-name');
    const name = el('input', {
        id: nameId,
        name: 'name',
        type: 'text',
        maxlength: 200,
        autocomplete: 'off',
    });
    form.appendChild(field('Nom personnalisé (facultatif)', name));
    const category = categoryOptions(categories);
    form.appendChild(field('Catégorie', category));
    form.appendChild(el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-dialog-status': 'true',
        hidden: true,
    }));
    form.appendChild(messageNode());
    form.appendChild(actionRow([
        button('Retour', { onClick: onBack, icon: 'left' }),
        button('S’abonner', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'check',
        }),
    ]));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const selected = form.querySelector(`input[name="${groupName}"]:checked`);
        const error = formError(form);
        clearMessage(error);
        if (!selected || selected.value === '') {
            setMessage(error, 'Sélectionnez un flux pour continuer.');
            return;
        }
        if (typeof createFeed !== 'function') {
            setMessage(error, 'La création du flux est indisponible.');
            return;
        }

        const payload = {
            feed_url: selected.value,
            category_id: category.value === '' ? null : positiveId(category.value),
        };
        const customName = name.value.trim();
        if (customName !== '') {
            payload.name = customName;
        }

        await submitDialogForm(form, async () => {
            const result = await createFeed(payload);
            if (result === false) {
                return result;
            }
            if (typeof onCreated === 'function') {
                await onCreated(normalizeCreateResult(result));
            }
            return result;
        }, 'Le flux n’a pas pu être créé.');
    });

    openDialog(shell.root, { title: shell.title, description: shell.description });
    return shell.root;
}

export function openAddFeedDialog({ discover, createFeed, categories = [], onCreated } = {}) {
    let currentRoot = null;
    let discovered = { siteUrl: '', candidates: [] };

    const showCandidateStep = () => {
        currentRoot = addCandidateStep({
            candidates: discovered.candidates,
            siteUrl: discovered.siteUrl,
            createFeed,
            categories,
            onCreated,
            onBack: showUrlStep,
        });
    };

    const showUrlStep = () => {
        currentRoot = addUrlStep({
            discover,
            onDiscovered: (result) => {
                discovered = result;
                showCandidateStep();
            },
        });
    };

    showUrlStep();
}

export function openFeedEditorDialog({ feed, categories = [], onSave } = {}) {
    const feedValue = dataValue(feed);
    const value = feedValue !== null
        && typeof feedValue === 'object'
        && !Array.isArray(feedValue)
        ? feedValue
        : {};
    const shell = dialogShell(
        'Modifier le flux',
        textValue(value.name, 'Flux'),
    );
    const form = formFor(shell.root);
    const nameId = nextId('edit-feed-name');
    const name = el('input', {
        id: nameId,
        name: 'name',
        type: 'text',
        required: true,
        maxlength: 200,
        autocomplete: 'off',
        autofocus: true,
    });
    name.value = textValue(value.name, '');
    form.appendChild(field('Nom du flux', name));

    const category = categoryOptions(categories, value.category_id);
    form.appendChild(field('Catégorie', category));

    const activeId = nextId('edit-feed-active');
    const active = el('input', {
        id: activeId,
        name: 'is_active',
        type: 'checkbox',
    });
    active.checked = booleanValue(value.is_active, booleanValue(value.active, true));
    form.appendChild(el('div', { className: 'field field-checkbox' }, [
        active,
        el('label', { for: activeId }, 'Flux actif'),
    ]));

    const feedUrl = textValue(value.feed_url, '');
    const siteUrl = textValue(value.site_url, '');
    if (feedUrl !== '' || siteUrl !== '') {
        const details = el('dl', { className: 'dialog-details' });
        const addDetail = (label, content) => {
            details.appendChild(el('dt', {}, label));
            if (isHttpUrl(content)) {
                const link = el('a', {
                    href: content,
                    target: '_blank',
                    rel: 'noopener noreferrer',
                }, content);
                details.appendChild(el('dd', {}, [link]));
            } else {
                details.appendChild(el('dd', {}, content || '- '));
            }
        };
        addDetail('URL du flux', feedUrl);
        if (siteUrl !== '' && siteUrl !== feedUrl) {
            addDetail('Site source', siteUrl);
        }
        form.appendChild(details);
    }

    form.appendChild(el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-dialog-status': 'true',
        hidden: true,
    }));
    form.appendChild(messageNode());
    form.appendChild(actionRow([
        button('Annuler', { onClick: () => closeDialog() }),
        button('Enregistrer', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'check',
        }),
    ]));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = formError(form);
        clearMessage(error);
        const newName = name.value.trim();
        if (newName === '') {
            setMessage(error, 'Le nom du flux est requis.');
            name.focus();
            return;
        }
        if (typeof onSave !== 'function') {
            setMessage(error, 'La modification du flux est indisponible.');
            return;
        }

        await submitDialogForm(form, () => onSave({
            name: newName,
            category_id: category.value === '' ? null : positiveId(category.value),
            is_active: active.checked,
        }), 'Le flux n’a pas pu être modifié.');
    });

    openDialog(shell.root, { title: shell.title, description: shell.description });
}

export function openCategoryDialog({ category, onSave } = {}) {
    const categoryValue = dataValue(category);
    const value = categoryValue !== null
        && typeof categoryValue === 'object'
        && !Array.isArray(categoryValue)
        ? categoryValue
        : null;
    const shell = dialogShell(
        value ? 'Modifier la catégorie' : 'Ajouter une catégorie',
        value ? textValue(value.name, '') : 'Classez vos flux dans une catégorie personnalisée.',
    );
    const form = formFor(shell.root);
    const nameId = nextId('category-name');
    const name = el('input', {
        id: nameId,
        name: 'name',
        type: 'text',
        required: true,
        maxlength: 200,
        autocomplete: 'off',
        autofocus: true,
    });
    name.value = value ? textValue(value.name, '') : '';
    form.appendChild(field('Nom de la catégorie', name));
    form.appendChild(el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-dialog-status': 'true',
        hidden: true,
    }));
    form.appendChild(messageNode());
    form.appendChild(actionRow([
        button('Annuler', { onClick: () => closeDialog() }),
        button(value ? 'Enregistrer' : 'Créer la catégorie', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'check',
        }),
    ]));

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const error = formError(form);
        clearMessage(error);
        const newName = name.value.trim();
        if (newName === '') {
            setMessage(error, 'Le nom de la catégorie est requis.');
            name.focus();
            return;
        }
        if (typeof onSave !== 'function') {
            setMessage(error, 'La modification de la catégorie est indisponible.');
            return;
        }

        await submitDialogForm(form, () => onSave({ name: newName }), 'La catégorie n’a pas pu être enregistrée.');
    });

    openDialog(shell.root, { title: shell.title, description: shell.description });
}

export function openConfirmDialog({
    title = 'Confirmer l’action',
    message = 'Êtes-vous sûr ?',
    confirmLabel = 'Confirmer',
    tone = 'default',
    onConfirm,
} = {}) {
    const safeTone = ['default', 'danger', 'warning'].includes(String(tone)) ? String(tone) : 'default';
    const shell = dialogShell(String(title), '');
    shell.root.classList.add(`dialog-confirm-${safeTone}`);
    shell.root.appendChild(el('p', { className: 'dialog-confirm-message' }, String(message)));
    const status = el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-dialog-status': 'true',
        hidden: true,
    });
    const error = messageNode();
    shell.root.appendChild(status);
    shell.root.appendChild(error);
    shell.root.appendChild(actionRow([
        button('Annuler', { onClick: () => closeDialog() }),
        button(String(confirmLabel), {
            className: safeTone === 'danger' ? 'button button-danger' : 'button button-primary',
            icon: safeTone === 'danger' ? 'trash' : 'check',
            onClick: async () => {
                if (typeof onConfirm !== 'function') {
                    setMessage(error, 'Cette action est indisponible.');
                    return;
                }
                const controls = Array.from(shell.root.querySelectorAll('button'));
                if (controls.some((control) => control.disabled)) {
                    return;
                }
                setFormBusy(shell.root, true, 'Action en cours…');
                clearMessage(error);
                try {
                    const result = await onConfirm();
                    if (result === false) {
                        setMessage(error, 'L’action n’a pas pu être terminée.');
                        return;
                    }
                    closeDialog();
                } catch (failure) {
                    setMessage(error, domErrorMessage(failure));
                } finally {
                    setFormBusy(shell.root, false);
                }
            },
        }),
    ]));

    openDialog(shell.root, { title: shell.title, description: shell.description });
}
