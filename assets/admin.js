document.addEventListener('click', function (event) {
    const synonymAdd = event.target.closest('[data-ahx-synonym-add]');
    if (synonymAdd) {
        const form = synonymAdd.closest('[data-ahx-synonyms]');
        const rows = form.querySelector('[data-ahx-synonym-rows]');
        if (rows.children.length >= 200) {
            return;
        }
        const index = Math.max(-1, ...Array.from(rows.children).map(function (row) { return Number(row.dataset.ahxSynonymIndex); })) + 1;
        const row = form.querySelector('[data-ahx-synonym-template]').content.firstElementChild.cloneNode(true);
        row.dataset.ahxSynonymIndex = String(index);
        row.querySelectorAll('[name]').forEach(function (input) {
            input.name = input.name.replace('[__index__]', '[' + index + ']');
        });
        rows.append(row);
        synonymAdd.disabled = rows.children.length >= 200;
        row.querySelector('input').focus();
    }
    const synonymRemove = event.target.closest('[data-ahx-synonym-remove]');
    if (synonymRemove) {
        const form = synonymRemove.closest('[data-ahx-synonyms]');
        const rows = form.querySelector('[data-ahx-synonym-rows]');
        const row = synonymRemove.closest('.ahx-recipe-synonym-row');
        if (rows.children.length > 1) {
            row.remove();
        } else {
            row.querySelectorAll('input, textarea').forEach(function (input) { input.value = ''; });
        }
        form.querySelector('[data-ahx-synonym-add]').disabled = false;
        rows.lastElementChild.querySelector('input').focus();
    }
    const imageSelectionButton = event.target.closest('[data-ahx-recipe-select-images]');
    if (imageSelectionButton) {
        imageSelectionButton.closest('form').querySelectorAll('.ahx-recipe-image-choice input[name="recipe_images[]"]').forEach(function (input) {
            input.checked = imageSelectionButton.dataset.ahxRecipeSelectImages === 'all';
        });
    }

    const addButton = event.target.closest('.ahx-recipe-add-ingredient');
    if (addButton) {
        const rows = document.querySelector('.ahx-recipe-ingredient-rows');
        const indexes = Array.from(rows.querySelectorAll('input[name*="[quantity]"]')).map(function (input) {
            const match = input.name.match(/\[(\d+)\]\[quantity\]/);
            return match ? Number(match[1]) : -1;
        });
        const index = Math.max(-1, ...indexes) + 1;
        const row = document.createElement('div');
        row.className = 'ahx-recipe-row';
        row.innerHTML = '<input name="ahx_recipe_ingredients[' + index + '][quantity]" type="text" inputmode="decimal" placeholder="Menge"><input name="ahx_recipe_ingredients[' + index + '][unit]" type="text" placeholder="Einheit"><input name="ahx_recipe_ingredients[' + index + '][label]" type="text" placeholder="Bezeichnung"><input name="ahx_recipe_ingredients[' + index + '][addition]" type="text" placeholder="Ergänzung"><button type="button" class="button ahx-recipe-remove-row" aria-label="Zutat entfernen">&minus;</button>';
        rows.append(row);
    }

    const removeButton = event.target.closest('.ahx-recipe-remove-row');
    if (removeButton) {
        const rows = document.querySelectorAll('.ahx-recipe-row');
        if (rows.length > 1) {
            removeButton.closest('.ahx-recipe-row').remove();
        } else {
            removeButton.closest('.ahx-recipe-row').querySelectorAll('input').forEach(function (input) { input.value = ''; });
        }
    }
});

document.querySelectorAll('.ahx-recipe-image-choice img').forEach(function (image) {
    const dimensions = image.closest('.ahx-recipe-image-choice').querySelector('.ahx-recipe-image-dimensions');
    function updateDimensions() {
        dimensions.textContent = image.naturalWidth && image.naturalHeight
            ? image.naturalWidth + ' \u00d7 ' + image.naturalHeight + ' px'
            : dimensions.dataset.unavailable;
    }
    image.addEventListener('load', updateDimensions);
    image.addEventListener('error', updateDimensions);
    if (image.complete) {
        updateDimensions();
    }
});