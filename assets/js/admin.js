(function () {
    document.addEventListener('change', function (event) {
        var box = event.target;
        if (!box || !box.matches || !box.matches('[data-lts-offer]')) {
            return;
        }
        var panel = box.closest('.lts-offer');
        if (!panel) {
            return;
        }
        var fields = panel.querySelector('.lts-offer-fields');
        if (fields) {
            fields.hidden = !box.checked;
        }
    });

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var list = document.getElementById('lts-faq');
        if (!list) {
            return;
        }

        var add = target.closest('[data-lts-add-faq]');
        if (add) {
            var template = document.getElementById('lts-faq-template');
            if (template && template.content) {
                list.appendChild(template.content.cloneNode(true));
                var added = list.lastElementChild;
                if (added) {
                    setFaqOpen(added, true);
                    syncFaqRow(added);
                    var question = added.querySelector('[name="lts_faq_question[]"]');
                    if (question) {
                        question.focus();
                    }
                }
                renumberFaq(list);
            }
            return;
        }

        if (target.closest('[data-lts-faq-open]')) {
            eachFaqRow(list, function (row) {
                setFaqOpen(row, true);
            });
            return;
        }

        if (target.closest('[data-lts-faq-close]')) {
            eachFaqRow(list, function (row) {
                setFaqOpen(row, false);
            });
            return;
        }

        var up = target.closest('[data-lts-faq-up]');
        if (up) {
            var upRow = up.closest('.lts-faq-row');
            if (upRow && upRow.previousElementSibling) {
                list.insertBefore(upRow, upRow.previousElementSibling);
                renumberFaq(list);
            }
            return;
        }

        var down = target.closest('[data-lts-faq-down]');
        if (down) {
            var downRow = down.closest('.lts-faq-row');
            if (downRow && downRow.nextElementSibling) {
                list.insertBefore(downRow.nextElementSibling, downRow);
                renumberFaq(list);
            }
            return;
        }

        var remove = target.closest('[data-lts-remove-faq]');
        if (remove) {
            var row = remove.closest('.lts-faq-row');
            if (row) {
                row.remove();
                renumberFaq(list);
            }
            return;
        }

        var toggle = target.closest('[data-lts-faq-toggle]');
        if (!toggle) {
            return;
        }

        var card = toggle.closest('.lts-faq-row');
        if (card) {
            setFaqOpen(card, !card.classList.contains('is-open'));
        }
    });

    document.addEventListener('input', function (event) {
        var field = event.target;
        if (!field || !field.closest) {
            return;
        }
        var row = field.closest('.lts-faq-row');
        if (row) {
            syncFaqRow(row);
        }
    });

    function eachFaqRow(list, callback) {
        var rows = list.querySelectorAll('.lts-faq-row');
        for (var index = 0; index < rows.length; index++) {
            callback(rows[index], index, rows.length);
        }
    }

    function renumberFaq(list) {
        eachFaqRow(list, function (row, index, total) {
            var num = row.querySelector('[data-lts-faq-num]');
            if (num) {
                num.textContent = String(index + 1);
            }
            var up = row.querySelector('[data-lts-faq-up]');
            var down = row.querySelector('[data-lts-faq-down]');
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === total - 1;
            }
        });
    }

    function setFaqOpen(row, open) {
        row.classList.toggle('is-open', open);
        var body = row.querySelector('.lts-faq-body');
        var toggle = row.querySelector('[data-lts-faq-toggle]');
        if (body) {
            body.hidden = !open;
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function syncFaqRow(row) {
        var list = row.closest('#lts-faq');
        var question = row.querySelector('[name="lts_faq_question[]"]');
        var answer = row.querySelector('[name="lts_faq_answer[]"]');
        var title = row.querySelector('[data-lts-faq-title]');
        var preview = row.querySelector('[data-lts-faq-preview]');
        var count = row.querySelector('[data-lts-faq-count]');
        if (title && question) {
            var empty = list ? list.getAttribute('data-empty') || '' : '';
            title.textContent = question.value.trim() !== '' ? question.value : empty;
        }
        if (preview && answer) {
            preview.textContent = answer.value.replace(/\s+/g, ' ').trim();
        }
        if (count && answer && list) {
            var pattern = list.getAttribute('data-count') || '%s';
            count.textContent = pattern.replace('%s', String(answer.value.length));
        }
    }
}());
