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
</body>
</html>
