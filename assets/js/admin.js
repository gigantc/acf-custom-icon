(function($) {
    'use strict';

    $(function() {

        // Delete confirm
        document.querySelectorAll('.icon-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var name = form.dataset.iconName || 'this icon';
                if (!confirm('Delete "' + name + '"? This cannot be undone.')) {
                    e.preventDefault();
                }
            });
        });

        // Show rename form
        document.querySelectorAll('.icon-edit-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var item = btn.closest('.acf-icon-item');
                item.querySelector('.acf-icon-item__name').hidden = true;
                item.querySelector('.acf-icon-item__actions').hidden = true;
                var renameEl = item.querySelector('.acf-icon-item__rename');
                renameEl.hidden = false;
                renameEl.querySelector('input[type="text"]').focus();
            });
        });

        // Cancel rename
        document.querySelectorAll('.icon-rename-cancel').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var item = btn.closest('.acf-icon-item');
                item.querySelector('.acf-icon-item__rename').hidden = true;
                item.querySelector('.acf-icon-item__name').hidden = false;
                item.querySelector('.acf-icon-item__actions').hidden = false;
            });
        });

        // Drag-to-reorder
        var $grid = $('.acf-icon-library__grid');
        if ($grid.length) {
            $grid.sortable({
                handle: '.acf-icon-item__drag-handle',
                tolerance: 'pointer',
                placeholder: 'acf-icon-placeholder',
                forcePlaceholderSize: true,
                stop: function() {
                    var order = [];
                    $grid.find('.acf-icon-item').each(function() {
                        order.push($(this).data('icon-id'));
                    });

                    $.post(acfIconLibrary.ajaxUrl, {
                        action: 'acf_icon_reorder',
                        nonce:  acfIconLibrary.nonce,
                        order:  order
                    }).done(function(res) {
                        if (res.success) {
                            var msg = document.getElementById('acf-icon-order-saved');
                            if (msg) {
                                msg.style.display = 'inline';
                                setTimeout(function() { msg.style.display = 'none'; }, 2000);
                            }
                        }
                    });
                }
            });
        }

    });

})(jQuery);
