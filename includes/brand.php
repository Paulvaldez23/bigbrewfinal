<?php
if (!defined('BIGBREW_BASE')) {
    define('BIGBREW_BASE', '/bigbrewfinal');
    // USER FINAL DIRECTIVE (VERBATIM): "bigbrewlogo.jpg ang ilalagay mo"
    // — use THIS local JPG file for EVERYTHING (logo + favicon)
    //   Modern browsers (Chrome/Edge/Firefox 2019+) accept JPG as favicon natively.
    define('BIGBREW_LOGO_JPG',  BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');
    define('BIGBREW_LOGO_PNG',  BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');  // Fallback alias
    define('BIGBREW_FAVICON',   BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');  // Favicon = same JPG file!
}

if (!function_exists('bigbrew_logo_img')) {
    function bigbrew_logo_img($size = 48, $dark_frame = false, $echo = true) {
        $s = (int)$size;
        $tag = '<img src="' . htmlspecialchars(BIGBREW_LOGO_JPG) . '" width="' . $s . '" height="' . $s . '" alt="Big Brew" style="display:block; width:100%; height:100%; object-fit:cover; border-radius:14px;">';
        if ($dark_frame) {
            $tag = '<div style="display:inline-block; width:'.$s.'px; height:'.$s.'px; background:#111111; border-radius:16px; padding:0; overflow:hidden; box-shadow: 0 12px 30px rgba(0,0,0,0.35), 0 0 0 2px rgba(255,243,227,0.15);">' . $tag . '</div>';
        } else {
            $tag = '<div style="display:inline-block; border-radius:12px; overflow:hidden;">' . $tag . '</div>';
        }
        if ($echo) echo $tag;
        return $tag;
    }
}

if (!function_exists('bigbrew_brand_css')) {
    function bigbrew_brand_css($echo = true) {
        $css = <<<CSS
:root {
  --bb-coffee-dark: #a86324;
  --bb-coffee: #ca7d2a;
  --bb-coffee-light: #dd8f3e;
  --bb-coffee-lighter: #eaab65;
  --bb-cream: #fff3e3;
  --bb-dark: #1a1a1a;
  --bb-dark-2: #2b241c;
}
.btn-primary, .btn-primary:hover, .btn-primary:focus, .btn-primary:active {
  background-color: var(--bb-coffee) !important;
  border-color: var(--bb-coffee-dark) !important;
}
.btn-primary:hover { background-color: var(--bb-coffee-dark) !important; }
.btn-outline-primary {
  color: var(--bb-coffee-dark) !important;
  border-color: var(--bb-coffee) !important;
}
.btn-outline-primary:hover {
  background-color: var(--bb-coffee) !important;
  color: #fff !important;
  border-color: var(--bb-coffee-dark) !important;
}
.text-primary, a { color: var(--bb-coffee-dark) !important; }
a:hover { color: var(--bb-coffee) !important; }
.bg-gradient-primary, body.bg-gradient-primary {
  background: linear-gradient(135deg, var(--bb-coffee-dark) 0%, var(--bb-coffee) 50%, var(--bb-coffee-light) 100%) !important;
}

/* ---------- SIDEBAR TEXT FORCE-VISIBLE (WHITE ON COFFEE) ---------- */
.sidebar.sidebar-dark .nav-item .nav-link,
.sidebar.sidebar-dark .nav-item .nav-link i,
.sidebar.sidebar-dark .sidebar-heading,
.sidebar.sidebar-dark .sidebar-brand .sidebar-brand-text {
  color: #fff8ec !important;
  text-shadow: 0 1px 2px rgba(0,0,0,0.35) !important;
  opacity: 1 !important;
}
.sidebar.sidebar-dark .nav-item .nav-link {
  font-weight: 600 !important;
  letter-spacing: 0.01em !important;
}
.sidebar.sidebar-dark .nav-item .nav-link i {
  opacity: 1 !important;
  font-size: 1.02rem !important;
}
.sidebar.sidebar-dark .nav-item .nav-link span {
  color: #fff8ec !important;
}
.sidebar.sidebar-dark .sidebar-heading {
  color: #ffe7c7 !important;
  font-weight: 800 !important;
  letter-spacing: 0.16em !important;
  font-size: 0.72rem !important;
  opacity: 0.95 !important;
  text-transform: uppercase !important;
  text-shadow: 0 1px 2px rgba(0,0,0,0.4) !important;
  padding-top: 0.9rem !important;
}
.sidebar.sidebar-dark .nav-item.active .nav-link,
.sidebar.sidebar-dark .nav-item .nav-link:active {
  color: #ffffff !important;
  background-color: rgba(26, 26, 26, 0.35) !important;
  font-weight: 700 !important;
}
.sidebar.sidebar-dark .nav-item.active .nav-link i,
.sidebar.sidebar-dark .nav-item .nav-link:active i {
  color: #ffffff !important;
}
.sidebar.sidebar-dark .nav-item .nav-link:hover,
.sidebar.toggled .nav-item:hover .nav-link span {
  color: #ffffff !important;
  background-color: rgba(26, 26, 26, 0.22) !important;
}
.sidebar.sidebar-dark .nav-item .nav-link:hover i {
  color: #ffffff !important;
}
.sidebar.sidebar-dark hr.sidebar-divider {
  border-top: 1px solid rgba(255, 247, 231, 0.22) !important;
}
.sidebar.sidebar-dark #sidebarToggle {
  background-color: rgba(26, 26, 26, 0.35) !important;
  color: #fff8ec !important;
}
.sidebar-brand {
  background-color: rgba(26, 26, 26, 0.18) !important;
}
.sidebar-brand-icon { filter: drop-shadow(0 2px 6px rgba(0,0,0,0.4)); }

.form-control:focus {
  border-color: var(--bb-coffee-lighter) !important;
  box-shadow: 0 0 0 0.2rem rgba(202, 125, 42, 0.25) !important;
}
.badge-primary { background-color: var(--bb-coffee) !important; }
.badge-success { background-color: #3f8a5a !important; }
.badge-warning { background-color: #c9a227 !important; color:#1a1a1a; }
.card-header, .modal-header { background-color: #fffaf2; }
.custom-control-input:checked ~ .custom-control-label::before {
  border-color: var(--bb-coffee) !important;
  background-color: var(--bb-coffee) !important;
}
.page-item.active .page-link {
  background-color: var(--bb-coffee) !important;
  border-color: var(--bb-coffee-dark) !important;
  color: #fff !important;
}
.dropdown .dropdown-menu .dropdown-header {
  background: linear-gradient(90deg, var(--bb-coffee-dark), var(--bb-coffee-light)) !important;
  color: #fff !important;
}
CSS;
        if ($echo) echo "<style>{$css}</style>";
        return $css;
    }
}
