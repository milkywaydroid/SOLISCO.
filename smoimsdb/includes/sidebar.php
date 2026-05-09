<?php
/* ============================================================
   FILE: pages/includes/sidebar.php
   SMOIMS Sidebar — collapsible mini-rail with hover-expand.
   Uses CSS-only hover expand on desktop, click-toggle on mobile.
   Fully responsive: never leaves a gap — main content
   stays flush against the rail and reflows when expanded.
   ============================================================ */
$currentPage = basename($_SERVER['PHP_SELF']);

$navItems = [
  ['dashboard.php', 'Dashboard',       '../images/dashboard.png', '📊'],
  ['orders.php',    'Manage Orders',   '../images/orders.png',    '📦'],
  ['reviews.php',   'Manage Reviews',  '../images/reviews.png',   '⭐'],
  ['inventory.php', 'Inventory',       '../images/inventory.png', '🏪'],
  ['records.php',   'Records',         '../images/records.png',   '📋'],
  ['reports.php',   'Reports',         '../images/reports.png',   '📈'],
  ['cashflow.php',  'Cash Flow',       '../images/cashflow.png',  '💸'],
];
?>
<style>
  :root{
    --sb-w-collapsed: 72px;
    --sb-w-expanded: 248px;
    --sb-grad: #564586;            /* instead of linear-gradient(...) */
    --sb-grad-soft: #f3e8ff;
    --sb-ink:#1a1a2e;
    --sb-muted:#6b7280;
    --sb-border: rgba(167, 139, 250, 0.3);
    --sb-surface:#f3e8ff;
    --sb-surface-2:#e9d5ff;
    --sb-shadow:0 14px 40px rgba(80,60,160,.10);
    --sb-ease:cubic-bezier(.22,1,.36,1);
  }

  /* The sidebar reserves only the COLLAPSED width in layout flow.
     When expanded (hovered or .open), it floats over content via
     overflow + absolute "extra" — actually we use width transition
     on a sticky element so adjacent main fills remaining space and
     reflows smoothly when sidebar grows. */
  .sidebar{
    width: var(--sb-w-collapsed);
    flex-shrink: 0;
    /* Siguraduhing solid color ito at hindi rgba/transparent */
    background: var(--sb-surface); 
    
    /* ALISIN O I-COMMENT OUT ANG MGA ITO KUNG MERON: */
    /* backdrop-filter: blur(10px); */
    /* -webkit-backdrop-filter: blur(10px); */
    
    border-right: 1px solid var(--sb-border);
    box-shadow: var(--sb-shadow);
    display: flex;
    flex-direction: column;
    padding: 22px 12px;
    position: sticky;
    top: 0;
    height: 100vh;
    z-index: 50;
    overflow: hidden;
    transition: width .32s var(--sb-ease), padding .32s var(--sb-ease);
  }
  /* Hover or pinned-open: widen */
  .sidebar:hover,
  .sidebar.open,
  .sidebar:focus-within{
    width:var(--sb-w-expanded);
    padding:22px 16px;
  }

  /* Logo */
  .sidebar-logo{
    font-family:'Playfair Display',Georgia,serif;
    font-size:1.5rem;font-weight:800;letter-spacing:.5px;
    color:var(--sb-ink);
    padding:4px 8px 18px;
    border-bottom:1px solid var(--sb-border);
    margin-bottom:14px;
    display:flex;align-items:center;gap:6px;
    white-space:nowrap;overflow:hidden;
  }
  .sidebar-logo .logo-short{display:inline}
  .sidebar-logo .logo-full{display:none}
  .sidebar:hover .logo-short,
  .sidebar.open .logo-short{display:none}
  .sidebar:hover .logo-full,
  .sidebar.open .logo-full{display:inline}
  .sidebar-logo span{
    background:var(--sb-grad);
    -webkit-background-clip:text;background-clip:text;
    color:transparent;
  }

  /* Nav */
  .sidebar-nav{display:flex;flex-direction:column;gap:4px;flex:1}
  .sidebar-nav a{
    position:relative;
    display:flex;align-items:center;gap:14px;
    padding:11px 12px;
    border-radius:12px;
    font-weight:600;font-size:.92rem;
    color:var(--sb-ink);
    text-decoration:none;
    overflow:hidden;
    white-space:nowrap;
    transition:color .3s var(--sb-ease), background .3s var(--sb-ease), box-shadow .3s var(--sb-ease);
  }
  .sidebar-nav a::before{
    content:'';position:absolute;inset:0;
    background:var(--sb-grad);
    opacity:0;
    transition:opacity .3s var(--sb-ease);
    z-index:0;
  }
  .sidebar-nav a > *{position:relative;z-index:1}
  .sidebar-nav a:hover{background:var(--sb-surface-2);}
  .sidebar-nav a.active{
    color:#fff;
    box-shadow:0 10px 24px rgba(167,139,250,.35);
  }
  .sidebar-nav a.active::before{opacity:1}

  .sidebar-icon{
    width:24px;height:24px;flex-shrink:0;
    object-fit:contain;border-radius:6px;
    transition:transform .3s var(--sb-ease), filter .3s var(--sb-ease);
  }
  .sidebar-nav a:hover .sidebar-icon{transform:scale(1.1)}
  .sidebar-nav a.active .sidebar-icon{filter:brightness(0) invert(1)}
  .sidebar-emoji{display:none;font-size:1.1rem;width:24px;text-align:center;flex-shrink:0;}

  /* Labels: hidden when collapsed, fade in when expanded */
  .nav-label{
    opacity:0;
    transform:translateX(-6px);
    transition:opacity .25s var(--sb-ease) .05s, transform .25s var(--sb-ease) .05s;
    pointer-events:none;
  }
  .sidebar:hover .nav-label,
  .sidebar.open .nav-label{
    opacity:1;transform:translateX(0);pointer-events:auto;
  }

  /* Tooltip flag while collapsed (so users still know what each icon does) */
  .sidebar:not(:hover):not(.open) .sidebar-nav a::after{
    content: attr(data-label);
    position:absolute;left:calc(100% + 10px);top:50%;
    transform:translateY(-50%) scale(.95);
    background:var(--sb-ink);color:#fff;
    font-size:.75rem;font-weight:600;
    padding:6px 10px;border-radius:8px;
    white-space:nowrap;
    opacity:0;pointer-events:none;
    transition:opacity .2s var(--sb-ease), transform .2s var(--sb-ease);
    box-shadow:0 8px 20px rgba(0,0,0,.18);
    z-index:99;
  }
  .sidebar:not(:hover):not(.open) .sidebar-nav a:hover::after{
    opacity:1;transform:translateY(-50%) scale(1);
  }

  /* Bottom area */
  .sidebar-bottom{
    margin-top:14px;padding-top:14px;
    border-top:1px solid var(--sb-border);
  }
  .sidebar-user{
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 12px;
    /* Palitan ang gradient ng mas dark o light na solid color para sa contrast */
    background: #ffffff; 
    font-weight: 600;
    font-size: .85rem;
    color: var(--sb-ink);
    margin-bottom: 8px;
    white-space: nowrap;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  }
  .sidebar-user .avatar{
    width:32px;height:32px;border-radius:50%;flex-shrink:0;
    background:var(--sb-grad);color:#fff;
    display:flex;align-items:center;justify-content:center;
    font-weight:800;font-size:.85rem;
    box-shadow:0 4px 12px rgba(167,139,250,.4);
  }
  .sidebar-user .user-name{
    opacity:0;transition:opacity .25s var(--sb-ease) .05s;
  }
  .sidebar:hover .sidebar-user .user-name,
  .sidebar.open .sidebar-user .user-name{opacity:1}

  .sidebar-bottom .btn{
    display:flex;align-items:center;justify-content:center;gap:8px;
    padding:10px 12px;border-radius:10px;
    background:var(--sb-surface-2);
    color:var(--sb-ink);font-weight:600;font-size:.85rem;
    border:1px solid var(--sb-border);
    transition:all .25s var(--sb-ease);
    text-decoration:none;white-space:nowrap;overflow:hidden;
  }
  .sidebar-bottom .btn:hover{
    background:var(--sb-grad);color:#fff;border-color:transparent;
    box-shadow:0 8px 20px rgba(167,139,250,.45);
  }
  .sidebar-bottom .btn .btn-label{
    opacity:0;transition:opacity .25s var(--sb-ease) .05s;
  }
  .sidebar:hover .sidebar-bottom .btn .btn-label,
  .sidebar.open .sidebar-bottom .btn .btn-label{opacity:1}

  /* Mobile burger trigger */
  .sb-burger{
    display:none;
    position:fixed;top:14px;left:14px;z-index:60;
    width:40px;height:40px;border-radius:10px;
    background:var(--sb-surface);border:1px solid var(--sb-border);
    box-shadow:var(--sb-shadow);
    align-items:center;justify-content:center;
    font-size:1.1rem;cursor:pointer;
  }

  @media (max-width:768px){
    .sidebar{
      position:fixed;left:-280px;top:0;width:var(--sb-w-expanded);
      padding:22px 16px;
      transition:left .35s var(--sb-ease);
    }
    .sidebar:hover{width:var(--sb-w-expanded)} /* no growing on mobile */
    .sidebar.open{left:0}
    .sidebar .nav-label,
    .sidebar .sidebar-user .user-name,
    .sidebar .sidebar-bottom .btn .btn-label{opacity:1;transform:none}
    .sidebar .logo-short{display:none}
    .sidebar .logo-full{display:inline}
    .sb-burger{display:inline-flex}
  }

  @keyframes sb-fade-in{
    from{opacity:0;transform:translateX(-12px)}
    to{opacity:1;transform:translateX(0)}
  }
  .sidebar-nav a{animation:sb-fade-in .45s var(--sb-ease) both}
  <?php foreach ($navItems as $i => $_): ?>
  .sidebar-nav a:nth-child(<?= $i+1 ?>){animation-delay:<?= 0.05 * ($i+1) ?>s}
  <?php endforeach; ?>

  .sidebar-img-logo{
    width:32px;height:32px;border-radius:6px;object-fit:cover;
  }
</style>

<button type="button" class="sb-burger" aria-label="Open menu"
        onclick="document.getElementById('appSidebar').classList.toggle('open')">☰</button>

<aside class="sidebar" id="appSidebar">
  <div class="sidebar-logo">
    <span class="logo-short"><img src="../images/logo.jpg" alt="Logo" class="sidebar-img-logo"></span>
    <span class="logo-full">Solis<span> Company</span></span>
  </div>

  <nav class="sidebar-nav">
    <?php foreach ($navItems as [$href, $label, $img, $emoji]):
      $isActive = $currentPage === $href; ?>
      <a href="<?= $href ?>" class="<?= $isActive ? 'active' : '' ?>" data-label="<?= htmlspecialchars($label) ?>">
        <img src="<?= $img ?>" alt="" class="sidebar-icon"
             onerror="this.style.display='none';this.nextElementSibling.style.display='inline-block'">
        <span class="sidebar-emoji"><?= $emoji ?></span>
        <span class="nav-label"><?= $label ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-bottom">
    <div class="sidebar-user">
      <div class="avatar"><?= strtoupper(substr($_SESSION['staff_name'] ?? 'S', 0, 1)) ?></div>
      <div class="user-name"><?= htmlspecialchars($_SESSION['staff_name'] ?? 'Staff') ?></div>
    </div>
    <a href="../logout.php" class="btn">
      <span class="btn-label">Logout</span>
    </a>
  </div>
</aside>
