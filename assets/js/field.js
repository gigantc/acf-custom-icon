(function() {
    'use strict';

    function initIconPicker(picker) {
        var tiles = picker.querySelectorAll('.icon-tile');

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
