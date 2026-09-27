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

document.addEventListener('change', function (event) {
    if (!event.target.matches('[data-ahx-check]')) {
        return;
    }
    const item = event.target.closest('.ahx-recipe__ingredient');
    if (item) {
        item.classList.toggle('is-checked', event.target.checked);
    }
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
    const checkedIngredients = ingredients.filter(function (ingredient) {
        return ingredient.querySelector('[data-ahx-check]').checked;
    });
    const selectedIngredients = checkedIngredients.length ? checkedIngredients : ingredients;
    const text = selectedIngredients.map(function (ingredient) {
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
});