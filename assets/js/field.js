(function() {
    'use strict';

    function getPicker(el) {
        return el.closest ? el.closest('.acf-icon-picker-wrap') : null;
    }

    function updateSelectedBar(picker, tile) {
        var nameEl = picker.querySelector('.acf-icon-selected-name');
        var previewEl = picker.querySelector('.acf-icon-selected-preview');
        if (!nameEl || !previewEl) return;

        var iconName = tile.getAttribute('title') || 'None';
        var iconStyle = tile.getAttribute('data-icon-style') || 'line';
        var tilePreview = tile.querySelector('.icon-preview');

        nameEl.textContent = iconName;
        previewEl.setAttribute('data-icon-style', iconStyle);

        if (tilePreview && !tilePreview.classList.contains('icon-preview--empty')) {
            previewEl.innerHTML = tilePreview.innerHTML;
        } else {
            previewEl.innerHTML = '<span class="dashicons dashicons-minus"></span>';
        }
    }

    function togglePanel(picker, forceOpen) {
        var panel = picker.querySelector('.acf-icon-picker-panel');
        var editBtn = picker.querySelector('.acf-icon-edit-btn');
        if (!panel) return;

        var isOpen = panel.style.display !== 'none' && panel.style.display !== '';
        var open = (typeof forceOpen === 'boolean') ? forceOpen : !isOpen;
        panel.style.display = open ? 'block' : 'none';
        if (editBtn) editBtn.textContent = open ? 'Close' : 'Edit';
    }

    function selectTile(picker, tile) {
        var radio = tile.querySelector('input[type="radio"]');
        if (!radio) return;

        picker.querySelectorAll('.icon-tile').forEach(function(t) {
            t.classList.remove('selected');
        });

        radio.checked = true;
        tile.classList.add('selected');
        radio.dispatchEvent(new Event('change', { bubbles: true }));

        updateSelectedBar(picker, tile);
        togglePanel(picker, false);
    }

    function filterTiles(picker, query) {
        var q = (query || '').toLowerCase().trim();
        picker.querySelectorAll('.icon-tile').forEach(function(tile) {
            if (!tile.dataset.iconName) return; // skip None tile
            var name = (tile.dataset.iconName || '').toLowerCase();
            if (q === '' || name.indexOf(q) !== -1) {
                tile.classList.remove('hidden');
            } else {
                tile.classList.add('hidden');
            }
        });
    }

    // Delegated click: works for any picker present now or added later
    // (ACF block re-renders, repeater rows, flexible content layouts, WP 7.x
    // block editor lifecycle changes). No per-node listeners, no re-init needed.
    document.addEventListener('click', function(e) {
        var target = e.target;
        if (!target || !target.closest) return;

        var editBtn = target.closest('.acf-icon-edit-btn');
        if (editBtn) {
            var picker = getPicker(editBtn);
            if (picker) togglePanel(picker);
            return;
        }

        var tile = target.closest('.icon-tile');
        if (tile) {
            var pickerForTile = getPicker(tile);
            if (pickerForTile) selectTile(pickerForTile, tile);
        }
    });

    document.addEventListener('input', function(e) {
        var input = e.target;
        if (!input || !input.classList || !input.classList.contains('acf-icon-search-input')) return;
        var picker = getPicker(input);
        if (picker) filterTiles(picker, input.value);
    });
})();
