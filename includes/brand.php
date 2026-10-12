<?php
if (!defined('BIGBREW_BASE')) {
    $sn = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($sn));
    if ($dir === '/' || $dir === '.') {
        $base = '';
    } elseif (substr($dir, -9) === '/includes') {
        $base = substr($dir, 0, -9);
    } else {
        $base = rtrim($dir, '/');
    }
    define('BIGBREW_BASE', $base);
    // USER FINAL DIRECTIVE: "bigbrewlogo.jpg ang ilalagay mo"
    define('BIGBREW_LOGO_JPG',  BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');
    define('BIGBREW_LOGO_PNG',  BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');  // Fallback alias
    define('BIGBREW_FAVICON',   BIGBREW_BASE . '/assets/img/bigbrewlogo.jpg');  // Favicon = same JPG file!
}

if (!function_exists('bigbrew_logo_img')) {
    function bigbrew_logo_img($size = 48, $dark_frame = false, $echo = true) {
        $s = (int)$size;
        $tag = '<img src="' . htmlspecialchars(BIGBREW_LOGO_JPG) . '" width="' . $s . '" height="' . $s . '" alt="Big Brew" style="display:block; width:100%; height:100%; object-fit:cover; border-radius:14px;" onerror="this.style.display=\'none\';if(this.nextElementSibling)this.nextElementSibling.style.display=\'flex\';"><span class="bb-logo-fallback" style="display:none;align-items:center;justify-content:center;width:100%;height:100%;font-size:'.round($s*0.5).'px;color:#ffe7c7;background:#2b241c;border-radius:14px;">☕</span>';
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

/* ========================================================
   RESPONSIVE DESIGN SUITE FOR IOS & ANDROID (Mobile & Tablet)
   ======================================================== */
@media (max-width: 767.98px) {
  html, body {
    overflow-x: hidden !important;
    width: 100% !important;
    -webkit-text-size-adjust: 100%;
  }

  /* Fix iOS Safari automatic zoom on inputs */
  input, select, textarea, .form-control {
    font-size: 16px !important;
  }

  #wrapper {
    display: block !important;
    width: 100% !important;
    overflow-x: hidden !important;
  }

  #content-wrapper {
    width: 100% !important;
    min-width: 0 !important;
    overflow-x: hidden !important;
    padding: 0 !important;
  }

  /* OFF-CANVAS SLIDE-OUT DRAWER FOR MOBILE */
  #accordionSidebar.sidebar {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 280px !important;
    max-width: 84vw !important;
    height: 100vh !important;
    height: 100dvh !important;
    z-index: 1060 !important;
    margin: 0 !important;
    padding-top: max(env(safe-area-inset-top), 0.5rem) !important;
    padding-bottom: max(env(safe-area-inset-bottom), 1.5rem) !important;
    overflow-y: auto !important;
    -webkit-overflow-scrolling: touch !important;
    transform: translateX(-105%) !important;
    transition: transform 0.28s cubic-bezier(0.33, 1, 0.68, 1) !important;
    box-shadow: none !important;
    visibility: hidden;
  }

  /* When drawer is open on mobile */
  body.mobile-sidebar-open #accordionSidebar.sidebar,
  #accordionSidebar.sidebar.mobile-show {
    transform: translateX(0) !important;
    visibility: visible !important;
    box-shadow: 6px 0 25px rgba(0, 0, 0, 0.45) !important;
  }

  #accordionSidebar.sidebar .sidebar-brand {
    padding: 1rem 1.2rem !important;
    height: auto !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    text-align: left !important;
  }

  #accordionSidebar.sidebar .sidebar-brand .sidebar-brand-text {
    display: block !important;
  }

  #accordionSidebar.sidebar .sidebar-heading {
    text-align: left !important;
    padding: 0.75rem 1.25rem 0.35rem !important;
    font-size: 0.72rem !important;
    display: block !important;
  }

  #accordionSidebar.sidebar .nav-item .nav-link {
    display: flex !important;
    align-items: center !important;
    width: 100% !important;
    text-align: left !important;
    padding: 0.8rem 1.25rem !important;
    font-size: 0.95rem !important;
  }

  #accordionSidebar.sidebar .nav-item .nav-link span {
    display: inline !important;
    font-size: 0.92rem !important;
    margin-left: 0.75rem !important;
  }

  #accordionSidebar.sidebar .nav-item .nav-link i {
    font-size: 1.05rem !important;
    width: 22px !important;
    text-align: center !important;
  }

  /* MOBILE BACKDROP OVERLAY */
  .sidebar-mobile-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.52);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    z-index: 1055;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.25s ease, visibility 0.25s ease;
  }

  body.mobile-sidebar-open .sidebar-mobile-backdrop {
    opacity: 1;
    visibility: visible;
  }

  /* TOPBAR ADAPTATIONS */
  .topbar {
    height: 3.75rem !important;
    padding: 0 0.75rem !important;
    margin-bottom: 1rem !important;
  }

  #sidebarToggleTop {
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    width: 42px !important;
    height: 42px !important;
    font-size: 1.2rem !important;
    color: var(--bb-coffee-dark) !important;
    background: rgba(202, 125, 42, 0.12) !important;
    border-radius: 50% !important;
    margin-right: 0.5rem !important;
    border: none !important;
    outline: none !important;
  }

  /* CONTAINER CONTENT */
  .container-fluid {
    padding-left: 0.85rem !important;
    padding-right: 0.85rem !important;
  }

  h1.h3 {
    font-size: 1.3rem !important;
    line-height: 1.25 !important;
  }

  /* DASHBOARD / PAYROLL CARDS */
  .card {
    border-radius: 14px !important;
    margin-bottom: 0.85rem !important;
  }

  .card-body {
    padding: 1rem !important;
  }

  /* RESPONSIVE TABLES WITH TOUCH SCROLL */
  .table-responsive {
    border-radius: 12px;
    -webkit-overflow-scrolling: touch;
    margin-bottom: 1rem;
  }

  /* BUTTONS TOUCH TARGETS (44px min for iOS) */
  .btn {
    min-height: 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
  }
}
CSS;
        if ($echo) echo "<style>{$css}</style>";
        return $css;
    }
}
