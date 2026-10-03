function ahxRecipeDraftDatabase() {
    return new Promise(function (resolve, reject) {
        const request = indexedDB.open('ahx-recipe-submissions', 1);
        request.onupgradeneeded = function () { request.result.createObjectStore('drafts'); };
        request.onsuccess = function () { resolve(request.result); };
        request.onerror = function () { reject(request.error); };
        request.onblocked = function () { reject(new Error('Lokaler Entwurfsspeicher ist blockiert.')); };
    });
}

function ahxRecipeDraftTransaction(database, key, value, mode) {
    return new Promise(function (resolve, reject) {
        const transaction = database.transaction('drafts', mode === 'get' ? 'readonly' : 'readwrite');
        const store = transaction.objectStore('drafts');
        const request = mode === 'get' ? store.get(key) : mode === 'delete' ? store.delete(key) : store.put(value, key);
        transaction.oncomplete = function () { resolve(request.result); };
        transaction.onerror = function () { reject(transaction.error); };
        transaction.onabort = function () { reject(transaction.error); };
    });
}

function ahxRecipeSubmissionToken() {
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes).map(function (value) { return value.toString(16).padStart(2, '0'); }).join('');
    return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
}

document.querySelectorAll('[data-ahx-submission]').forEach(async function (form) {
    const kind = form.elements.kind.value;
    const key = form.dataset.draftKey;
    const maxImageBytes = Number(form.dataset.maxImageBytes ?? 5 * 1024 * 1024);
    const status = form.querySelector('[data-ahx-submission-status]');
    const rows = form.querySelector('[data-ahx-submission-ingredients]');
    const template = form.querySelector('[data-ahx-submission-row]');
    let database;
    let state;
    let previewUrl = '';
    let queue = Promise.resolve();
    let busy = false;
    function fresh() {
        return {kind: kind, token: ahxRecipeSubmissionToken(), attempted: false, title: '', description: '', servings: 2, instructions: '', ingredients: [{quantity: '', unit: '', label: '', addition: ''}], types: [], url: '', image: null};
    }
    function report(message, error) {
        status.textContent = message;
        status.classList.toggle('has-error', Boolean(error));
    }
    function addRow(ingredient) {
        if (rows.children.length >= 100) {
            return;
        }
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-ingredient]').forEach(function (input) { input.value = ingredient[input.dataset.ingredient] || ''; });
        rows.append(row);
        form.querySelector('[data-ahx-submission-add]').disabled = rows.children.length >= 100;
    }
    function renderImage() {
        if (kind !== 'recipe') {
            return;
        }
        const container = form.querySelector('[data-ahx-submission-image]');
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = '';
        }
        container.hidden = !state.image;
        if (state.image) {
            previewUrl = URL.createObjectURL(state.image);
            container.querySelector('img').src = previewUrl;
            container.querySelector('[data-ahx-submission-filename]').textContent = state.image.name || 'Rezeptbild';
            if (typeof DataTransfer === 'function' && state.image instanceof File) {
                const transfer = new DataTransfer();
                transfer.items.add(state.image);
                form.elements.image.files = transfer.files;
            }
        } else {
            container.querySelector('img').removeAttribute('src');
        }
    }
    function restore() {
        form.elements.title.value = state.title;
        if (kind === 'recipe') {
            ['description', 'servings', 'instructions'].forEach(function (name) { form.elements[name].value = state[name]; });
            rows.replaceChildren();
            state.ingredients.forEach(addRow);
            form.querySelectorAll('[name="types"]').forEach(function (input) { input.checked = state.types.includes(Number(input.value)); });
            form.elements.image.value = '';
            renderImage();
        } else {
            form.elements.url.value = state.url;
        }
    }
    function markEdited() {
        if (state.attempted) {
            state.token = ahxRecipeSubmissionToken();
            state.attempted = false;
        }
    }
    function capture() {
        const previous = JSON.stringify(state);
        state.title = form.elements.title.value;
        if (kind === 'recipe') {
            ['description', 'servings', 'instructions'].forEach(function (name) { state[name] = form.elements[name].value; });
            state.ingredients = Array.from(rows.children).map(function (row) {
                const ingredient = {};
                row.querySelectorAll('[data-ingredient]').forEach(function (input) { ingredient[input.dataset.ingredient] = input.value; });
                return ingredient;
            });
            state.types = Array.from(form.querySelectorAll('[name="types"]:checked')).map(function (input) { return Number(input.value); });
        } else {
            state.url = form.elements.url.value;
        }
        if (JSON.stringify(state) !== previous) {
            markEdited();
        }
    }
    function persist() {
        const snapshot = structuredClone(state);
        queue = queue.catch(function () {}).then(async function () {
            if (!database) {
                throw new Error('Entwurfsspeicher nicht verfügbar.');
            }
            await ahxRecipeDraftTransaction(database, key, snapshot, 'put');
            report('Entwurf lokal gespeichert.', false);
        }).catch(function () { report('Entwurf konnte nicht lokal gespeichert werden. Bitte diese Seite nicht schließen.', true); });
        return queue;
    }
    function setBusy(value) {
        busy = value;
        form.querySelectorAll('input, textarea, select, button').forEach(function (element) { element.disabled = value; });
        if (!value && rows) {
            form.querySelector('[data-ahx-submission-add]').disabled = rows.children.length >= 100;
        }
    }
    try {
        database = await ahxRecipeDraftDatabase();
        state = await ahxRecipeDraftTransaction(database, key, null, 'get');
    } catch (error) {
        report('Lokaler Entwurfsspeicher nicht verfügbar. Bitte diese Seite bis zum Speichern nicht schließen.', true);
    }
    const recovered = Boolean(state && state.kind === kind && state.token);
    if (!recovered) {
        state = fresh();
    }
    restore();
    if (recovered) {
        report('Lokaler Entwurf wiederhergestellt.', false);
    }
    form.hidden = false;
    form.addEventListener('input', function (event) {
        if (!busy && event.target.type !== 'file') {
            capture();
            persist();
        }
    });
    form.addEventListener('change', function (event) {
        if (busy) {
            return;
        }
        if (event.target.name === 'image') {
            const image = event.target.files[0];
            if (image && (!['image/jpeg', 'image/png', 'image/webp'].includes(image.type) || image.size > maxImageBytes)) {
                event.target.value = '';
                report('Bitte ein JPG-, PNG- oder WebP-Bild mit maximal ' + (maxImageBytes / 1024 / 1024).toLocaleString('de-DE') + ' MB auswählen.', true);
                return;
            }
            if (image && image !== state.image) {
                markEdited();
                state.image = image;
            }
            renderImage();
        }
        capture();
        persist();
    });
    form.addEventListener('click', function (event) {
        if (busy) {
            return;
        }
        if (event.target.closest('[data-ahx-submission-add]')) {
            addRow({});
            rows.lastElementChild.querySelector('[data-ingredient="label"]').focus();
            capture();
            persist();
        }
        const remove = event.target.closest('[data-ahx-submission-remove]');
        if (remove) {
            remove.closest('.ahx-recipe-submission__ingredient').remove();
            if (!rows.children.length) {
                addRow({});
            }
            form.querySelector('[data-ahx-submission-add]').disabled = false;
            rows.lastElementChild.querySelector('[data-ingredient="label"]').focus();
            capture();
            persist();
        }
        if (event.target.closest('[data-ahx-submission-remove-image]')) {
            markEdited();
            state.image = null;
            form.elements.image.value = '';
            renderImage();
            capture();
            persist();
        }
    });
    form.addEventListener('reset', async function (event) {
        event.preventDefault();
        if (busy || !confirm('Diesen lokalen Entwurf wirklich verwerfen?')) {
            return;
        }
        await queue;
        state = fresh();
        restore();
        persist();
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !form.reportValidity()) {
            return;
        }
        capture();
        state.attempted = true;
        setBusy(true);
        await persist();
        report('Einreichung wird gespeichert …', false);
        try {
            const payload = Object.assign({}, state, {website: form.elements.website.value});
            delete payload.image;
            const body = new FormData();
            body.append('action', 'ahx_wp_recipe_submit');
            body.append('nonce', form.dataset.nonce);
            body.append('payload', JSON.stringify(payload));
            if (state.image && kind === 'recipe') {
                body.append('image', state.image, state.image.name || 'rezeptbild.png');
            }
            const response = await fetch(form.dataset.endpoint, {method: 'POST', body: body, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.data && result.data.message || 'Die Einreichung konnte nicht gespeichert werden.');
            }
            let cleared = true;
            if (database) {
                try { await ahxRecipeDraftTransaction(database, key, null, 'delete'); } catch (error) { cleared = false; }
            }
            state = fresh();
            restore();
            report(result.data.message + (cleared ? '' : ' Der alte lokale Entwurf konnte nicht entfernt werden.'), !cleared);
        } catch (error) {
            report(error.message + (/Entwurf bleibt erhalten/i.test(error.message) ? '' : ' Der lokale Entwurf bleibt erhalten.'), true);
        } finally {
            setBusy(false);
        }
    });
});