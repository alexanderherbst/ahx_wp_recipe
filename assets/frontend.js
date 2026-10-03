function ahxRecipeIngredientName(value) {
    return String(value).toLocaleLowerCase('de-DE')
        .replace(/\((n|en|e)\)/g, '$1')
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFD').replace(/\p{M}/gu, '')
        .replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
}

function ahxRecipeUnit(value) {
    const unit = ahxRecipeIngredientName(value).replace(/\s/g, '');
    const units = {
        g: ['mass', 1], gramm: ['mass', 1], kg: ['mass', 1000], kilogramm: ['mass', 1000],
        ml: ['volume', 1], milliliter: ['volume', 1], l: ['volume', 1000], liter: ['volume', 1000],
        '': ['piece', 1], stk: ['piece', 1], stueck: ['piece', 1], stuecke: ['piece', 1],
        el: ['tablespoon', 1], essloeffel: ['tablespoon', 1], tl: ['teaspoon', 1], teeloeffel: ['teaspoon', 1]
    };
    const definition = Object.prototype.hasOwnProperty.call(units, unit) ? units[unit] : ['unit:' + unit, 1];
    return {dimension: definition[0], factor: definition[1]};
}

function ahxRecipeMatchPantry(recipe, pantry, servings, synonyms = new Map()) {
    function canonicalName(value) {
        const name = ahxRecipeIngredientName(value);
        return synonyms.get(name) || name;
    }
    const scale = Math.max(1, Number(servings) || 1) / Math.max(1, Number(recipe.servings) || 1);
    const requirements = new Map();
    recipe.ingredients.forEach(function (ingredient) {
        const name = canonicalName(ingredient.label);
        if (!name) {
            return;
        }
        const unit = ahxRecipeUnit(ingredient.unit);
        const key = name + '|' + unit.dimension;
        const quantity = ingredient.quantity === null ? null : Number(ingredient.quantity) * unit.factor * scale;
        const existing = requirements.get(key);
        if (existing) {
            existing.quantity = existing.quantity === null || quantity === null ? null : existing.quantity + quantity;
        } else {
            requirements.set(key, {name: name, label: ingredient.label, unit: ingredient.unit, definition: unit, quantity: quantity});
        }
    });
    const items = Array.from(requirements.values()).map(function (requirement) {
        const available = pantry.filter(function (entry) {
            return canonicalName(entry.label) === requirement.name;
        });
        const aliases = Array.from(new Set(available.filter(function (entry) {
            return ahxRecipeIngredientName(entry.label) !== ahxRecipeIngredientName(requirement.label);
        }).map(function (entry) { return entry.label; })));
        const result = {label: requirement.label, unit: requirement.unit, status: 'missing', required: null, available: null, aliases: aliases};
        if (!available.length) {
            return result;
        }
        const compatible = available.filter(function (entry) {
            return entry.quantity !== null && ahxRecipeUnit(entry.unit).dimension === requirement.definition.dimension;
        });
        if (requirement.quantity === null || !compatible.length || available.some(function (entry) { return entry.quantity === null; })) {
            result.status = 'unknown';
            return result;
        }
        const quantity = compatible.reduce(function (total, entry) {
            return total + Number(entry.quantity) * ahxRecipeUnit(entry.unit).factor;
        }, 0);
        result.required = requirement.quantity / requirement.definition.factor;
        result.available = quantity / requirement.definition.factor;
        result.status = quantity + 0.000001 >= requirement.quantity ? 'ready' : 'short';
        if (result.status === 'short' && compatible.length < available.length) {
            result.status = 'unknown';
        }
        return result;
    });
    return {
        items: items,
        total: items.length,
        matched: items.filter(function (item) { return item.status !== 'missing'; }).length,
        complete: items.length > 0 && items.every(function (item) { return item.status === 'ready' || item.status === 'unknown'; }),
        score: items.length ? items.reduce(function (total, item) {
            return total + (item.status === 'ready' || item.status === 'unknown' ? 1 : item.status === 'short' ? Math.min(0.99, item.available / item.required) : 0);
        }, 0) / items.length : 0
    };
}

document.querySelectorAll('[data-ahx-pantry]').forEach(function (form) {
    const collection = form.closest('.ahx-recipe-collection');
    const recipes = JSON.parse(form.querySelector('[data-ahx-pantry-data]').textContent);
    const synonyms = new Map(Object.entries(JSON.parse(form.querySelector('[data-ahx-pantry-synonyms]').textContent)));
    const rows = form.querySelector('[data-ahx-pantry-rows]');
    const template = form.querySelector('[data-ahx-pantry-row]');
    const status = form.querySelector('[data-ahx-pantry-status]');
    const empty = collection.querySelector('[data-ahx-pantry-empty]');
    const groups = Array.from(collection.querySelectorAll('.ahx-recipe-collection__group')).map(function (element) {
        return {element: element, cards: Array.from(element.querySelectorAll('[data-ahx-pantry-recipe]'))};
    });
    function addRow(focus) {
        if (rows.children.length >= 50) {
            return;
        }
        const row = template.content.firstElementChild.cloneNode(true);
        rows.append(row);
        form.querySelector('[data-ahx-pantry-add]').disabled = rows.children.length >= 50;
        if (focus) {
            row.querySelector('[data-ahx-pantry-name]').focus();
        }
    }
    function formatQuantity(value, unit) {
        return Number(value.toFixed(3)).toLocaleString('de-DE') + (unit ? ' ' + unit : '');
    }
    function update() {
        const pantry = Array.from(rows.children).map(function (row) {
            const quantity = row.querySelector('[data-ahx-pantry-quantity]');
            return {label: row.querySelector('[data-ahx-pantry-name]').value.trim(), quantity: quantity.value === '' ? null : Number(quantity.value), unit: row.querySelector('[data-ahx-pantry-unit]').value};
        }).filter(function (entry) { return entry.label !== ''; });
        const active = pantry.length > 0;
        const servings = Math.min(999, Math.max(1, Number(form.querySelector('[data-ahx-pantry-servings]').value) || 1));
        const completeOnly = form.querySelector('[data-ahx-pantry-complete]').checked;
        const matches = new Map();
        Object.entries(recipes).forEach(function (entry) {
            matches.set(entry[0], ahxRecipeMatchPantry(entry[1], pantry, servings, synonyms));
        });
        const visibleRecipes = new Set();
        groups.forEach(function (group) {
            let visible = 0;
            group.bestScore = -1;
            group.cards.forEach(function (card) {
                const id = card.dataset.ahxPantryRecipe;
                const match = matches.get(id);
                card.hidden = active && (!match.matched || (completeOnly && !match.complete));
                const details = card.querySelector('[data-ahx-pantry-match]');
                details.replaceChildren();
                details.hidden = !active;
                if (active && !card.hidden) {
                    const coverage = document.createElement('p');
                    coverage.className = 'ahx-recipe-list__coverage';
                    coverage.textContent = match.matched + ' von ' + match.total + ' Zutaten vorhanden';
                    details.append(coverage);
                    const missing = match.items.filter(function (item) { return item.status === 'missing'; });
                    const unknown = match.items.filter(function (item) { return item.status === 'unknown'; });
                    const messages = [];
                    match.items.filter(function (item) { return item.aliases.length; }).forEach(function (item) {
                        messages.push('Als Synonym erkannt: ' + item.aliases.join(', ') + ' → ' + item.label);
                    });
                    if (missing.length) {
                        messages.push('Fehlt: ' + missing.map(function (item) { return item.label; }).join(', '));
                    }
                    match.items.filter(function (item) { return item.status === 'short'; }).forEach(function (item) {
                        messages.push('Zu wenig ' + item.label + ': ' + formatQuantity(item.available, item.unit) + ' vorhanden, ' + formatQuantity(item.required, item.unit) + ' benötigt');
                    });
                    if (unknown.length) {
                        messages.push('Menge nicht geprüft: ' + unknown.map(function (item) { return item.label; }).join(', '));
                    }
                    if (match.items.every(function (item) { return item.status === 'ready'; })) {
                        messages.push('Alle Zutaten und Mengen vorhanden');
                    }
                    messages.forEach(function (message) {
                        const paragraph = document.createElement('p');
                        paragraph.textContent = message;
                        details.append(paragraph);
                    });
                }
                if (!card.hidden) {
                    visible++;
                    visibleRecipes.add(id);
                    group.bestScore = Math.max(group.bestScore, match.score);
                }
            });
            const ordered = active ? group.cards.slice().sort(function (first, second) {
                const firstMatch = matches.get(first.dataset.ahxPantryRecipe);
                const secondMatch = matches.get(second.dataset.ahxPantryRecipe);
                return Number(secondMatch.complete) - Number(firstMatch.complete) || secondMatch.score - firstMatch.score || secondMatch.matched - firstMatch.matched;
            }) : group.cards;
            ordered.forEach(function (card) { group.element.querySelector('.ahx-recipe-list').append(card); });
            group.element.hidden = visible === 0;
            group.element.querySelector('.ahx-recipe-collection__count').textContent = '(' + visible + ')';
        });
        const orderedGroups = active ? groups.slice().sort(function (first, second) { return second.bestScore - first.bestScore; }) : groups;
        orderedGroups.forEach(function (group) { collection.append(group.element); });
        status.hidden = !active;
        status.textContent = visibleRecipes.size + (visibleRecipes.size === 1 ? ' passendes Rezept für ' : ' passende Rezepte für ') + servings + (servings === 1 ? ' Portion' : ' Portionen');
        empty.hidden = !active || visibleRecipes.size > 0;
    }
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        update();
    });
    form.addEventListener('input', function () {
        if (form.checkValidity()) {
            update();
        }
    });
    form.addEventListener('change', function () {
        if (form.checkValidity()) {
            update();
        }
    });
    form.addEventListener('click', function (event) {
        if (event.target.closest('[data-ahx-pantry-add]')) {
            addRow(true);
        }
        const remove = event.target.closest('[data-ahx-pantry-remove]');
        if (remove) {
            remove.closest('.ahx-recipe-pantry__row').remove();
            if (!rows.children.length) {
                addRow(false);
            }
            form.querySelector('[data-ahx-pantry-add]').disabled = false;
            rows.lastElementChild.querySelector('[data-ahx-pantry-name]').focus();
            update();
        }
    });
    form.addEventListener('reset', function () {
        setTimeout(function () {
            rows.replaceChildren();
            addRow(false);
            update();
        }, 0);
    });
    addRow(false);
    form.hidden = false;
});

document.addEventListener('input', function (event) {
    const servingsInput = event.target.closest('[data-ahx-servings]');
    if (!servingsInput) {
        return;
    }
    const recipe = servingsInput.closest('[data-ahx-recipe]');
    const servings = Math.max(1, Number(servingsInput.value) || 1);
    const originalServings = Math.max(1, Number(recipe.dataset.baseServings || servingsInput.defaultValue) || 1);
    const bringLink = recipe.querySelector('[data-ahx-bring-mode="recipe"]');
    if (bringLink) {
        const bringUrl = new URL(bringLink.href);
        bringUrl.searchParams.set('requestedQuantity', String(servings));
        bringLink.href = bringUrl.toString();
    }
    recipe.querySelectorAll('[data-ahx-amount]').forEach(function (amountElement) {
        const base = amountElement.dataset.base;
        if (base === '') {
            return;
        }
        const scaled = Number(base) * servings / originalServings;
        amountElement.textContent = Number(scaled.toFixed(3)).toString();
    });
});

document.addEventListener('change', function (event) {
    const layoutSelect = event.target.closest('[data-ahx-layout]');
    if (!layoutSelect) {
        return;
    }

    const recipe = layoutSelect.closest('[data-ahx-recipe]');
    ['classic', 'split', 'checklist'].forEach(function (layout) {
        recipe.classList.toggle('ahx-recipe--' + layout, layoutSelect.value === layout);
    });
});

document.addEventListener('click', function (event) {
    const bringLink = event.target.closest('[data-ahx-bring-open]');
    if (!bringLink) {
        return;
    }
    if (bringLink.dataset.ahxBringMode === 'recipe') {
        return;
    }

    const recipe = bringLink.closest('[data-ahx-recipe]');
    const status = recipe.querySelector('[data-ahx-bring-status]');
    const ingredients = Array.from(recipe.querySelectorAll('.ahx-recipe__ingredient'));
    const text = ingredients.map(function (ingredient) {
        const amount = ingredient.querySelector('[data-ahx-amount]');
        const unit = ingredient.querySelector('[data-ahx-unit]');
        const item = ingredient.querySelector('[data-ahx-item]');
        const addition = ingredient.querySelector('[data-ahx-addition]');
        const mainText = [amount ? amount.textContent.trim() : '', unit ? unit.textContent.trim() : '', item ? item.textContent.trim() : '']
            .filter(Boolean)
            .join(' ');
        return [mainText, addition ? addition.textContent.trim() : ''].filter(Boolean).join('\n');
    }).filter(Boolean).join('\n');

    if (!text) {
        return;
    }

    function reportCopied() {
        status.textContent = 'Zutaten kopiert. Wähle in Bring! eine Liste und füge sie dort ein.';
    }

    function reportCopyFailed() {
        status.textContent = 'Bring! wurde geöffnet. Zutaten konnten nicht automatisch kopiert werden.';
    }

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(reportCopied).catch(reportCopyFailed);
        return;
    }

    const temporaryInput = document.createElement('textarea');
    temporaryInput.value = text;
    temporaryInput.setAttribute('readonly', '');
    temporaryInput.style.position = 'fixed';
    temporaryInput.style.left = '-9999px';
    document.body.appendChild(temporaryInput);
    temporaryInput.select();
    const copied = document.execCommand('copy');
    temporaryInput.remove();
    if (copied) {
        reportCopied();
    } else {
        reportCopyFailed();
    }
});

document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-ahx-step-check]')) {
        return;
    }
    const step = event.target.closest('.ahx-recipe__instructions li');
    if (step) {
        step.classList.toggle('is-checked', event.target.checked);
    }
});

document.querySelectorAll('[data-ahx-recipe]').forEach(function (recipe) {
    const servings = recipe.querySelector('[data-ahx-servings]');
    if (servings) {
        recipe.dataset.baseServings = servings.defaultValue;
    }
    const dialog = recipe.querySelector('[data-ahx-image-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    const fullImage = dialog.querySelector('[data-ahx-image-full]');
    const imageError = dialog.querySelector('[data-ahx-image-error]');
    recipe.addEventListener('click', function (event) {
        const imageLink = event.target.closest('[data-ahx-recipe-image]');
        if (!imageLink || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        imageError.hidden = true;
        fullImage.hidden = false;
        fullImage.alt = imageLink.querySelector('img').alt || recipe.querySelector('meta[itemprop="name"]').content;
        fullImage.src = imageLink.href;
        dialog.showModal();
        document.body.classList.add('ahx-recipe-image-open');
    });
    fullImage.addEventListener('error', function () {
        fullImage.hidden = true;
        imageError.hidden = false;
    });
    fullImage.addEventListener('load', function () {
        fullImage.hidden = false;
        imageError.hidden = true;
    });
    dialog.querySelector('[data-ahx-image-close]').addEventListener('click', function () {
        dialog.close();
    });
    dialog.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            dialog.close();
        }
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener('close', function () {
        document.body.classList.remove('ahx-recipe-image-open');
    });
});