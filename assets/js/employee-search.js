/* Reusable Employee Search — HR1 standard employee-selection control.
   Any <select data-emp-search> is replaced visually by a single
   "[ search icon ] Search Employee" trigger. The native select is kept in the
   DOM (hidden) so it remains the module's source of truth: its value+name still
   submit with the surrounding form, and existing JS that reads the select still
   works. The trigger opens a shared search dialog (Employee ID / first, last, or
   full name); picking a result sets the select, dispatches "change", updates the
   trigger label, and (opt-in via data-emp-autosubmit) submits a filter form.

   Data comes only from the options the server rendered for that select, so the
   search can never reveal employees outside the module's authorized scope. */
(function () {
    'use strict';

    function normalize(s) {
        return (s || '').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    var ICON = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><circle cx="7" cy="7" r="5"/><path d="M11 11l3.2 3.2"/></svg>';

    var modal = null;
    var activeSelect = null;
    var activeTrigger = null;
    var activeTokens = [];

    function optionMeta(option) {
        var own = option.getAttribute('data-emp-no');
        var text = (option.textContent || '').trim();
        var id = (own != null && own !== '') ? own
            : (text.split('—')[0] || '').trim();
        var name = id && text.indexOf(id) === 0 ? text.slice(id.length).replace(/^\s*(—|:|-)?\s*/, '').trim() : text;
        return { name: name || text, id: id, value: option.value };
    }

    function isSelectable(option) {
        var v = option.value;
        return v !== '' && v !== '0';
    }

    function currentLabel(select) {
        var o = select.options[select.selectedIndex];
        if (!o || !isSelectable(o)) return null;
        var m = optionMeta(o);
        return m.id ? (m.id + ' — ' + m.name) : m.name;
    }

    function makeModal() {
        if (modal) return;
        modal = document.createElement('div');
        modal.className = 'modal-backdrop emp-search-modal';
        modal.hidden = true;
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'emp-search-title');
        modal.innerHTML =
            '<div class="modal" style="max-width:560px;width:95%;">' +
                '<div class="modal-header">' +
                    '<h3 id="emp-search-title" style="margin:0;">Search Employee</h3>' +
                    '<button type="button" class="btn btn-outline btn-sm emp-search-close">Close</button>' +
                '</div>' +
                '<div class="emp-search-body">' +
                    '<div class="emp-search-field">' +
                        '<input type="search" class="emp-search-q" placeholder="Search by Employee Name or ID…" aria-label="Search by Employee Name or ID" autocomplete="off" spellcheck="false">' +
                        '<span class="emp-search-icon" aria-hidden="true">' + ICON + '</span>' +
                    '</div>' +
                    '<p class="emp-search-hint">Type an employee name or ID to see matches.</p>' +
                    '<div class="emp-search-count" hidden></div>' +
                    '<div class="emp-search-results" role="listbox"></div>' +
                '</div>' +
            '</div>';

        modal.addEventListener('click', function (e) {
            if (e.target === modal) close();
        });
        modal.querySelector('.emp-search-close').addEventListener('click', close);
        modal.querySelector('.emp-search-q').addEventListener('input', renderResults);

        document.body.appendChild(modal);

        function close() {
            modal.hidden = true;
            activeSelect = null;
            activeTokens = [];
            modal.querySelector('.emp-search-q').value = '';
            if (activeTrigger) activeTrigger.focus();
        }
        modal._close = close;
    }

    function open(select, trigger) {
        makeModal();
        activeSelect = select;
        activeTrigger = trigger;
        activeTokens = Array.prototype.map.call(select.options, function (o) {
            return normalize(o.getAttribute('data-emp-no') + ' ' + o.textContent);
        });
        modal.hidden = false;
        modal.querySelector('.emp-search-count').hidden = true;
        var q = modal.querySelector('.emp-search-q');
        q.value = '';
        renderResults();
        q.focus();
    }

    function pick(value) {
        if (!activeSelect) return;
        var sel = activeSelect;
        var chosen = null;
        Array.prototype.forEach.call(sel.options, function (o) {
            if (o.value === value) chosen = o;
        });
        sel.value = value;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
        modal._close();

        if (chosen) {
            var m = optionMeta(chosen);
            var label = m.id ? (m.id + ' — ' + m.name) : m.name;
            if (activeTrigger) {
                activeTrigger.querySelector('.emp-search-label').textContent = label;
                activeTrigger.classList.add('has-selection');
            }
        }

        if (sel.hasAttribute('data-emp-autosubmit') && sel.form) {
            sel.form.submit();
        }
    }

    function renderResults() {
        var q = normalize(modal.querySelector('.emp-search-q').value);
        var list = modal.querySelector('.emp-search-results');
        var countEl = modal.querySelector('.emp-search-count');

        if (q === '') {
            list.innerHTML = '<div class="emp-search-empty">Start typing an employee name or ID.</div>';
            countEl.hidden = true;
            countEl.textContent = '';
            return;
        }

        var matches = [];
        Array.prototype.forEach.call(activeSelect.options, function (o, i) {
            if (!isSelectable(o)) return;
            if (activeTokens[i].indexOf(q) !== -1) matches.push(o);
        });

        if (matches.length === 0) {
            list.innerHTML = '<div class="emp-search-empty">No matching employees.</div>';
            countEl.hidden = true;
            countEl.textContent = '';
            return;
        }

        countEl.hidden = false;
        countEl.textContent = matches.length + (matches.length === 1 ? ' match' : ' matches');
        list.innerHTML = '';
        matches.forEach(function (o) {
            var m = optionMeta(o);
            var row = document.createElement('div');
            row.className = 'emp-search-row';
            row.setAttribute('role', 'option');

            var name = document.createElement('div');
            name.className = 'emp-search-row-name';
            name.textContent = m.name;

            var id = document.createElement('div');
            id.className = 'emp-search-row-id';
            id.textContent = m.id;

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-primary btn-sm emp-search-pick';
            btn.textContent = 'Select';
            btn.addEventListener('click', function () { pick(m.value); });

            row.appendChild(name);
            row.appendChild(id);
            row.appendChild(btn);
            list.appendChild(row);
        });
    }

    function build(select) {
        if (select.getAttribute('data-emp-search') === '1') return;
        select.setAttribute('data-emp-search', '1');

        var wrap = document.createElement('div');
        wrap.className = 'emp-search';

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'emp-search-trigger';
        trigger.setAttribute('aria-haspopup', 'dialog');
        trigger.setAttribute('aria-label', 'Search Employee');

        var icon = document.createElement('span');
        icon.className = 'emp-search-trigger-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = ICON;

        var label = document.createElement('span');
        label.className = 'emp-search-label';

        var selected = currentLabel(select);
        label.textContent = selected || 'Search Employee';
        if (selected) trigger.classList.add('has-selection');
        if (select.disabled) trigger.disabled = true;

        trigger.appendChild(icon);
        trigger.appendChild(label);

        select.classList.add('emp-search-native');
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(trigger);

        trigger.addEventListener('click', function () { open(select, trigger); });
    }

    function init() {
        var selects = document.querySelectorAll('select[data-emp-search]');
        Array.prototype.forEach.call(selects, build);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal && !modal.hidden) {
            modal._close();
        }
    });
})();