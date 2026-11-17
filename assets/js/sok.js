(function () {
    if (window.LTSSok) {
        return;
    }
    window.LTSSok = true;

    function rootOf(node) {
        while (node && node !== document) {
            if (node.getAttribute && node.getAttribute('data-lts-sok') !== null) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    function apply(root) {
        var input = root.querySelector('.lts-sok__input');
        var select = root.querySelector('.lts-sok__fylke');
        var items = root.querySelectorAll('.lts-sok__item');
        var count = root.querySelector('.lts-sok__count');
        var query = input ? String(input.value || '').toLowerCase().trim() : '';
        var digits = query.replace(/\D/g, '');
        var words = query.replace(/\d/g, ' ').replace(/\s+/g, ' ').trim();
        var fylke = select ? select.value : '';
        var shown = 0;
        var i;

        for (i = 0; i < items.length; i++) {
            var item = items[i];
            var name = item.getAttribute('data-name') || '';
            var county = item.getAttribute('data-fylke') || '';
            var codes = (item.getAttribute('data-postnummer') || '').split(' ');
            var ok = !fylke || item.getAttribute('data-fylke-slug') === fylke;
            if (ok && words && name.indexOf(words) === -1 && county.indexOf(words) === -1) {
                ok = false;
            }
            if (ok && digits) {
                var hit = false;
                var j;
                for (j = 0; j < codes.length; j++) {
                    if (codes[j].indexOf(digits) === 0) {
                        hit = true;
                        break;
                    }
                }
                if (!hit) {
                    ok = false;
                }
            }
            if (ok) {
                item.className = 'lts-sok__item';
                shown++;
            } else {
                item.className = 'lts-sok__item is-hidden';
            }
        }

        if (count) {
            var one = root.getAttribute('data-label-one') || 'sted';
            var many = root.getAttribute('data-label-many') || 'steder';
            count.textContent = shown + ' ' + (shown === 1 ? one : many);
        }
        if (select) {
            var all = select.querySelector('option[value=""]');
            var label = root.getAttribute('data-label-all') || 'Alle fylker (%s)';
            if (all) {
                all.textContent = label.replace('%s', String(items.length));
            }
        }
    }

    document.addEventListener('input', function (event) {
        var root = rootOf(event.target);
        if (root) {
            apply(root);
        }
    });
    document.addEventListener('change', function (event) {
        var root = rootOf(event.target);
        if (root) {
            apply(root);
        }
    });
})();
