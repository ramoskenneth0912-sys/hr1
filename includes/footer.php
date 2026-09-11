        </main>
    </div>
</div>
<?php if (!empty($reactEntry)): ?>
<?php require_once __DIR__ . '/react.php'; ?>
<?= react_asset_tags((string) $reactEntry) ?>
<?php endif; ?>
<script>
(function () {
    var toggle = document.getElementById('notifToggle');
    var dropdown = document.getElementById('notifDropdown');
    if (!toggle || !dropdown) return;
    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = dropdown.hidden;
        dropdown.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', function (e) {
        if (!dropdown.hidden && !dropdown.contains(e.target) && e.target !== toggle) {
            dropdown.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
})();
(function () {
    var layout = document.querySelector('.app-layout');
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!layout || !toggle || !sidebar || !overlay) return;

    function setOpen(open) {
        layout.classList.toggle('nav-open', open);
        document.body.classList.toggle('nav-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
    }

    toggle.addEventListener('click', function () {
        setOpen(!layout.classList.contains('nav-open'));
    });
    overlay.addEventListener('click', function () { setOpen(false); });
    sidebar.addEventListener('click', function (e) {
        if (e.target.closest('.nav-link')) setOpen(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && layout.classList.contains('nav-open')) setOpen(false);
    });
    var desktopQuery = window.matchMedia('(min-width: 1201px)');
    var onDesktop = function (mq) { if (mq.matches) setOpen(false); };
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', onDesktop);
    else desktopQuery.addListener(onDesktop);
})();
(function () {
    var layout = document.querySelector('.app-layout');
    var collapseBtn = document.getElementById('sidebarCollapse');
    if (!layout || !collapseBtn) return;

    var KEY = 'hr1_sidebar_collapsed';
    var desktopQuery = window.matchMedia('(min-width: 1201px)');

    function isDesktop() { return desktopQuery.matches; }

    function apply(collapsed) {
        layout.classList.toggle('sidebar-collapsed', collapsed);
        collapseBtn.setAttribute('aria-expanded', String(!collapsed));
        collapseBtn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapseBtn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
    }

    var saved = '0';
    try { saved = localStorage.getItem(KEY) || '0'; } catch (err) { /* storage unavailable */ }
    apply(saved === '1');

    collapseBtn.addEventListener('click', function () {
        apply(!layout.classList.contains('sidebar-collapsed'));
        try { localStorage.setItem(KEY, layout.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (err) { /* storage unavailable */ }
        hideTip();
    });

    // Floating tooltip for hovered icons while the sidebar is collapsed.
    var tip = document.createElement('div');
    tip.className = 'sidebar-tooltip';
    tip.setAttribute('role', 'tooltip');
    document.body.appendChild(tip);

    var tipSource = null;

    function showTip(el) {
        tip.textContent = el.getAttribute('data-tooltip') || '';
        var r = el.getBoundingClientRect();
        tip.style.top = Math.round(r.top + r.height / 2) + 'px';
        tip.style.left = Math.round(r.left + r.width + 10) + 'px';
        tip.classList.add('visible');
        tipSource = el;
    }

    function hideTip() {
        if (tipSource) {
            tip.classList.remove('visible');
            tipSource = null;
        }
    }

    document.addEventListener('mouseover', function (e) {
        var source = e.target.closest('[data-tooltip]');
        if (source && layout.classList.contains('sidebar-collapsed') && isDesktop()) {
            if (source !== tipSource) showTip(source);
        } else {
            hideTip();
        }
    });

    document.addEventListener('mouseout', function (e) {
        if (tipSource && e.target === tipSource && !e.relatedTarget) hideTip();
    });

    document.addEventListener('scroll', hideTip, true);

    var onViewport = function () { hideTip(); };
    if (desktopQuery.addEventListener) desktopQuery.addEventListener('change', onViewport);
    else desktopQuery.addListener(onViewport);
})();
</script>

<!-- Logout confirmation dialog (keeps logout as POST + CSRF via requestSubmit) -->
<div id="logoutConfirm" class="logout-modal" hidden role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle" aria-describedby="logoutConfirmText">
    <div class="logout-modal-backdrop" data-logout-close></div>
    <div class="logout-modal-box">
        <h3 id="logoutConfirmTitle">Log out?</h3>
        <p id="logoutConfirmText">Are you sure you want to log out?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn btn-outline" data-logout-close>Cancel</button>
            <button type="button" class="btn btn-danger-solid" data-logout-confirm>Log Out</button>
        </div>
    </div>
</div>
<style>
.logout-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.logout-modal[hidden] { display: none; }
.logout-modal-backdrop { position: absolute; inset: 0; background: rgba(16,10,40,.55); }
.logout-modal-box { position: relative; z-index: 1; width: min(400px, 100%); background: #fff; border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: 0 24px 60px -24px rgba(27,37,89,.45); padding: 1.5rem 1.5rem 1.25rem; text-align: center; }
.logout-modal-box h3 { margin: 0 0 .5rem; font-size: 1.1rem; font-weight: 700; color: var(--text-dark); }
.logout-modal-box p { margin: 0 0 1.25rem; font-size: .9rem; color: var(--muted); line-height: 1.5; }
.logout-modal-actions { display: flex; gap: .75rem; }
.logout-modal-actions .btn { flex: 1; justify-content: center; }
.btn-danger-solid { background: var(--danger); color: #fff; border: 1px solid var(--danger); }
.btn-danger-solid:hover { background: #d94c40; border-color: #d94c40; }
</style>
<script>
(function () {
    var modal = document.getElementById('logoutConfirm');
    if (!modal) return;

    var pendingForm = null;

    function open() { modal.hidden = false; document.addEventListener('keydown', onKey, true); }
    function close() { modal.hidden = true; pendingForm = null; document.removeEventListener('keydown', onKey, true); }
    function confirmLogout() {
        var targetForm = pendingForm;
        close();
        if (targetForm) targetForm.requestSubmit();
    }
    function onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); return; }
        if (e.key === 'Enter') {
            // Enter confirms the logout. preventDefault/stopPropagation so the
            // key-triggered click on a focused submit button can't re-fire.
            e.preventDefault();
            e.stopPropagation();
            confirmLogout();
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-logout]');
        if (!btn) return;
        pendingForm = btn.form;
        if (!pendingForm || pendingForm.method.toUpperCase() !== 'POST') return;
        e.preventDefault();
        open();
    });

    modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-logout-close]')) { close(); return; }
        if (e.target.closest('[data-logout-confirm]')) { confirmLogout(); }
    });
})();
</script>

<!-- Remove All notifications confirmation dialog (keeps action as POST + CSRF via requestSubmit) -->
<div id="removeAllConfirm" class="logout-modal" hidden role="dialog" aria-modal="true" aria-labelledby="removeAllConfirmTitle" aria-describedby="removeAllConfirmText">
    <div class="logout-modal-backdrop" data-removeall-close></div>
    <div class="logout-modal-box">
        <h3 id="removeAllConfirmTitle">Remove all notifications?</h3>
        <p id="removeAllConfirmText">Are you sure you want to remove all notifications?</p>
        <div class="logout-modal-actions">
            <button type="button" class="btn btn-outline" data-removeall-close>Cancel</button>
            <button type="button" class="btn btn-danger-solid" data-removeall-confirm>Remove All</button>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById('removeAllConfirm');
    if (!modal) return;

    var pendingForm = null;

    function open() { modal.hidden = false; document.addEventListener('keydown', onKey, true); }
    function close() { modal.hidden = true; pendingForm = null; document.removeEventListener('keydown', onKey, true); }
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-confirm-removeall]');
        if (!btn) return;
        pendingForm = btn.form;
        if (!pendingForm || pendingForm.method.toUpperCase() !== 'POST') return;
        e.preventDefault();
        open();
    });

    modal.addEventListener('click', function (e) {
        if (e.target.closest('[data-removeall-close]')) { close(); return; }
        if (e.target.closest('[data-removeall-confirm]')) {
            var targetForm = pendingForm;
            close();
            if (targetForm) targetForm.requestSubmit();
        }
    });
})();
</script>

<!--
Automatic real-time notification updates. Lightweight AJAX polling against
the read-only notification_poll.php endpoint (session-scoped, owner-only).
- Only updates when the content actually changes (fingerprint compare) so
  the bell does not flicker and no duplicate items are appended.
- Never reloads the page and never opens the dropdown by itself.
- If the dropdown is already open it refreshes in place; if closed it only
  updates the unread badge / head pill.
-->
<script>
(function () {
    var toggle = document.getElementById('notifToggle');
    var dropdown = document.getElementById('notifDropdown');
    if (!toggle || !dropdown || typeof fetch !== 'function') return;

    var BASE = <?= json_encode(BASE_URL) ?>;
    var INTERVAL = 20000; // 20 seconds
    var lastKey = null;
    var inFlight = false;

    function badgeEl() {
        var b = toggle.querySelector('.notif-badge');
        if (!b) {
            b = document.createElement('span');
            b.className = 'notif-badge';
            toggle.appendChild(b);
        }
        return b;
    }

    // Baseline unread count as rendered by the server on page load (-1 = none).
    var baseline = badgeEl();
    var seenCount = (baseline && /^\d+$/.test(baseline.textContent)) ? parseInt(baseline.textContent, 10) : -1;

    function setBadge(count) {
        var badge = badgeEl();
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.style.display = '';
        } else {
            badge.style.display = 'none';
        }
    }

    function poll() {
        if (inFlight) return;
        inFlight = true;
        fetch(BASE + '/modules/employee/notification_poll.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store'
        }).then(function (r) {
            if (!r.ok) throw new Error('poll status ' + r.status);
            return r.json();
        }).then(function (data) {
            var count = parseInt(data.count || 0, 10);
            var key = count + '|' + (data.latest_id || 0) + '|' + (data.latest_ts || '');
            if (key === lastKey) return; // nothing new since the last refresh
            var isFirst = lastKey === null;
            lastKey = key;
            // On the very first poll, only touch the DOM if the unread count
            // actually moved while the page was sitting open (avoids a
            // redundant refresh when nothing changed after page load).
            if (isFirst && count === seenCount) return;
            seenCount = count;
            setBadge(count);
            // Replace the dropdown body in place. The dropdown element keeps
            // its own hidden state, so a closed bell stays closed.
            if (typeof data.html === 'string') {
                dropdown.innerHTML = data.html;
            }
        }).catch(function () {
            /* transient network/session error: try again next interval */
        }).then(function () {
            inFlight = false;
        });
    }

    poll();
    window.setInterval(poll, INTERVAL);
})();
</script>
</body>
</html>
