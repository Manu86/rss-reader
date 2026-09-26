import { errorMessage as domErrorMessage } from '../utils/dom.js';
import { formatNumber } from '../utils/format.js';
import {
    button,
    clear,
    el,
    field,
    icon,
    setChildren,
    spinnerBlock,
} from './feed-dialogs.js';

const MAX_OPML_BYTES = 1048576;
let settingsSequence = 0;

function isElement(value) {
    return value !== null
        && typeof value === 'object'
        && (typeof value.nodeType === 'number' || typeof value.querySelector === 'function');
}

function resolveRoot(root) {
    if (typeof root === 'string') {
        return globalThis.document ? globalThis.document.querySelector(root) : null;
    }
    if (isElement(root)) {
        if (root.id === 'utility-content') {
            return root;
        }
        if (typeof root.querySelector === 'function') {
            return root.querySelector('#utility-content') || root;
        }
    }
    return globalThis.document ? globalThis.document.querySelector('#utility-content') : null;
}

function constructorValues(root, callbacks) {
    if (
        root !== null
        && typeof root === 'object'
        && !isElement(root)
        && !Array.isArray(root)
    ) {
        return {
            root: root.root || '#utility-content',
            callbacks: root,
        };
    }
    return { root, callbacks: callbacks || {} };
}

function dataValue(value) {
    if (value !== null && typeof value === 'object' && value.data !== undefined) {
        return value.data;
    }
    return value;
}

function textValue(value, fallback = '') {
    return value === null || value === undefined ? fallback : String(value);
}

function userName(user) {
    const value = dataValue(user);
    if (typeof value === 'string') {
        return value;
    }
    if (value !== null && typeof value === 'object') {
        return textValue(value.username ?? value.name, '');
    }
    return '';
}

function importValue(result) {
    const value = dataValue(result);
    if (value === null || typeof value !== 'object') {
        return {};
    }
    return value;
}

function countValue(value) {
    const number = Number(value);
    return Number.isFinite(number) && number >= 0 ? number : 0;
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
        node.textContent = String(message);
    }
}

function clearMessage(node) {
    setMessage(node, '', false);
}

function formError(form) {
    return form && typeof form.querySelector === 'function'
        ? form.querySelector('[data-settings-message]')
        : null;
}

function formStatus(form) {
    return form && typeof form.querySelector === 'function'
        ? form.querySelector('[data-settings-status]')
        : null;
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
    controlsFor(form).forEach((control) => {
        if (busy) {
            if (!control.disabled && !control.dataset.settingsWasDisabled) {
                control.dataset.settingsWasDisabled = 'false';
            }
            if (!control.disabled) {
                control.disabled = true;
            }
        } else if (control.dataset.settingsWasDisabled === 'false') {
            control.disabled = false;
            delete control.dataset.settingsWasDisabled;
        }
    });
    const status = formStatus(form);
    if (status) {
        clear(status);
        status.hidden = !busy;
        if (busy) {
            status.appendChild(spinnerBlock(message));
        }
    }
}

function messageNode() {
    return el('p', {
        className: 'form-error',
        role: 'alert',
        hidden: true,
        'data-settings-message': 'true',
    });
}

function statusNode() {
    return el('div', {
        className: 'inline-status',
        role: 'status',
        'aria-live': 'polite',
        'data-settings-status': 'true',
        hidden: true,
    });
}

function sectionTitle(id, title, description = '') {
    const header = el('div', { className: 'settings-section-header' }, [
        el('h2', { id }, title),
    ]);
    if (description) {
        header.appendChild(el('p', { className: 'settings-section-description' }, description));
    }
    return header;
}

function createSettingsSection(instance, title, description) {
    const id = `settings-${instance.instanceId}-${++settingsSequence}`;
    const section = el('section', {
        className: 'settings-section',
        'aria-labelledby': id,
    });
    section.appendChild(sectionTitle(id, title, description));
    return section;
}

function passwordSettingsForm(instance) {
    const form = el('form', { className: 'settings-form', novalidate: true });
    const currentId = `current-password-${instance.instanceId}`;
    const newId = `new-password-${instance.instanceId}`;
    const confirmId = `confirm-password-${instance.instanceId}`;
    const current = el('input', {
        id: currentId,
        name: 'current_password',
        type: 'password',
        required: true,
        autocomplete: 'current-password',
    });
    const next = el('input', {
        id: newId,
        name: 'new_password',
        type: 'password',
        required: true,
        minlength: 12,
        autocomplete: 'new-password',
    });
    const confirmation = el('input', {
        id: confirmId,
        name: 'new_password_confirmation',
        type: 'password',
        required: true,
        minlength: 12,
        autocomplete: 'new-password',
    });
    form.appendChild(field('Mot de passe actuel', current));
    form.appendChild(field('Nouveau mot de passe', next, {
        hint: 'Utilisez au moins 12 caractères.',
    }));
    form.appendChild(field('Confirmation du nouveau mot de passe', confirmation));
    form.appendChild(statusNode());
    form.appendChild(messageNode());
    form.appendChild(el('div', { className: 'form-actions' }, [
        button('Changer le mot de passe', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'lock',
        }),
    ]));
    return { form, current, next, confirmation };
}

function appearanceSettingsForm(instance) {
    const form = el('form', { className: 'settings-form', novalidate: true });
    const themeId = `theme-${instance.instanceId}`;
    const theme = el('select', {
        id: themeId,
        name: 'theme',
        required: true,
    }, [
        el('option', { value: 'light', text: 'Thème clair' }),
        el('option', { value: 'dark', text: 'Thème sombre' }),
    ]);
    form.appendChild(field('Thème de l’interface', theme, {
        hint: 'Le thème sombre s’applique instantanément à toute l’interface.',
    }));
    form.appendChild(statusNode());
    form.appendChild(messageNode());
    form.appendChild(el('div', { className: 'form-actions' }, [
        button('Appliquer le thème', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'check',
        }),
    ]));
    return { form, theme };
}

function opmlSettingsForm() {
    const form = el('form', { className: 'settings-form', novalidate: true });
    const file = el('input', {
        name: 'file',
        type: 'file',
        required: true,
        accept: '.opml,application/xml,text/xml',
    });
    form.appendChild(field('Fichier OPML', file, {
        hint: 'La taille maximale est de 1 MiB.',
    }));
    form.appendChild(statusNode());
    form.appendChild(messageNode());
    form.appendChild(el('div', { className: 'form-actions' }, [
        button('Importer OPML', {
            type: 'submit',
            className: 'button button-primary',
            icon: 'upload',
        }),
    ]));
    return { form, file };
}

async function runSection(instance, name, form, task, onSuccess, successMessage) {
    if (instance.pending.has(name)) {
        return false;
    }
    const error = formError(form);
    clearMessage(error);
    instance.pending.add(name);
    setFormBusy(form, true, name === 'opml' ? 'Import en cours…' : 'Enregistrement…');
    try {
        const result = await task();
        if (result === false) {
            setMessage(error, 'L’opération n’a pas pu être effectuée.');
            return false;
        }
        let successText = successMessage;
        if (typeof onSuccess === 'function') {
            const returnedMessage = await onSuccess(result);
            if (returnedMessage !== null && returnedMessage !== undefined && returnedMessage !== '') {
                successText = String(returnedMessage);
            }
        }
        setMessage(error, successText, true);
        return true;
    } catch (failure) {
        setMessage(error, domErrorMessage(failure));
        return false;
    } finally {
        instance.pending.delete(name);
        setFormBusy(form, false);
    }
}

export class SettingsView {
    constructor(root = '#utility-content', callbacks = {}) {
        const values = constructorValues(root, callbacks);
        this.root = resolveRoot(values.root);
        this.callbacks = { ...(values.callbacks || {}) };
        this.instanceId = `settings-${++settingsSequence}`;
        this.user = null;
        this.busySection = null;
        this.pending = new Set();
        this.appliedTheme = typeof document !== 'undefined'
            && document.documentElement.dataset.theme === 'dark'
            ? 'dark'
            : 'light';
    }

    render(data = {}) {
        if (data !== null && typeof data === 'object') {
            const responseData = data.data !== undefined ? data.data : data;
            const sources = [data, responseData];
            sources.forEach((source) => {
                if (source === null || typeof source !== 'object') {
                    return;
                }
                if (Object.prototype.hasOwnProperty.call(source, 'user')) {
                    this.user = source.user;
                }
                if (Object.prototype.hasOwnProperty.call(source, 'busySection')) {
                    this.busySection = source.busySection ?? null;
                }
            });
        }
        this.renderRoot();
        return this;
    }

    renderRoot() {
        if (!this.root) {
            return;
        }
        const view = el('section', {
            className: 'settings-view',
            'aria-labelledby': `${this.instanceId}-title`,
        });
        const header = el('header', { className: 'settings-header' }, [
            el('p', { className: 'eyebrow' }, 'Compte'),
            el('h1', { id: `${this.instanceId}-title` }, 'Paramètres'),
        ]);
        view.appendChild(header);

        const name = userName(this.user);
        if (name !== '') {
            view.appendChild(el('p', { className: 'settings-user' }, [
                el('span', { className: 'settings-user-label' }, 'Connecté comme '),
                el('strong', {}, name),
            ]));
        }

        view.appendChild(this.renderAppearanceSection());
        view.appendChild(this.renderPasswordSection());
        view.appendChild(this.renderOpmlSection());
        setChildren(this.root, view);
    }

    renderAppearanceSection() {
        const section = createSettingsSection(this, 'Apparence', 'Choisissez le thème de l’interface. Le choix est mémorisé pour votre compte.');
        const controls = appearanceSettingsForm(this);
        const callback = this.callbacks.onApplyTheme;
        const submit = controls.form.querySelector('button[type="submit"]');
        if (typeof callback !== 'function') {
            submit.disabled = true;
        }
        if (this.isBusy('appearance')) {
            setFormBusy(controls.form, true, 'Application…');
        }
        const currentTheme = this.appliedTheme === 'dark' ? 'dark' : 'light';
        if (Array.from(controls.theme.options).some((option) => option.value === currentTheme)) {
            controls.theme.value = currentTheme;
        }
        controls.form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const error = formError(controls.form);
            clearMessage(error);
            const theme = controls.theme.value;
            await runSection(
                this,
                'appearance',
                controls.form,
                () => callback(theme),
                () => {
                    this.appliedTheme = theme;
                    return theme === 'dark' ? 'Thème sombre appliqué.' : 'Thème clair appliqué.';
                },
                'Thème appliqué.',
            );
        });
        section.appendChild(controls.form);
        return section;
    }

    renderPasswordSection() {
        const section = createSettingsSection(this, 'Mot de passe', 'Choisissez un mot de passe d’au moins 12 caractères.');
        const controls = passwordSettingsForm(this);
        const callback = this.callbacks.onChangePassword;
        const submit = controls.form.querySelector('button[type="submit"]');
        if (typeof callback !== 'function') {
            submit.disabled = true;
        } else if (this.isBusy('password')) {
            setFormBusy(controls.form, true, 'Modification…');
        }
        controls.form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const error = formError(controls.form);
            clearMessage(error);
            const currentPassword = controls.current.value;
            const newPassword = controls.next.value;
            const confirmation = controls.confirmation.value;
            if (currentPassword === '') {
                setMessage(error, 'Saisissez votre mot de passe actuel.');
                controls.current.focus();
                return;
            }
            if (Array.from(newPassword).length < 12) {
                setMessage(error, 'Le nouveau mot de passe doit contenir au moins 12 caractères.');
                controls.next.focus();
                return;
            }
            if (newPassword !== confirmation) {
                setMessage(error, 'La confirmation ne correspond pas au nouveau mot de passe.');
                controls.confirmation.focus();
                return;
            }
            await runSection(
                this,
                'password',
                controls.form,
                () => callback({
                    current_password: currentPassword,
                    new_password: newPassword,
                }),
                () => {
                    controls.current.value = '';
                    controls.next.value = '';
                    controls.confirmation.value = '';
                },
                'Mot de passe modifié.',
            );
        });
        section.appendChild(controls.form);
        return section;
    }

    renderOpmlSection() {
        const section = createSettingsSection(this, 'OPML', 'Importez ou exportez les abonnements de votre compte.');
        const controls = opmlSettingsForm();
        const callback = this.callbacks.onImportOpml;
        const submit = controls.form.querySelector('button[type="submit"]');
        if (typeof callback !== 'function') {
            submit.disabled = true;
        } else if (this.isBusy('opml')) {
            setFormBusy(controls.form, true, 'Import…');
        }
        controls.form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const error = formError(controls.form);
            clearMessage(error);
            const file = controls.file.files && controls.file.files.length > 0
                ? controls.file.files[0]
                : null;
            if (file === null) {
                setMessage(error, 'Sélectionnez un fichier OPML.');
                controls.file.focus();
                return;
            }
            if (Number(file.size) > MAX_OPML_BYTES) {
                setMessage(error, 'Le fichier OPML dépasse la limite de 1 MiB.');
                return;
            }
            await runSection(
                this,
                'opml',
                controls.form,
                () => callback(file),
                (result) => {
                    const values = importValue(result);
                    controls.file.value = '';
                    const imported = countValue(values.imported);
                    const duplicates = countValue(values.duplicates);
                    const failed = countValue(values.failed);
                    const categories = countValue(values.categories_created);
                    return `Import terminé : ${formatNumber(imported)} importé${imported > 1 ? 's' : ''}, ${formatNumber(duplicates)} doublon${duplicates > 1 ? 's' : ''}, ${formatNumber(failed)} échec${failed > 1 ? 's' : ''}, ${formatNumber(categories)} catégorie${categories > 1 ? 's' : ''} créée${categories > 1 ? 's' : ''}.`;
                },
                'Import OPML terminé.',
            );
        });
        section.appendChild(controls.form);
        section.appendChild(el('div', { className: 'settings-export' }, [
            el('p', {}, 'Exportez la liste complète de vos abonnements.'),
            el('a', {
                href: '/api/opml/export',
                download: 'rss-reader.opml',
                className: 'button',
            }, [icon('download'), el('span', {}, 'Exporter OPML')]),
        ]));
        return section;
    }

    isBusy(section) {
        return this.busySection === section || this.pending.has(section);
    }
}

export default SettingsView;
