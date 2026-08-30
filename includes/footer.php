        </main>
    </div>
</div>
<?php if (!empty($reactEntry)): ?>
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
    function onKey(e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } }

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
        if (e.target.closest('[data-logout-confirm]')) {
            var targetForm = pendingForm;
            close();
            if (targetForm) targetForm.requestSubmit();
        }
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
</body>
</html>
