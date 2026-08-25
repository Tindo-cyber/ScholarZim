/**
 * Select-all and a live count for the bulk action bars on the provider inbox and
 * the admin moderation queue.
 *
 * Progressive enhancement only: without this file the checkboxes and the submit
 * button still work, they just do not tell you how many are ticked.
 */
document.addEventListener('DOMContentLoaded', function () {
    var tables = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-table]'));

    tables.forEach(function (table) {
        // A table whose rows carry their own forms cannot be wrapped in the bulk
        // form, so it names the form by id and the checkboxes join it with the
        // HTML form attribute instead.
        var formId = table.getAttribute('data-bulk-form');
        var form = formId ? document.getElementById(formId) : table.closest('form');

        if (!form) {
            return;
        }

        var toggleAll = table.querySelector('[data-bulk-toggle-all]');
        var counter = form.querySelector('[data-bulk-count]');

        function items() {
            return Array.prototype.slice.call(table.querySelectorAll('[data-bulk-item]:not(:disabled)'));
        }

        function selected() {
            return items().filter(function (item) {
                return item.checked;
            });
        }

        function sync() {
            var count = selected().length;

            if (counter) {
                counter.textContent = String(count);
            }

            if (toggleAll) {
                var all = items().length;
                toggleAll.checked = all > 0 && count === all;
                // Neither ticked nor empty: the box says "some", not "all".
                toggleAll.indeterminate = count > 0 && count < all;
            }
        }

        if (toggleAll) {
            toggleAll.addEventListener('change', function () {
                items().forEach(function (item) {
                    item.checked = toggleAll.checked;
                });
                sync();
            });
        }

        table.addEventListener('change', function (event) {
            if (event.target && event.target.hasAttribute('data-bulk-item')) {
                sync();
            }
        });

        form.addEventListener('submit', function (event) {
            if (selected().length === 0) {
                event.preventDefault();
                window.alert('Select at least one row first.');
            }
        });

        sync();
    });
});
