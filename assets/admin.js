document.addEventListener('click', function (event) {
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