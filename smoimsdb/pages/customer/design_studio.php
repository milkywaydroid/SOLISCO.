<?php
/* ============================================================
   DESIGN STUDIO — Standalone Include
   FILE: pages/customer/design_studio.php

   Required variables (must exist in parent scope):
     $sideImages          array  ['front'=>url|null, ...]
     $existingBoundaries  array  decoded design_boundaries JSON
     $product             array  full product row from inventory

   Outputs: <style>, HTML, and <script> for the Design Studio panel.
   Safe to include ONCE inside ordering_item.php.
   ============================================================ */
?>

<!-- ═══════════════════════════════════════════
     DESIGN STUDIO — Extra Styles
═══════════════════════════════════════════ -->
<style id="ds-extra-styles">
/* ── Design Studio new controls ── */
.ds-btn-flip {
  background: #fff;
  border: 1.5px solid var(--c-border);
  color: var(--c-muted);
  padding: 7px 13px;
  border-radius: 99px;
  font-size: .78rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all .22s;
  font-family: var(--font-body);
  cursor: pointer;
}
.ds-btn-flip:hover       { border-color: var(--c-accent-mid); color: var(--c-accent); }
.ds-btn-flip.active      { background: var(--c-accent-light); border-color: var(--c-accent); color: var(--c-accent); }

.ds-btn-center {
  background: #fff;
  border: 1.5px solid var(--c-border);
  color: var(--c-muted);
  padding: 7px 13px;
  border-radius: 99px;
  font-size: .78rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all .22s;
  font-family: var(--font-body);
  cursor: pointer;
}
.ds-btn-center:hover { border-color: var(--c-accent-mid); color: var(--c-accent); }

.ds-opacity-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 18px 10px;
  border-top: 1.5px solid var(--c-border);
  background: rgba(250,249,255,.6);
  flex-wrap: wrap;
}
.ds-opacity-label {
  font-size: .74rem;
  font-weight: 700;
  color: var(--c-muted);
  white-space: nowrap;
}
.ds-opacity-slider {
  flex: 1;
  min-width: 100px;
  accent-color: var(--c-accent);
  cursor: pointer;
}
.ds-opacity-val {
  font-size: .74rem;
  font-weight: 800;
  color: var(--c-accent);
  min-width: 34px;
  text-align: right;
}

/* Rotate handle via CSS (the ::after sets the symbol) */
.ds-rotate-handle::after { content: '↻'; }
</style>

<!-- ═══════════════════════════════════════════
     DESIGN STUDIO — HTML
═══════════════════════════════════════════ -->
<div class="panel panel-center">
  <div class="panel-head">
    <span style="font-size:1.15rem">🎨</span>
    <span class="panel-head-title">Design Studio</span>
    <span style="margin-left:auto;font-size:.74rem;color:var(--c-muted);font-weight:600;">Upload designs for all 4 sides</span>
  </div>

  <?php foreach(['front','back','left','right'] as $sk): ?>
    <input type="file" id="ds_file_<?= $sk ?>" accept="image/*" style="display:none">
  <?php endforeach; ?>

  <!-- Side Tabs -->
  <div class="ds-tabs">
    <?php foreach(['front'=>['⬆','Front'],'back'=>['⬇','Back'],'left'=>['◀','Left'],'right'=>['▶','Right']] as $sk=>[$icon,$lbl]): ?>
      <button type="button" class="ds-tab <?= $sk==='front'?'active':'' ?>"
              id="dstab_<?= $sk ?>"
              onclick="dsSwitchTab('<?= $sk ?>')">
        <span class="ds-tab-icon"><?= $icon ?></span>
        <span class="ds-tab-label"><?= $lbl ?></span>
        <span class="ds-dot" id="dsdot_<?= $sk ?>"></span>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Canvas Area -->
  <div class="ds-canvas-area" id="dsCanvasArea">
    <div id="ds_canvas_container">
      <canvas id="ds_product_canvas"></canvas>
      <div id="ds_mask_window" style="display:none">
        <div class="ds-mask-line"></div>
        <div id="ds_image_wrapper">
          <img id="ds_design_img" src="" alt="Design"
               style="display:block;width:100%;height:100%;object-fit:contain;pointer-events:none;">
          <div class="ds-resize-handle" id="ds_resize_handle" title="Drag to resize"></div>
          <div class="ds-rotate-handle" id="ds_rotate_handle" title="Drag to rotate"></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="ds-toolbar" id="ds_toolbar">
    <button type="button" class="ds-btn ds-btn-primary" onclick="dsTriggerUpload()">📎 Upload Design</button>
    <button type="button" class="ds-btn ds-btn-ghost ds-btn-danger" id="dsClearBtn"
            style="display:none" onclick="dsClearSide()">🗑 Clear</button>
    <button type="button" class="ds-btn ds-btn-ghost" onclick="dsMarkNA()">✕ No design</button>

    <!-- Extra controls — shown only when a design is loaded -->
    <button type="button" class="ds-btn-center" id="dsCenterBtn"
            style="display:none" onclick="dsCenterDesign()" title="Centre in print zone">⊕ Centre</button>
    <button type="button" class="ds-btn-center" id="dsFitBtn"
            style="display:none" onclick="dsFitDesign()" title="Fit to print zone">⤢ Fit</button>
    <button type="button" class="ds-btn-flip" id="dsFlipHBtn"
            style="display:none" onclick="dsFlip('h')" title="Flip horizontal">⇄ Flip H</button>
    <button type="button" class="ds-btn-flip" id="dsFlipVBtn"
            style="display:none" onclick="dsFlip('v')" title="Flip vertical">⇅ Flip V</button>

    <div class="ds-spacer"></div>
    <div class="ds-status">
      <span class="ds-status-dot" id="dsStatusDot"></span>
      <span id="dsStatusText">Upload or mark N/A</span>
    </div>
  </div>

  <!-- Opacity row — shown when design loaded -->
  <div class="ds-opacity-row" id="dsOpacityRow" style="display:none">
    <span class="ds-opacity-label">Opacity</span>
    <input type="range" class="ds-opacity-slider" id="dsOpacitySlider"
           min="10" max="100" value="100" oninput="dsSetOpacity(this.value)">
    <span class="ds-opacity-val" id="dsOpacityVal">100%</span>
  </div>

  <!-- Side summary chips -->
  <div class="ds-summary" id="dsSummary">
    <?php foreach(['front'=>'⬆ Front','back'=>'⬇ Back','left'=>'◀ Left','right'=>'▶ Right'] as $sk=>$sl): ?>
      <div class="ds-chip" id="dssum_<?= $sk ?>">
        <span class="ds-chip-dot"></span><?= $sl ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="ds-tip">
    💡 <strong>Drag</strong> to move &nbsp;·&nbsp;
    <strong>Corner ◢</strong> to resize (any direction) &nbsp;·&nbsp;
    <strong>Top ↻</strong> to rotate &nbsp;·&nbsp;
    <strong>Scroll wheel</strong> to zoom &nbsp;·&nbsp;
    <strong>Arrow keys</strong> to nudge &nbsp;·&nbsp;
    <strong>⊕ Centre</strong> / <strong>⤢ Fit</strong> to snap &nbsp;·&nbsp;
    <strong>Flip H/V</strong> to mirror &nbsp;·&nbsp;
    <strong>"No design"</strong> if side is blank
  </div>
</div><!-- /panel panel-center -->


<!-- ═══════════════════════════════════════════
     DESIGN STUDIO — JavaScript
═══════════════════════════════════════════ -->
<script>
/* ── DS state ─────────────────────────────────── */
let dsCurrentSide = 'front';
let dsBoundaries  = <?= json_encode($existingBoundaries ?: (object)[]) ?>;
let dsDesignImage = null;

// Per-session transform (rebuilt on each tab switch restore)
let dsTransform = {
  translateX: 0, translateY: 0,
  scale: 1, rotate: 0,
  baseWidth: 0, baseHeight: 0,
  opacity: 1,
  flipH: false, flipV: false
};

// Which sides are marked N/A
let dsIsNA = { front: false, back: false, left: false, right: false };

// Persistent per-side state (survives tab switches)
window._dsSideData = {};

/* ── Helpers ───────────────────────────────────── */
function _dsShowDesignControls(visible) {
  const ids = ['dsClearBtn','dsCenterBtn','dsFitBtn','dsFlipHBtn','dsFlipVBtn'];
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? 'inline-flex' : 'none';
  });
  const opRow = document.getElementById('dsOpacityRow');
  if (opRow) opRow.style.display = visible ? 'flex' : 'none';
}

/* ── Load product background image ─────────────── */
function dsLoadProductImage(side) {
  const canvas = document.getElementById('ds_product_canvas');
  const area   = document.getElementById('dsCanvasArea');
  if (!canvas) return;

  const imgUrl = SIDE_IMAGES[side];
  if (!imgUrl) {
    canvas.width  = 600; canvas.height = 400;
    canvas.style.width = '100%'; canvas.style.height = 'auto';
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#f5f3ff'; ctx.fillRect(0, 0, 600, 400);
    ctx.fillStyle = '#a78bfa'; ctx.font = 'bold 15px DM Sans, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('No product image for this side', 300, 200);
    document.getElementById('ds_mask_window').style.display = 'none';
    return;
  }

  const img = new Image(); img.crossOrigin = 'Anonymous';
  img.onload = function () {
    requestAnimationFrame(() => {
      const panelW = area.clientWidth > 0 ? area.clientWidth : 700;
      const ratio  = Math.min(panelW / img.naturalWidth, 600 / img.naturalHeight);
      const w = Math.round(img.naturalWidth  * ratio);
      const h = Math.round(img.naturalHeight * ratio);
      canvas.width  = w; canvas.height = h;
      canvas.style.width = '100%'; canvas.style.height = 'auto';
      canvas.getContext('2d').drawImage(img, 0, 0, w, h);
      dsApplyMaskWindow(side);
    });
  };
  img.src = imgUrl;
}

function dsApplyMaskWindow(side) {
  const canvas  = document.getElementById('ds_product_canvas');
  const maskDiv = document.getElementById('ds_mask_window');
  if (!canvas || !maskDiv) return;
  const b = dsBoundaries[side] || { x: 0.08, y: 0.08, w: 0.84, h: 0.84 };
  maskDiv.style.left   = (b.x * canvas.width)  + 'px';
  maskDiv.style.top    = (b.y * canvas.height)  + 'px';
  maskDiv.style.width  = (b.w * canvas.width)   + 'px';
  maskDiv.style.height = (b.h * canvas.height)  + 'px';
  maskDiv.style.display = 'block';
}

/* ── Upload ─────────────────────────────────────── */
function dsTriggerUpload() {
  const fi = document.getElementById('ds_file_' + dsCurrentSide);
  fi.value = '';
  fi.onchange = e => { if (e.target.files?.[0]) dsLoadDesign(e.target.files[0]); };
  fi.click();
}

function dsLoadDesign(file) {
  if (!file.type.startsWith('image/')) { showToast('Please upload an image file', 'error'); return; }
  const reader = new FileReader();
  reader.onload = ev => {
    const img = new Image();
    img.onload = function () {
      dsDesignImage = img;
      const maskDiv = document.getElementById('ds_mask_window');
      const mw = maskDiv.clientWidth  || 300;
      const mh = maskDiv.clientHeight || 300;
      const fitScale = Math.min(mw / img.width, mh / img.height) * 0.92;
      dsTransform = {
        translateX:  (mw - img.width  * fitScale) / 2,
        translateY:  (mh - img.height * fitScale) / 2,
        scale:       fitScale,
        rotate:      0,
        baseWidth:   img.width,
        baseHeight:  img.height,
        opacity:     1,
        flipH:       false,
        flipV:       false
      };
      // Reset opacity slider
      document.getElementById('dsOpacitySlider').value = 100;
      document.getElementById('dsOpacityVal').innerText = '100%';
      // Reset flip buttons
      document.getElementById('dsFlipHBtn')?.classList.remove('active');
      document.getElementById('dsFlipVBtn')?.classList.remove('active');

      dsIsNA[dsCurrentSide] = false;
      document.getElementById('na_flag_' + dsCurrentSide).value = '0';
      dsUpdateTransform();
      dsUpdateUIForDesign();
    };
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
}

/* ── Transform application ──────────────────────── */
function dsUpdateTransform() {
  const wrapper = document.getElementById('ds_image_wrapper');
  const img     = document.getElementById('ds_design_img');
  if (!wrapper || !img) return;

  const s = dsTransform.scale;
  const w = dsTransform.baseWidth  * s;
  const h = dsTransform.baseHeight * s;
  wrapper.style.width   = w + 'px';
  wrapper.style.height  = h + 'px';

  const scaleX = dsTransform.flipH ? -1 : 1;
  const scaleY = dsTransform.flipV ? -1 : 1;
  wrapper.style.transform =
    `translate(${dsTransform.translateX}px,${dsTransform.translateY}px)` +
    ` rotate(${dsTransform.rotate}deg)`;
  img.style.transform = `scale(${scaleX},${scaleY})`;
  img.style.opacity   = dsTransform.opacity;
  wrapper.style.display = 'block';
}

function dsUpdateUIForDesign() {
  document.getElementById('dsStatusText').innerText = 'Design ready — drag, resize, rotate';
  document.getElementById('dsStatusDot').className  = 'ds-status-dot ok';
  document.getElementById('ds_design_img').src = dsDesignImage.src;
  document.getElementById('ds_design_img').style.display = 'block';
  _dsShowDesignControls(true);
  saveCurrentSideDesign();
  dsUpdateSummaryDots();
  refreshAddBtn();
}

/* ── Clamp design within mask zone ─────────────── */
function dsClamp() {
  const m = document.getElementById('ds_mask_window');
  if (!m) return;
  const mw = m.clientWidth, mh = m.clientHeight;
  const iw = dsTransform.baseWidth  * dsTransform.scale;
  const ih = dsTransform.baseHeight * dsTransform.scale;
  // Allow 20% of the image to slide out on each side
  dsTransform.translateX = Math.min(mw * .8, Math.max(-iw + mw * .2, dsTransform.translateX));
  dsTransform.translateY = Math.min(mh * .8, Math.max(-ih + mh * .2, dsTransform.translateY));
  dsUpdateTransform();
}

/* ── Centre & Fit ───────────────────────────────── */
function dsCenterDesign() {
  if (!dsDesignImage) return;
  const m = document.getElementById('ds_mask_window');
  if (!m) return;
  const mw = m.clientWidth, mh = m.clientHeight;
  const iw = dsTransform.baseWidth  * dsTransform.scale;
  const ih = dsTransform.baseHeight * dsTransform.scale;
  dsTransform.translateX = (mw - iw) / 2;
  dsTransform.translateY = (mh - ih) / 2;
  dsUpdateTransform();
  saveCurrentSideDesign();
}

function dsFitDesign() {
  if (!dsDesignImage) return;
  const m = document.getElementById('ds_mask_window');
  if (!m) return;
  const mw = m.clientWidth, mh = m.clientHeight;
  const fitScale = Math.min(mw / dsTransform.baseWidth, mh / dsTransform.baseHeight) * 0.94;
  dsTransform.scale = fitScale;
  const iw = dsTransform.baseWidth  * fitScale;
  const ih = dsTransform.baseHeight * fitScale;
  dsTransform.translateX = (mw - iw) / 2;
  dsTransform.translateY = (mh - ih) / 2;
  dsUpdateTransform();
  saveCurrentSideDesign();
}

/* ── Flip ───────────────────────────────────────── */
function dsFlip(axis) {
  if (!dsDesignImage) return;
  if (axis === 'h') {
    dsTransform.flipH = !dsTransform.flipH;
    document.getElementById('dsFlipHBtn')?.classList.toggle('active', dsTransform.flipH);
  } else {
    dsTransform.flipV = !dsTransform.flipV;
    document.getElementById('dsFlipVBtn')?.classList.toggle('active', dsTransform.flipV);
  }
  dsUpdateTransform();
  saveCurrentSideDesign();
}

/* ── Opacity ────────────────────────────────────── */
function dsSetOpacity(val) {
  dsTransform.opacity = parseInt(val) / 100;
  document.getElementById('dsOpacityVal').innerText = val + '%';
  dsUpdateTransform();
  saveCurrentSideDesign();
}

/* ── Clear / N/A ────────────────────────────────── */
function dsClearSide() {
  dsDesignImage = null;
  dsTransform = { translateX:0, translateY:0, scale:1, rotate:0, baseWidth:0, baseHeight:0, opacity:1, flipH:false, flipV:false };
  const designImg = document.getElementById('ds_design_img');
  if (designImg) { designImg.src = ''; designImg.style.display = 'none'; designImg.style.transform = ''; designImg.style.opacity = ''; }
  _dsShowDesignControls(false);
  document.getElementById('dsStatusText').innerText = 'Upload or mark N/A';
  document.getElementById('dsStatusDot').className  = 'ds-status-dot';
  dsIsNA[dsCurrentSide] = false;
  document.getElementById('na_flag_' + dsCurrentSide).value = '0';
  delete window._dsSideData[dsCurrentSide];
  // Reset opacity slider
  document.getElementById('dsOpacitySlider').value = 100;
  document.getElementById('dsOpacityVal').innerText = '100%';
  document.getElementById('dsFlipHBtn')?.classList.remove('active');
  document.getElementById('dsFlipVBtn')?.classList.remove('active');
  dsUpdateSummaryDots();
  refreshAddBtn();
}
window.dsClearSide = dsClearSide;

function dsMarkNA() {
  dsClearSide();
  dsIsNA[dsCurrentSide] = true;
  document.getElementById('na_flag_' + dsCurrentSide).value = '1';
  document.getElementById('dsStatusText').innerText = 'No design (N/A)';
  document.getElementById('dsStatusDot').className  = 'ds-status-dot na';
  window._dsSideData[dsCurrentSide] = { design: null, na: true, transform: null };
  dsUpdateSummaryDots();
  refreshAddBtn();
}
window.dsMarkNA = dsMarkNA;

/* ── Dot + chip sync ────────────────────────────── */
function dsUpdateSummaryDots() {
  ['front','back','left','right'].forEach(side => {
    const dot   = document.getElementById('dsdot_'  + side);
    const sum   = document.getElementById('dssum_'  + side);
    const dchip = document.getElementById('dchip_'  + side);
    if (!dot || !sum) return;
    dot.classList.remove('filled','na-dot');
    sum.classList.remove('filled','na');
    dchip?.classList.remove('ok','na-c');
    const data = window._dsSideData[side];
    if      (data?.na)     { dot.classList.add('na-dot'); sum.classList.add('na');     dchip?.classList.add('na-c'); }
    else if (data?.design) { dot.classList.add('filled');  sum.classList.add('filled'); dchip?.classList.add('ok');   }
  });
}

/* ── Persist per-side state ─────────────────────── */
function saveCurrentSideDesign() {
  window._dsSideData[dsCurrentSide] = {
    design:    dsDesignImage,
    na:        dsIsNA[dsCurrentSide],
    transform: dsTransform ? { ...dsTransform } : null
  };
}

/* ── Tab switch ─────────────────────────────────── */
function dsSwitchTab(side) {
  saveCurrentSideDesign();
  dsCurrentSide = side;
  document.querySelectorAll('.ds-tab').forEach(t => t.classList.remove('active'));
  document.getElementById('dstab_' + side)?.classList.add('active');
  dsLoadProductImage(side);

  // Restore state for this side after canvas redraws
  setTimeout(() => {
    const data = window._dsSideData[side];
    if (data?.na) {
      dsIsNA[side] = true;
      document.getElementById('na_flag_' + side).value = '1';
      const di = document.getElementById('ds_design_img');
      if (di) { di.style.display='none'; di.src=''; }
      _dsShowDesignControls(false);
      document.getElementById('dsStatusText').innerText = 'No design (N/A)';
      document.getElementById('dsStatusDot').className  = 'ds-status-dot na';
    } else if (data?.design) {
      dsDesignImage = data.design;
      dsTransform   = { ...data.transform };
      // Restore opacity slider
      const opPct = Math.round((dsTransform.opacity ?? 1) * 100);
      document.getElementById('dsOpacitySlider').value = opPct;
      document.getElementById('dsOpacityVal').innerText = opPct + '%';
      // Restore flip buttons
      document.getElementById('dsFlipHBtn')?.classList.toggle('active', !!dsTransform.flipH);
      document.getElementById('dsFlipVBtn')?.classList.toggle('active', !!dsTransform.flipV);
      dsUpdateTransform();
      dsUpdateUIForDesign();
    } else {
      dsClearSide();
    }
    dsUpdateSummaryDots();
  }, 280);
}
window.dsSwitchTab = dsSwitchTab;

/* ══════════════════════════════════════════════════
   POINTER / TOUCH INTERACTION ENGINE
   (drag, resize with signed delta, rotate, pinch,
    scroll-wheel zoom, arrow-key nudge)
══════════════════════════════════════════════════ */
(function attachDSInteractions() {

  const getWrapper  = () => document.getElementById('ds_image_wrapper');
  const getMaskDiv  = () => document.getElementById('ds_mask_window');
  const getResizeH  = () => document.getElementById('ds_resize_handle');
  const getRotateH  = () => document.getElementById('ds_rotate_handle');

  // ── Pointer map (supports multi-touch) ──────────
  const ptrs = {};           // pointerId → {x,y}
  let   mode = null;         // 'drag' | 'resize' | 'rotate' | 'pinch'

  // Drag
  let dragStartX, dragStartY, dragStartTX, dragStartTY;
  // Resize — signed diagonal distance from start
  let resizeStartX, resizeStartY, resizeStartScale;
  // Rotate
  let rotateStartAngle, rotateStartRotate;
  // Pinch
  let pinchStartDist, pinchStartScale;

  function pointerDist(ptrMap) {
    const pts = Object.values(ptrMap);
    if (pts.length < 2) return 0;
    const dx = pts[1].x - pts[0].x, dy = pts[1].y - pts[0].y;
    return Math.sqrt(dx*dx + dy*dy);
  }

  function activateMode(m, e) {
    mode = m;
    const wrapper = getWrapper();
    if (wrapper) { try { wrapper.setPointerCapture(e.pointerId); } catch(_){} }
    const maskDiv = getMaskDiv();
    const resizeH = getResizeH();
    const rotateH = getRotateH();
    if (m === 'drag')   { try { wrapper.setPointerCapture(e.pointerId); } catch(_){} }
    if (m === 'resize') { try { resizeH?.setPointerCapture(e.pointerId); } catch(_){} }
    if (m === 'rotate') { try { rotateH?.setPointerCapture(e.pointerId); } catch(_){} }
  }

  // ── Attach listeners once elements exist ────────
  function init() {
    const wrapper = getWrapper();
    const resizeH = getResizeH();
    const rotateH = getRotateH();
    const maskDiv = getMaskDiv();
    const canvasArea = document.getElementById('dsCanvasArea');
    if (!wrapper || !resizeH || !rotateH || !maskDiv || !canvasArea) {
      setTimeout(init, 200);
      return;
    }

    // Drag — on wrapper
    wrapper.addEventListener('pointerdown', e => {
      if (e.target === resizeH || e.target === rotateH) return;
      e.preventDefault();
      ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
      const cnt = Object.keys(ptrs).length;
      if (cnt === 2) {
        mode = 'pinch';
        pinchStartDist  = pointerDist(ptrs);
        pinchStartScale = dsTransform.scale;
        return;
      }
      dragStartX  = e.clientX; dragStartY  = e.clientY;
      dragStartTX = dsTransform.translateX;
      dragStartTY = dsTransform.translateY;
      activateMode('drag', e);
    });

    // Resize — on resize handle
    resizeH.addEventListener('pointerdown', e => {
      e.preventDefault(); e.stopPropagation();
      ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
      resizeStartX     = e.clientX; resizeStartY    = e.clientY;
      resizeStartScale = dsTransform.scale;
      activateMode('resize', e);
    });

    // Rotate — on rotate handle
    rotateH.addEventListener('pointerdown', e => {
      e.preventDefault(); e.stopPropagation();
      ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
      const r   = maskDiv.getBoundingClientRect();
      const cx  = r.left + r.width  / 2;
      const cy  = r.top  + r.height / 2;
      rotateStartAngle  = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI;
      rotateStartRotate = dsTransform.rotate;
      activateMode('rotate', e);
    });

    // Move
    window.addEventListener('pointermove', e => {
      if (!mode) return;
      ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
      if (!dsDesignImage) return;

      if (mode === 'pinch') {
        const newDist = pointerDist(ptrs);
        if (pinchStartDist > 0) {
          dsTransform.scale = Math.min(8, Math.max(0.05, pinchStartScale * (newDist / pinchStartDist)));
          dsClamp();
        }
        return;
      }
      if (mode === 'drag') {
        dsTransform.translateX = dragStartTX + (e.clientX - dragStartX);
        dsTransform.translateY = dragStartTY + (e.clientY - dragStartY);
        dsClamp();
        return;
      }
      if (mode === 'resize') {
        // Signed diagonal: positive = grow (drag SE), negative = shrink (drag NW)
        const dx = e.clientX - resizeStartX;
        const dy = e.clientY - resizeStartY;
        const sign = (dx + dy) >= 0 ? 1 : -1;
        const dist = Math.sqrt(dx*dx + dy*dy) * sign;
        const newScale = resizeStartScale + dist * 0.006;
        dsTransform.scale = Math.min(8, Math.max(0.05, newScale));
        dsClamp();
        return;
      }
      if (mode === 'rotate') {
        const r  = maskDiv.getBoundingClientRect();
        const cx = r.left + r.width  / 2;
        const cy = r.top  + r.height / 2;
        const angle = Math.atan2(e.clientY - cy, e.clientX - cx) * 180 / Math.PI;
        dsTransform.rotate = rotateStartRotate + (angle - rotateStartAngle);
        dsUpdateTransform();
      }
    });

    // Up / Cancel
    function onPointerUp(e) {
      delete ptrs[e.pointerId];
      if (Object.keys(ptrs).length === 0) {
        if (mode) saveCurrentSideDesign();
        mode = null;
      } else if (mode === 'pinch' && Object.keys(ptrs).length === 1) {
        // Back to single-touch drag
        const pt = Object.values(ptrs)[0];
        dragStartX  = pt.x; dragStartY  = pt.y;
        dragStartTX = dsTransform.translateX;
        dragStartTY = dsTransform.translateY;
        mode = 'drag';
      }
    }
    window.addEventListener('pointerup',     onPointerUp);
    window.addEventListener('pointercancel', onPointerUp);

    // ── Scroll-wheel zoom ────────────────────────
    canvasArea.addEventListener('wheel', e => {
      if (!dsDesignImage) return;
      e.preventDefault();
      const delta = e.deltaY > 0 ? -0.08 : 0.08;
      dsTransform.scale = Math.min(8, Math.max(0.05, dsTransform.scale + delta));
      dsClamp();
      saveCurrentSideDesign();
    }, { passive: false });

    // ── Arrow-key nudge ──────────────────────────
    document.addEventListener('keydown', e => {
      if (!dsDesignImage) return;
      const el = document.activeElement;
      if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) return;
      const step = e.shiftKey ? 10 : 1;
      let moved = false;
      if (e.key === 'ArrowLeft')  { dsTransform.translateX -= step; moved = true; }
      if (e.key === 'ArrowRight') { dsTransform.translateX += step; moved = true; }
      if (e.key === 'ArrowUp')    { dsTransform.translateY -= step; moved = true; }
      if (e.key === 'ArrowDown')  { dsTransform.translateY += step; moved = true; }
      if (moved) { e.preventDefault(); dsClamp(); saveCurrentSideDesign(); }
    });
  }

  // Wait until DOM elements are ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    setTimeout(init, 50);
  }
})();

/* ── Composite for order export ─────────────────── */
async function compositeDesignForSide(side) {
  const productCanvas = document.getElementById('ds_product_canvas');
  if (!productCanvas) return null;
  const data = window._dsSideData[side];
  if (!data?.design) return null;

  const tmp = document.createElement('canvas');
  tmp.width  = productCanvas.width;
  tmp.height = productCanvas.height;
  const ctx = tmp.getContext('2d');

  // Draw product background
  await new Promise(res => {
    const im = new Image();
    im.onload  = () => { ctx.drawImage(im, 0, 0, tmp.width, tmp.height); res(); };
    im.onerror = res;
    im.src     = SIDE_IMAGES[side] || productCanvas.toDataURL();
  });

  const b  = dsBoundaries[side] || { x: 0.08, y: 0.08, w: 0.84, h: 0.84 };
  const bx = b.x * tmp.width,  by = b.y * tmp.height;
  const bw = b.w * tmp.width,  bh = b.h * tmp.height;
  const tr = data.transform;
  const iw = tr.baseWidth  * tr.scale;
  const ih = tr.baseHeight * tr.scale;

  ctx.save();
  ctx.beginPath(); ctx.rect(bx, by, bw, bh); ctx.clip();
  ctx.translate(bx + tr.translateX + iw / 2, by + tr.translateY + ih / 2);
  ctx.rotate(tr.rotate * Math.PI / 180);
  ctx.globalAlpha = tr.opacity ?? 1;
  ctx.scale(tr.flipH ? -1 : 1, tr.flipV ? -1 : 1);
  ctx.drawImage(data.design, -iw / 2, -ih / 2, iw, ih);
  ctx.restore();

  return tmp.toDataURL('image/jpeg', .9);
}
window.compositeDesignForSide = compositeDesignForSide;

/* ── Init ───────────────────────────────────────── */
requestAnimationFrame(() => { dsLoadProductImage('front'); });
window.addEventListener('resize', () => { dsLoadProductImage(dsCurrentSide); });
</script>