(function($) {
    'use strict';

    $(function() {

        // Delete confirm. Asks the server where the icon is used first; any failure falls back to the plain confirm.
        var i18n = acfIconLibrary.i18n || {};

        document.querySelectorAll('.icon-delete-form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                if (form.dataset.confirmed === '1') return;
                e.preventDefault();

                var name = form.dataset.iconName || i18n.thisIcon || 'this icon';
                var plainConfirm = function() {
                    return confirm((i18n.confirmDelete || 'Delete "%s"? This cannot be undone.').replace('%s', function() { return name; }));
                };
                var proceed = function(ok) {
                    if (!ok) return;
                    form.dataset.confirmed = '1';
                    HTMLFormElement.prototype.submit.call(form);
                };

                $.post(acfIconLibrary.ajaxUrl, {
                    action:  'acf_icon_usage',
                    nonce:   acfIconLibrary.nonce,
                    icon_id: form.querySelector('input[name="icon_id"]').value
                }).done(function(res) {
                    if (res && res.success && res.data && res.data.message) {
                        proceed(confirm(res.data.message));
                    } else {
                        proceed(plainConfirm());
                    }
                }).fail(function() {
                    proceed(plainConfirm());
                });
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
