(function () {
    var cfg = window.ltsEditor;
    var root = document.getElementById('lts-editor');
    if (!cfg || !root) {
        return;
    }

    var servicesBox = document.getElementById('lts-services');
    var faqBox = document.getElementById('lts-faq');
    var blocksBox = document.getElementById('lts-blocks');
    var chipsBox = document.getElementById('lts-chips');
    var titleInput = document.getElementById('lts-title');
    var wpTitle = document.getElementById('title');
    var thumbId = document.getElementById('lts-thumbnail');
    var thumb = document.getElementById('lts-thumb');
    var thumbImg = document.getElementById('lts-thumb-img');
    var clearThumb = document.getElementById('lts-thumb-clear');
    var servicesBySlug = {};
    var kept = {};
    var customHours = null;
    var memory = null;
    var saving = false;
    var toastTimer = null;
    var frame = null;

    if (cfg.services) {
        for (var i = 0; i < cfg.services.length; i++) {
            servicesBySlug[cfg.services[i].slug] = cfg.services[i];
        }
    }

    function format(template, value) {
        return String(template).replace('%s', value);
    }

    function slugify(text) {
        return String(text || '')
            .toLowerCase()
            .replace(/æ/g, 'ae')
            .replace(/ø/g, 'o')
            .replace(/å/g, 'a')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function money(raw) {
        var normalized = String(raw).trim().replace(/\s/g, '').replace(',', '.');
        if (normalized === '' || isNaN(Number(normalized))) {
            return '';
        }
        var negative = normalized.charAt(0) === '-';
        if (negative) {
            normalized = normalized.slice(1);
        }
        var parts = normalized.split('.');
        var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
        var text = parts.length > 1 && parts[1] !== '' ? whole + ',' + parts[1] : whole;
        return (negative ? '−' : '') + text + ' kr';
    }

    function namedFields() {
        return root.querySelectorAll('input, textarea, select');
    }

    function capture() {
        var fields = [];
        var nodes = namedFields();
        for (var index = 0; index < nodes.length; index++) {
            var field = nodes[index];
            if (!field.name) {
                continue;
            }
            fields.push({
                name: field.name,
                type: field.type,
                value: field.value,
                checked: !!field.checked
            });
        }
        return fields;
    }

    function serialize() {
        var fields = capture();
        var parts = [];
        for (var index = 0; index < fields.length; index++) {
            var field = fields[index];
            if (field.type === 'checkbox' || field.type === 'radio') {
                parts.push(field.name + '=' + (field.checked ? field.value : ''));
            } else {
                parts.push(field.name + '=' + field.value);
            }
        }
        return parts.join('\n');
    }

    function apply(fields) {
        var queues = {};
        for (var index = 0; index < fields.length; index++) {
            var name = fields[index].name;
            if (!queues[name]) {
                queues[name] = [];
            }
            queues[name].push(fields[index]);
        }
        var nodes = namedFields();
        for (var nodeIndex = 0; nodeIndex < nodes.length; nodeIndex++) {
            var field = nodes[nodeIndex];
            if (!field.name || !queues[field.name] || !queues[field.name].length) {
                continue;
            }
            var saved = queues[field.name].shift();
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = saved.checked;
            } else {
                field.value = saved.value;
            }
        }
    }

    function nameBlockRow(row, slug) {
        var nameField = row.querySelector('[data-lts-block-field="name"]');
        var idField = row.querySelector('[data-lts-block-field="id"]');
        if (nameField) {
            nameField.name = 'lts_block_name[' + slug + '][]';
        }
        if (idField) {
            idField.name = 'lts_block_id[' + slug + '][]';
        }
    }

    function syncBlockLink(row) {
        var select = row.querySelector('[data-lts-block]');
        var link = row.querySelector('.lts-block-edit');
        var holder = row.querySelector('.lts-layout-row');
        if (!select || !link) {
            return;
        }
        var option = select.options[select.selectedIndex];
        var href = option ? option.getAttribute('data-edit') || '' : '';
        if (href) {
            link.setAttribute('href', href);
            if (holder) {
                holder.hidden = false;
            }
        } else if (holder) {
            holder.hidden = true;
        }
    }

    function remember() {
        memory = {
            services: servicesBox ? servicesBox.innerHTML : '',
            faq: faqBox ? faqBox.innerHTML : '',
            blocks: blocksBox ? blocksBox.innerHTML : '',
            chips: chipsBox ? chipsBox.innerHTML : '',
            thumb: thumbImg ? thumbImg.getAttribute('src') || '' : '',
            fields: capture()
        };
        memory.serial = serialize();
    }

    function mark() {
        var dirty = !memory || serialize() !== memory.serial;
        var bar = root.querySelector('.lts-dirty');
        if (bar) {
            bar.hidden = !dirty;
        }
        root.classList.toggle('is-dirty', dirty);
    }

    function syncTitle() {
        if (titleInput && wpTitle) {
            wpTitle.value = titleInput.value;
        }
    }

    function placeTitle() {
        return titleInput ? titleInput.value.trim() : '';
    }

    function placeSlug() {
        if (cfg.placeSlug) {
            return cfg.placeSlug;
        }
        return slugify(placeTitle());
    }

    function hoursMode() {
        var hidden = root.querySelector('[name="lts_hours_mode"]');
        return hidden ? hidden.value : 'custom';
    }

    function selectedFylke() {
        var select = document.getElementById('lts_fylke_id');
        if (!select || !cfg.fylker) {
            return null;
        }
        var id = parseInt(select.value, 10);
        for (var index = 0; index < cfg.fylker.length; index++) {
            if (cfg.fylker[index].id === id) {
                return cfg.fylker[index];
            }
        }
        return null;
    }

    function syncDay(row) {
        var box = row.querySelector('[data-lts-day]');
        var state = row.querySelector('[data-lts-state]');
        var open = !!(box && box.checked);
        if (state) {
            state.value = open ? 'open' : 'closed';
        }
        row.classList.toggle('is-open', open);
        var label = row.querySelector('[data-lts-day-label]');
        if (label) {
            label.textContent = open ? cfg.i18n.open : cfg.i18n.closed;
        }
    }

    function readHours() {
        var data = {};
        var rows = root.querySelectorAll('.lts-day');
        for (var index = 0; index < rows.length; index++) {
            var day = rows[index].getAttribute('data-day');
            var box = rows[index].querySelector('[data-lts-day]');
            var opens = rows[index].querySelector('[data-lts-opens]');
            var closes = rows[index].querySelector('[data-lts-closes]');
            data[day] = {
                open: !!(box && box.checked),
                opens: opens ? opens.value : '',
                closes: closes ? closes.value : ''
            };
        }
        return data;
    }

    function writeHours(data) {
        var rows = root.querySelectorAll('.lts-day');
        for (var index = 0; index < rows.length; index++) {
            var day = rows[index].getAttribute('data-day');
            var slot = data && data[day] ? data[day] : { open: false, opens: '', closes: '' };
            var box = rows[index].querySelector('[data-lts-day]');
            var opens = rows[index].querySelector('[data-lts-opens]');
            var closes = rows[index].querySelector('[data-lts-closes]');
            if (box) {
                box.checked = !!slot.open;
            }
            if (opens) {
                opens.value = slot.opens || '';
            }
            if (closes) {
                closes.value = slot.closes || '';
            }
            syncDay(rows[index]);
        }
    }

    function lockFromMode() {
        var mode = hoursMode();
        var list = root.querySelector('.lts-days');
        if (list) {
            list.classList.toggle('is-locked', mode === 'inherit');
        }
        var buttons = root.querySelectorAll('[data-lts-hours-mode] button');
        for (var index = 0; index < buttons.length; index++) {
            buttons[index].setAttribute('aria-pressed', buttons[index].getAttribute('data-value') === mode ? 'true' : 'false');
        }
    }

    function setMode(mode) {
        var hidden = root.querySelector('[name="lts_hours_mode"]');
        if (!hidden || (mode !== 'inherit' && mode !== 'custom')) {
            return;
        }
        if (hidden.value === 'custom' && mode === 'inherit') {
            customHours = readHours();
        }
        hidden.value = mode;
        if (mode === 'inherit') {
            var fylke = selectedFylke();
            writeHours(fylke ? fylke.hours : null);
        } else if (customHours) {
            writeHours(customHours);
        } else {
            customHours = readHours();
        }
        lockFromMode();
        refresh();
        mark();
    }

    function readService(row) {
        var on = row.querySelector('[data-lts-offer]');
        var price = row.querySelector('[data-lts-price]');
        var local = row.querySelector('[data-lts-local]');
        var seo = row.querySelector('[data-lts-seo]');
        var meta = row.querySelector('[data-lts-meta]');
        var layout = row.querySelector('.lts-layout');
        var note = row.querySelector('[data-lts-note]');
        return {
            on: !!(on && on.checked),
            price: price ? price.value : '',
            description: local ? local.value : '',
            seo: seo ? seo.value : '',
            meta: meta ? meta.value : '',
            layout: layout ? layout.getAttribute('href') || '' : '',
            note: note ? note.value : ''
        };
    }

    function rememberServices() {
        if (!servicesBox) {
            return;
        }
        var rows = servicesBox.querySelectorAll('.lts-service');
        for (var index = 0; index < rows.length; index++) {
            kept[rows[index].getAttribute('data-slug')] = readService(rows[index]);
        }
    }

    function emptyMessage(text) {
        var paragraph = document.createElement('p');
        paragraph.className = 'lts-empty';
        paragraph.textContent = text;
        return paragraph;
    }

    function fieldLabel(text, control, hint, max) {
        var wrap = document.createElement('label');
        wrap.className = 'lts-field';
        if (max) {
            var row = document.createElement('span');
            row.className = 'lts-label-row';
            var name = document.createElement('span');
            name.textContent = text;
            var count = document.createElement('span');
            count.className = 'lts-count';
            count.setAttribute('data-max', String(max));
            row.appendChild(name);
            row.appendChild(count);
            wrap.appendChild(row);
        } else {
            var span = document.createElement('span');
            span.textContent = text;
            wrap.appendChild(span);
        }
        wrap.appendChild(control);
        if (hint) {
            var help = document.createElement('span');
            help.className = 'lts-h';
            help.textContent = hint;
            wrap.appendChild(help);
        }
        return wrap;
    }

    function serviceNode(service, state) {
        var slug = service.slug;
        var wrap = document.createElement('div');
        wrap.className = 'lts-offer lts-service';
        wrap.setAttribute('data-slug', slug);
        wrap.setAttribute('data-title', service.title);
        if (service.base) {
            wrap.setAttribute('data-base', service.base);
        }

        var head = document.createElement('div');
        head.className = 'lts-service-head';
        var toggle = document.createElement('label');
        toggle.className = 'lts-switch';
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'lts_offer[' + slug + '][on]';
        checkbox.value = '1';
        checkbox.checked = !!state.on;
        checkbox.setAttribute('data-lts-offer', '');
        var knob = document.createElement('span');
        toggle.appendChild(checkbox);
        toggle.appendChild(knob);
        var name = document.createElement('span');
        name.className = 'lts-service-name';
        name.textContent = service.title;
        var pill = document.createElement('span');
        pill.className = 'lts-tag';
        pill.setAttribute('data-lts-price-pill', '');
        head.appendChild(toggle);
        head.appendChild(name);
        head.appendChild(pill);

        var fields = document.createElement('div');
        fields.className = 'lts-offer-fields';
        fields.hidden = !state.on;

        var price = document.createElement('input');
        price.type = 'text';
        price.setAttribute('inputmode', 'decimal');
        price.setAttribute('data-lts-price', '');
        price.name = 'lts_prices[' + slug + ']';
        price.value = state.price || '';
        fields.appendChild(fieldLabel(cfg.i18n.localPriceLabel, price, '', 0));

        var note = document.createElement('input');
        note.type = 'text';
        note.setAttribute('data-lts-note', '');
        note.name = 'lts_price_notes[' + slug + ']';
        note.value = state.note || '';
        fields.appendChild(fieldLabel(cfg.i18n.priceNote, note, cfg.i18n.priceNoteHint, 0));

        var local = document.createElement('textarea');
        local.rows = 4;
        local.setAttribute('data-lts-local', '');
        local.name = 'lts_offer[' + slug + '][description]';
        local.value = state.description || '';
        fields.appendChild(fieldLabel(cfg.i18n.localText, local, cfg.i18n.localTextHint, 0));

        var snippet = document.createElement('div');
        snippet.className = 'lts-snippet';
        snippet.setAttribute('data-lts-snippet', '');
        snippet.appendChild(document.createElement('div')).className = 'lts-snippet-url';
        snippet.appendChild(document.createElement('div')).className = 'lts-snippet-title';
        snippet.appendChild(document.createElement('div')).className = 'lts-snippet-desc';
        fields.appendChild(snippet);

        var seo = document.createElement('input');
        seo.type = 'text';
        seo.setAttribute('data-lts-seo', '');
        seo.setAttribute('data-lts-counted', '');
        seo.setAttribute('data-max', '60');
        seo.name = 'lts_offer[' + slug + '][seo_title]';
        seo.value = state.seo || '';
        var meta = document.createElement('textarea');
        meta.rows = 3;
        meta.setAttribute('data-lts-meta', '');
        meta.setAttribute('data-lts-counted', '');
        meta.setAttribute('data-max', '155');
        meta.name = 'lts_offer[' + slug + '][meta_description]';
        meta.value = state.meta || '';
        var seoPair = document.createElement('div');
        seoPair.className = 'lts-pair';
        seoPair.appendChild(fieldLabel(cfg.i18n.seoTitle, seo, cfg.i18n.seoHint, 60));
        seoPair.appendChild(fieldLabel(cfg.i18n.metaLabel, meta, '', 155));
        fields.appendChild(seoPair);

        var layoutRow = document.createElement('p');
        layoutRow.className = 'lts-layout-row';
        var layout = document.createElement('a');
        layout.className = 'lts-layout';
        layout.textContent = cfg.i18n.layout;
        var layoutHref = state.layout || service.template || '';
        if (layoutHref) {
            layout.href = layoutHref;
        } else {
            layoutRow.hidden = true;
        }
        layoutRow.appendChild(layout);
        fields.appendChild(layoutRow);

        wrap.appendChild(head);
        wrap.appendChild(fields);
        return wrap;
    }

    function onFylkeChange() {
        if (!servicesBox || servicesBox.getAttribute('data-kommune') !== '1') {
            refresh();
            mark();
            return;
        }
        rememberServices();
        var fylke = selectedFylke();
        while (servicesBox.firstChild) {
            servicesBox.removeChild(servicesBox.firstChild);
        }
        if (!fylke) {
            servicesBox.appendChild(emptyMessage(cfg.i18n.pickCounty));
        } else if (!fylke.services || !fylke.services.length) {
            servicesBox.appendChild(emptyMessage(cfg.i18n.noServices));
        } else {
            for (var index = 0; index < fylke.services.length; index++) {
                var slug = fylke.services[index];
                var service = servicesBySlug[slug];
                if (!service) {
                    continue;
                }
                var state = kept[slug] || { on: false, price: '', description: '', seo: '', meta: '', layout: '', note: '' };
                servicesBox.appendChild(serviceNode(service, state));
            }
            if (!servicesBox.children.length) {
                servicesBox.appendChild(emptyMessage(cfg.i18n.noServices));
            }
        }
        if (hoursMode() === 'inherit') {
            writeHours(fylke ? fylke.hours : null);
        }
        refresh();
        mark();
    }

    function refreshCounts() {
        var fields = root.querySelectorAll('[data-lts-counted]');
        for (var index = 0; index < fields.length; index++) {
            var input = fields[index];
            var max = parseInt(input.getAttribute('data-max'), 10);
            var label = input.parentNode ? input.parentNode.querySelector('.lts-count') : null;
            if (!label || !max) {
                continue;
            }
            var length = input.value.length;
            label.textContent = String(length) + '/' + String(max);
            label.classList.toggle('is-over', length > max);
        }
    }

    function refreshSnippet(row) {
        var box = row.querySelector('[data-lts-snippet]');
        if (!box) {
            return;
        }
        var seo = row.querySelector('[data-lts-seo]');
        var meta = row.querySelector('[data-lts-meta]');
        var local = row.querySelector('[data-lts-local]');
        var description = root.querySelector('[name="lts_description"]');
        var serviceTitle = row.getAttribute('data-title') || '';
        var where = placeTitle();
        var title = seo && seo.value.trim() ? seo.value.trim() : serviceTitle;
        if (!(seo && seo.value.trim()) && where) {
            title = serviceTitle + ' ' + cfg.i18n.inPlace + ' ' + where;
        }
        var desc = '';
        if (meta && meta.value.trim()) {
            desc = meta.value.trim();
        } else if (local && local.value.trim()) {
            desc = local.value.trim();
        } else if (description) {
            desc = description.value.trim();
        }
        desc = desc.replace(/\s+/g, ' ');
        if (desc.length > 155) {
            desc = desc.slice(0, 155).trim() + '…';
        }
        var url = box.querySelector('.lts-snippet-url');
        var titleNode = box.querySelector('.lts-snippet-title');
        var descNode = box.querySelector('.lts-snippet-desc');
        if (url) {
            var base = row.getAttribute('data-base') || '';
            var parts = [cfg.host];
            if (base) {
                parts.push(base);
            }
            parts.push(placeSlug() || '…');
            parts.push(row.getAttribute('data-slug') || '');
            url.textContent = parts.join(' › ');
        }
        if (titleNode) {
            titleNode.textContent = title;
        }
        if (descNode) {
            descNode.textContent = desc;
        }
    }

    function refreshPill(row) {
        var pill = row.querySelector('[data-lts-price-pill]');
        if (!pill) {
            return;
        }
        var on = row.querySelector('[data-lts-offer]');
        var price = row.querySelector('[data-lts-price]');
        var amount = price ? price.value.trim() : '';
        if (on && on.checked && amount !== '') {
            var pretty = money(amount);
            pill.textContent = pretty ? format(cfg.i18n.localPrice, pretty) : cfg.i18n.fromCounty;
        } else {
            pill.textContent = cfg.i18n.fromCounty;
        }
    }

    function refreshServices() {
        var rows = root.querySelectorAll('.lts-service');
        for (var index = 0; index < rows.length; index++) {
            var on = rows[index].querySelector('[data-lts-offer]');
            var fields = rows[index].querySelector('.lts-offer-fields');
            if (fields && on) {
                fields.hidden = !on.checked;
            }
            refreshPill(rows[index]);
            refreshSnippet(rows[index]);
        }
        var badge = root.querySelector('[data-lts-offer-count]');
        if (!badge) {
            return;
        }
        var count = 0;
        var boxes = root.querySelectorAll('[data-lts-offer]');
        for (var boxIndex = 0; boxIndex < boxes.length; boxIndex++) {
            if (boxes[boxIndex].checked) {
                count++;
            }
        }
        badge.textContent = String(count);
        badge.hidden = count < 1;
    }

    function refreshInherit() {
        var list = document.getElementById('lts-inherit-list');
        var title = document.getElementById('lts-inherit-title');
        if (!list) {
            return;
        }
        var fylke = selectedFylke();
        if (title) {
            title.textContent = fylke ? format(cfg.i18n.inheritFrom, fylke.title) : cfg.i18n.inheritUnknown;
        }
        while (list.firstChild) {
            list.removeChild(list.firstChild);
        }
        var items = [];
        if (hoursMode() === 'inherit') {
            items.push(cfg.i18n.inheritHours);
        }
        var rows = root.querySelectorAll('.lts-service');
        for (var index = 0; index < rows.length; index++) {
            var on = rows[index].querySelector('[data-lts-offer]');
            var price = rows[index].querySelector('[data-lts-price]');
            if (on && on.checked && price && price.value.trim() === '') {
                items.push(format(cfg.i18n.inheritPrice, rows[index].getAttribute('data-title') || ''));
            }
        }
        if (!items.length) {
            items.push(cfg.i18n.noneInherit);
        }
        for (var itemIndex = 0; itemIndex < items.length; itemIndex++) {
            var item = document.createElement('li');
            item.textContent = items[itemIndex];
            if (items.length === 1 && items[0] === cfg.i18n.noneInherit) {
                item.className = 'is-muted';
            }
            list.appendChild(item);
        }
    }

    function refreshThumb() {
        if (!thumbId || !thumb) {
            return;
        }
        var has = thumbId.value !== '' && thumbId.value !== '0';
        thumb.hidden = !has;
        if (clearThumb) {
            clearThumb.hidden = !has;
        }
    }

    function refresh() {
        var days = root.querySelectorAll('.lts-day');
        for (var index = 0; index < days.length; index++) {
            syncDay(days[index]);
        }
        refreshServices();
        refreshCounts();
        refreshInherit();
        refreshThumb();
    }

    function showToast(message, isError) {
        var toast = root.querySelector('.lts-toast');
        if (!toast) {
            return;
        }
        toast.textContent = message;
        toast.classList.toggle('is-error', !!isError);
        toast.hidden = false;
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.hidden = true;
        }, 2600);
    }

    function setBusy(busy) {
        var buttons = root.querySelectorAll('[data-lts-save]');
        for (var index = 0; index < buttons.length; index++) {
            buttons[index].disabled = busy;
        }
    }

    function setPreview(url) {
        var link = document.getElementById('lts-preview');
        if (!link) {
            return;
        }
        if (url) {
            link.href = url;
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
        } else {
            link.removeAttribute('href');
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
        }
    }

    function updateLayouts(layouts) {
        if (!layouts) {
            return;
        }
        var slugs = Object.keys(layouts);
        for (var index = 0; index < slugs.length; index++) {
            var row = root.querySelector('.lts-service[data-slug="' + slugs[index] + '"]');
            if (!row) {
                continue;
            }
            var link = row.querySelector('.lts-layout');
            var holder = row.querySelector('.lts-layout-row');
            if (!link || !holder || !layouts[slugs[index]]) {
                continue;
            }
            link.href = layouts[slugs[index]];
            holder.hidden = false;
        }
    }

    function save() {
        var form = document.getElementById('post');
        if (!form || saving || !window.ajaxurl) {
            return;
        }
        syncTitle();
        refresh();
        var data = new FormData(form);
        data.set('action', 'lts_save_editor');
        if (titleInput) {
            data.set('lts_title', titleInput.value);
        }
        saving = true;
        setBusy(true);
        window.fetch(window.ajaxurl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json || !json.success) {
                var error = json && json.data && json.data.message ? json.data.message : cfg.i18n.couldNotSave;
                showToast(error, true);
                return;
            }
            if (json.data.warning) {
                showToast(json.data.warning, true);
            } else {
                showToast(json.data.message || cfg.i18n.saved, false);
            }
            var status = document.getElementById('lts-status');
            var visibility = document.getElementById('lts-visibility');
            if (status && json.data.status) {
                status.value = json.data.status === 'draft' ? 'draft' : 'publish';
            }
            if (visibility && json.data.status === 'private') {
                visibility.value = 'private';
            } else if (visibility && json.data.status === 'publish') {
                visibility.value = 'public';
            }
            var date = document.getElementById('lts-date');
            if (date && json.data.date) {
                date.textContent = json.data.date;
            }
            if (json.data.slug) {
                cfg.placeSlug = json.data.slug;
                var slugInput = document.getElementById('lts-slug');
                var slugNote = document.getElementById('lts-slug-note');
                if (slugInput) {
                    slugInput.value = json.data.slug;
                }
                if (slugNote) {
                    slugNote.textContent = json.data.slug;
                    if (slugNote.parentNode) {
                        slugNote.parentNode.hidden = false;
                    }
                }
            }
            setPreview(json.data.preview || '');
            updateLayouts(json.data.layouts);
            rememberServices();
            refresh();
            remember();
            mark();
        }).catch(function () {
            showToast(cfg.i18n.couldNotSave, true);
        }).then(function () {
            saving = false;
            setBusy(false);
        });
    }

    function restore() {
        if (!memory) {
            return;
        }
        if (servicesBox) {
            servicesBox.innerHTML = memory.services;
        }
        if (faqBox) {
            faqBox.innerHTML = memory.faq;
        }
        if (blocksBox) {
            blocksBox.innerHTML = memory.blocks;
        }
        if (chipsBox) {
            chipsBox.innerHTML = memory.chips;
        }
        apply(memory.fields);
        if (thumbImg) {
            thumbImg.setAttribute('src', memory.thumb || '');
        }
        syncTitle();
        lockFromMode();
        customHours = hoursMode() === 'custom' ? readHours() : null;
        refreshNearbyOptions();
        refresh();
        mark();
    }

    function addChip(id, title) {
        if (!chipsBox) {
            return;
        }
        var chip = document.createElement('span');
        chip.className = 'lts-chip';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'lts_nearby[]';
        input.value = String(id);
        var name = document.createElement('span');
        name.textContent = title;
        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('data-lts-remove-chip', '');
        button.setAttribute('aria-label', cfg.i18n.remove);
        button.textContent = '×';
        chip.appendChild(input);
        chip.appendChild(name);
        chip.appendChild(button);
        chipsBox.appendChild(chip);
    }

    function refreshNearbyOptions() {
        var select = document.getElementById('lts-add-nearby');
        if (!select || !cfg.kommuner) {
            return;
        }
        var selected = {};
        var inputs = root.querySelectorAll('input[name="lts_nearby[]"]');
        for (var index = 0; index < inputs.length; index++) {
            selected[inputs[index].value] = true;
        }
        while (select.firstChild) {
            select.removeChild(select.firstChild);
        }
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = cfg.i18n.addKommune;
        select.appendChild(placeholder);
        for (var kommuneIndex = 0; kommuneIndex < cfg.kommuner.length; kommuneIndex++) {
            var kommune = cfg.kommuner[kommuneIndex];
            if (selected[String(kommune.id)]) {
                continue;
            }
            var option = document.createElement('option');
            option.value = String(kommune.id);
            option.textContent = kommune.title;
            select.appendChild(option);
        }
    }

    function peopleChoices() {
        var node = document.getElementById('lts-people-choices');
        if (!node) {
            return [];
        }
        try {
            var parsed = JSON.parse(node.textContent || '[]');
            return parsed && parsed.length ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function syncPeoplePanels() {
        var mode = document.getElementById('lts-people-mode');
        var fields = document.getElementById('lts-people-fields');
        var entry = document.getElementById('lts-people-entry');
        if (!mode || !fields || !entry) {
            return;
        }
        fields.hidden = mode.value === 'uten';
        entry.hidden = mode.value !== 'basert';
    }

    function fillPeopleEntry() {
        var type = document.getElementById('lts-people-field');
        var value = document.getElementById('lts-people-value');
        if (!type || !value) {
            return;
        }
        var options = [];
        var choices = peopleChoices();
        for (var index = 0; index < choices.length; index++) {
            if (choices[index].key === type.value) {
                options = choices[index].options || [];
                break;
            }
        }
        var current = value.value;
        var placeholder = value.options.length ? value.options[0].textContent : '';
        while (value.firstChild) {
            value.removeChild(value.firstChild);
        }
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = placeholder;
        value.appendChild(empty);
        var found = false;
        for (var optionIndex = 0; optionIndex < options.length; optionIndex++) {
            var option = document.createElement('option');
            option.value = options[optionIndex].slug;
            option.textContent = options[optionIndex].label;
            if (options[optionIndex].slug === current) {
                option.selected = true;
                found = true;
            }
            value.appendChild(option);
        }
        if (!found) {
            value.value = '';
        }
    }

    function changed(event) {
        if (event.target && event.target.id === 'lts_fylke_id') {
            onFylkeChange();
            return;
        }
        if (event.target && event.target.id === 'lts-people-mode') {
            syncPeoplePanels();
        }
        if (event.target && event.target.id === 'lts-people-field') {
            fillPeopleEntry();
        }
        if (event.target && event.target.getAttribute && event.target.getAttribute('data-lts-block') !== null) {
            var blockRow = event.target.closest('.lts-block-row');
            if (blockRow) {
                syncBlockLink(blockRow);
            }
        }
        syncTitle();
        refresh();
        mark();
    }

    root.addEventListener('input', changed);
    root.addEventListener('change', changed);
    root.addEventListener('click', function (event) {
        var tab = event.target.closest('[role="tab"]');
        if (tab && root.contains(tab)) {
            var id = tab.getAttribute('aria-controls');
            var tabs = root.querySelectorAll('[role="tab"]');
            for (var index = 0; index < tabs.length; index++) {
                tabs[index].setAttribute('aria-selected', tabs[index] === tab ? 'true' : 'false');
            }
            var panels = root.querySelectorAll('[role="tabpanel"]');
            for (var panelIndex = 0; panelIndex < panels.length; panelIndex++) {
                panels[panelIndex].hidden = panels[panelIndex].id !== id;
            }
            return;
        }

        var modeButton = event.target.closest('[data-lts-hours-mode] button');
        if (modeButton) {
            setMode(modeButton.getAttribute('data-value'));
            return;
        }

        var chipButton = event.target.closest('[data-lts-remove-chip]');
        if (chipButton) {
            var chip = chipButton.closest('.lts-chip');
            if (chip) {
                chip.remove();
            }
            refreshNearbyOptions();
            mark();
            return;
        }

        if (event.target.closest('[data-lts-reset]')) {
            restore();
            return;
        }

        if (event.target.closest('[data-lts-save]')) {
            save();
            return;
        }

        if (event.target.closest('[data-lts-add-faq]') || event.target.closest('[data-lts-remove-faq]') || event.target.closest('[data-lts-faq-up]') || event.target.closest('[data-lts-faq-down]')) {
            window.setTimeout(mark, 0);
            return;
        }

        var addBlock = event.target.closest('[data-lts-add-block]');
        if (addBlock) {
            var card = addBlock.closest('.lts-block-service');
            var list = card ? card.querySelector('.lts-block-list') : null;
            var template = document.getElementById('lts-block-template');
            var slug = card ? card.getAttribute('data-slug') || '' : '';
            if (list && template && template.content && slug) {
                var fragment = template.content.cloneNode(true);
                var added = fragment.querySelector('.lts-block-row');
                if (added) {
                    nameBlockRow(added, slug);
                }
                list.appendChild(fragment);
            }
            window.setTimeout(mark, 0);
            return;
        }

        var removeBlock = event.target.closest('[data-lts-remove-block]');
        if (removeBlock) {
            var removed = removeBlock.closest('.lts-block-row');
            if (removed) {
                removed.remove();
            }
            window.setTimeout(mark, 0);
        }
    });

    var nearby = document.getElementById('lts-add-nearby');
    if (nearby) {
        nearby.addEventListener('change', function () {
            var id = nearby.value;
            if (!id) {
                return;
            }
            var option = nearby.options[nearby.selectedIndex];
            addChip(id, option ? option.text : id);
            nearby.value = '';
            refreshNearbyOptions();
            mark();
        });
    }

    var preview = document.getElementById('lts-preview');
    if (preview) {
        preview.addEventListener('click', function (event) {
            if (preview.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
            }
        });
    }

    var trash = root.querySelector('.lts-trash');
    if (trash) {
        trash.addEventListener('click', function (event) {
            if (!window.confirm(cfg.i18n.trashConfirm)) {
                event.preventDefault();
            }
        });
    }

    var pick = document.getElementById('lts-thumb-pick');
    if (pick && window.wp && window.wp.media) {
        pick.addEventListener('click', function () {
            if (!frame) {
                frame = window.wp.media({
                    title: cfg.i18n.chooseImage,
                    multiple: false,
                    library: { type: 'image' }
                });
                frame.on('select', function () {
                    var file = frame.state().get('selection').first().toJSON();
                    if (thumbId) {
                        thumbId.value = String(file.id);
                    }
                    if (thumbImg) {
                        var sized = file.sizes && file.sizes.medium && file.sizes.medium.url;
                        thumbImg.src = sized || file.url;
                    }
                    refreshThumb();
                    mark();
                });
            }
            frame.open();
        });
    }
    if (clearThumb) {
        clearThumb.addEventListener('click', function () {
            if (thumbId) {
                thumbId.value = '';
            }
            if (thumbImg) {
                thumbImg.src = '';
            }
            refreshThumb();
            mark();
        });
    }

    var form = document.getElementById('post');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            save();
        });
    }

    window.addEventListener('beforeunload', function (event) {
        if (!memory || serialize() === memory.serial) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    if (blocksBox) {
        var blockRows = blocksBox.querySelectorAll('.lts-block-row');
        for (var blockIndex = 0; blockIndex < blockRows.length; blockIndex++) {
            syncBlockLink(blockRows[blockIndex]);
        }
    }

    lockFromMode();
    customHours = hoursMode() === 'custom' ? readHours() : null;
    refreshNearbyOptions();
    refresh();
    remember();
    mark();
}());
