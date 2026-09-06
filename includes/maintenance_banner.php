<?php
/**
 * Shared maintenance-mode notice banner. Include this partial in any page that
 * should visibly communicate an active maintenance state.
 *
 * The partial renders nothing when maintenance is OFF, and nothing on pages
 * that are already hard-blocked by the maintenance gate (they show the 503
 * page instead). For warnings/non-blocking states it renders an amber banner
 * with a mode-appropriate title and message (escaped on output).
 *
 * The banner is not dismissible: no site-wide banner-dismissal mechanism
 * exists in this codebase, so a static notice is used (once maintenance is
 * turned off the banner disappears for everyone).
 */
$MMX_BANNER = function_exists('maintenance_banner_status') ? maintenance_banner_status() : null;
if ($MMX_BANNER === null) {
    return;
}

$bannerTitle = $MMX_BANNER['title'];
$bannerMsg   = $MMX_BANNER['message'];
$bannerLabel = $MMX_BANNER['actionLabel'] ?? null;
$bannerUrl   = $MMX_BANNER['actionUrl'] ?? null;
?>
<div class="tms-maintenance-banner" role="status" aria-live="polite">
    <span class="tms-maintenance-banner-icon" aria-hidden="true">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
    </span>
    <span class="tms-maintenance-banner-copy">
        <strong><?= e($bannerTitle) ?></strong>
        <span><?= e($bannerMsg) ?></span>
    </span>
    <?php if ($bannerLabel !== null && $bannerUrl !== null): ?>
    <a class="tms-maintenance-banner-action" href="<?= e($bannerUrl) ?>">
        <?= e($bannerLabel) ?>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
    </a>
    <?php endif; ?>
</div>
<style>
.tms-maintenance-banner{
    position: relative; z-index: 60;
    display: flex; align-items: center; gap: .8rem;
    padding: .6rem 1.25rem;
    background: linear-gradient(180deg, #FFFAEC, #FFF4DC);
    border-bottom: 1px solid rgba(255,181,71,.5);
}
.tms-maintenance-banner-icon{
    flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, #FFB547, #F59E0B); color: #fff;
    box-shadow: 0 4px 12px rgba(255,181,71,.45);
}
.tms-maintenance-banner-copy{
    flex: 1 1 auto; min-width: 0;
    display: flex; flex-direction: column; line-height: 1.3;
}
.tms-maintenance-banner-copy strong{ font-size: .82rem; font-weight: 700; color: #6b4300; letter-spacing: .02em; }
.tms-maintenance-banner-copy span{ font-size: .75rem; color: #8a5c0b; }
.tms-maintenance-banner-action{
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem .95rem; border-radius: 999px;
    background: linear-gradient(135deg, #7B2CBF, #9D4EDD); color: #fff;
    font-size: .78rem; font-weight: 600; text-decoration: none; white-space: nowrap;
    box-shadow: 0 4px 14px rgba(123,44,191,.25);
    transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
}
.tms-maintenance-banner-action:hover{ transform: translateY(-1px); box-shadow: 0 6px 18px rgba(123,44,191,.35); opacity: .95; }
.tms-maintenance-banner-action svg{ transition: transform .15s ease; }
.tms-maintenance-banner-action:hover svg{ transform: translateX(2px); }
@media (max-width: 560px){
    .tms-maintenance-banner{ flex-wrap: wrap; row-gap: .15rem; }
    .tms-maintenance-banner-action{ margin-left: 42px; }
}
</style>
<?php unset($bannerTitle, $bannerMsg, $bannerLabel, $bannerUrl, $MMX_BANNER); ?>