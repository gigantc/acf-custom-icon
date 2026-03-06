(function() {
    'use strict';

    function updateSelectedBar(picker, tile) {
        var nameEl = picker.querySelector('.acf-icon-selected-name');
        var previewEl = picker.querySelector('.acf-icon-selected-preview');
        if (!nameEl || !previewEl) return;

        var iconName = tile.getAttribute('title') || 'None';
        var iconSvg = tile.getAttribute('data-icon-svg');
        var iconStyle = tile.getAttribute('data-icon-style') || 'line';

        nameEl.textContent = iconName;
        previewEl.setAttribute('data-icon-style', iconStyle);

        if (iconSvg) {
            previewEl.innerHTML = iconSvg;
        } else {
            previewEl.innerHTML = '<span class="dashicons dashicons-minus"></span>';
        }
    }

    function initIconPicker(picker) {
        var tiles = picker.querySelectorAll('.icon-tile');
        var panel = picker.querySelector('.acf-icon-picker-panel');
        var editBtn = picker.querySelector('.acf-icon-edit-btn');

        // Toggle panel on edit/close button click
        if (editBtn && panel) {
            editBtn.addEventListener('click', function() {
                var isOpen = panel.style.display !== 'none';
                panel.style.display = isOpen ? 'none' : 'block';
                editBtn.textContent = isOpen ? 'Edit' : 'Close';
            });
        }

        tiles.forEach(function(tile) {
            tile.addEventListener('click', function() {
                var radio = tile.querySelector('input[type="radio"]');
                if (!radio) return;

                // Uncheck all tiles in this picker
                tiles.forEach(function(t) {
                    t.classList.remove('selected');
                });

                // Select this tile and notify ACF/Gutenberg of the change
                radio.checked = true;
                tile.classList.add('selected');
                radio.dispatchEvent(new Event('change', { bubbles: true }));

                // Update the selected bar and collapse the panel
                updateSelectedBar(picker, tile);
                if (panel) panel.style.display = 'none';
                if (editBtn) editBtn.textContent = 'Edit';
            });
        });

        // Reflect initial checked state on load
        tiles.forEach(function(tile) {
            var radio = tile.querySelector('input[type="radio"]');
            if (radio && radio.checked) {
                tile.classList.add('selected');
            }
        });

        var searchInput = picker.querySelector('.acf-icon-search-input');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var query = searchInput.value.toLowerCase().trim();
                tiles.forEach(function(tile) {
                    if (!tile.dataset.iconName) return; // skip None tile from filtering
                    var name = (tile.dataset.iconName || '').toLowerCase();
                    if (query === '' || name.indexOf(query) !== -1) {
                        tile.classList.remove('hidden');
                    } else {
                        tile.classList.add('hidden');
                    }
                });
            });
        }
    }

    function initAllPickers(root) {
        var pickers = (root || document).querySelectorAll('.acf-icon-picker-wrap');
        pickers.forEach(function(picker) {
            initIconPicker(picker);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            initAllPickers();
        });
    } else {
        initAllPickers();
    }

    // Re-initialize when ACF appends new fields (repeaters, flexible content)
    if (typeof acf !== 'undefined') {
        acf.addAction('append', function($el) {
            var el = $el[0] || $el;
            initAllPickers(el);
        });
    }
})();
