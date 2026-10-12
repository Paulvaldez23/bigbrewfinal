/* ==========================================================
 * Big Brew - Weekly Shift Scheduler (User-Friendly UI)
 * PURE JavaScript — NO PHP — loaded via <script src=>
 * Two interaction modes:
 *   MODE 1 (PRIMARY - always works):
 *     Click employee card → lights up green → click any grid cell → assigned
 *     (No drag events needed. Works on slow/touch devices.)
 *   MODE 2 (BONUS): Native HTML5 drag & drop
 *     Hold employee card → drop into a cell
 *     Hold an assigned chip → drop into another cell (swap/move)
 * ========================================================== */
(function () {
  'use strict';

  // ---------- Globals (injected by PHP) ----------
  // NOTE: These are populated at FILE PARSE TIME. boot() re-reads below for order safety.
  var DATA = window.SHIFT_APP_DATA || {};
  var WEEK_START = DATA.week_start || '';
  var EMPLOYEES  = DATA.employees  || [];
  var SHIFT_ROWS = DATA.shift_rows || [];
  var DAY_HDRS   = DATA.day_headers || [];
  var INITIAL    = DATA.initial_assignments || [];
  var SHIFTS_MAP = DATA.shifts_by_key || {};
  var SAVE_URL   = DATA.save_url || 'shifts.php';

  // ---------- Defensive re-read at boot time (regardless of script tag order) ----------
  function ensureDataFromWindow() {
    var D = window.SHIFT_APP_DATA;
    if (!D) return;
    // Only refresh when the runtime data actually has content (not {})
    var hasSomething = (D.employees && D.employees.length) || (D.shift_rows && D.shift_rows.length) || (D.day_headers && D.day_headers.length);
    if (hasSomething) {
      DATA = D;
      if (D.week_start) WEEK_START = D.week_start;
      if (D.employees)  EMPLOYEES  = D.employees;
      if (D.shift_rows) SHIFT_ROWS = D.shift_rows;
      if (D.day_headers) DAY_HDRS  = D.day_headers;
      if (D.initial_assignments) INITIAL = D.initial_assignments;
      if (D.shifts_by_key) SHIFTS_MAP = D.shifts_by_key;
      if (D.save_url) SAVE_URL = D.save_url;
    }
  }

  // ---------- State ----------
  var state = {
    assignments: {},      // key = "dow|rowKey" -> [ assignment objects ]
    selected_emp_id: null,
    selected_row_key: null,
    default_branch: 'Branch 1',
    branch_filter: 'Branch 1', // 'all', 'Branch 1', 'Branch 2'
    drag: null            // {source: 'pool'|'grid', empId, dow?, row?}
  };

  // ---------- Utilities ----------
  function cellKey(dow, row) { return dow + '|' + row; }
  function pad(n) { return String(n).padStart(2,'0'); }
  function time12(t) {
    if (!t) return '';
    try {
      var parts = String(t).split(':');
      var h = parseInt(parts[0] || '0', 10);
      var m = parseInt(parts[1] || '0', 10);
      var ampm = h >= 12 ? 'PM' : 'AM';
      h = h % 12; if (h === 0) h = 12;
      return h + ':' + pad(m) + ' ' + ampm;
    } catch(e) { return t; }
  }
  function empById(id) {
    for (var i = 0; i < EMPLOYEES.length; i++) {
      if (EMPLOYEES[i].id === id || String(EMPLOYEES[i].id) === String(id)) return EMPLOYEES[i];
    }
    return null;
  }
  function shiftByKey(k) { return SHIFTS_MAP[k] || null; }

  function showToast(msg, type) {
    type = type || 'success';
    var cls = type === 'success' ? 'alert-success' : (type === 'danger' ? 'alert-danger' : 'alert-info');
    var div = document.createElement('div');
    div.className = 'alert shadow mb-2 ' + cls;
    div.setAttribute('role','alert');
    div.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;min-width:280px;';
    div.innerHTML = msg;
    document.body.appendChild(div);
    setTimeout(function(){
      div.style.transition = 'opacity 0.3s';
      div.style.opacity = '0';
      setTimeout(function(){ if (div.parentNode) div.parentNode.removeChild(div); }, 400);
    }, 3000);
  }

  // ---------- Build HTML helpers ----------
  function buildChipHTML(a) {
    var e = empById(a.employee_id);
    if (!e) return '';
    var sh = shiftByKey(a.shift_row);
    var color = (a.shift_row === 'Off') ? '#e74a3b' : ((sh && sh.color) || '#4e73df');
    var branch = (a.branch === 'Branch 1' || a.branch === 'Branch 2') ? a.branch : 'Branch 1';
    var branchColor = branch === 'Branch 2' ? '#6f42c1' : '#ca7d2a';
    var branchShort = branch === 'Branch 2' ? 'B2' : 'B1';
    var tip = (a.shift_label || a.shift_row) + ' • ' + branch + ' • ' + time12(a.shift_in) + ' - ' + time12(a.shift_out);
    if (a.notes) tip += ' — ' + a.notes;
    var label = (e.first_name || '') + ' ' + ((e.last_name || '').charAt(0)).toUpperCase() + '.';
    return '<span class="bb-chip d-inline-block m-1 px-2 py-1 rounded text-white text-xs font-weight-bold"'
      + ' style="background:' + color + ';border:1px solid rgba(0,0,0,.08);cursor:grab;"'
      + ' draggable="true"'
      + ' data-emp="' + e.id + '"'
      + ' data-dow="' + a.day_of_week + '"'
      + ' data-row="' + a.shift_row + '"'
      + ' data-branch="' + branch + '"'
      + ' title="' + tip.replace(/"/g, '&quot;').replace(/</g,'&lt;') + '">'
      + '<i class="fas fa-grip-horizontal opacity-60 mr-1"></i>' + label
      + ' <small class="ml-1 px-1 rounded" style="background:' + branchColor + ';">' + branchShort + '</small>'
      + '</span>';
  }

  // ---------- Assignment mutation ----------
  function removeFromDay(dow, empId) {
    for (var key in state.assignments) {
      if (!Object.prototype.hasOwnProperty.call(state.assignments, key)) continue;
      var parts = key.split('|');
      if (parts[0] !== String(dow)) continue;
      var list = state.assignments[key];
      state.assignments[key] = list.filter(function (x) { return String(x.employee_id) !== String(empId); });
    }
  }
  function addAssignment(dow, row, empId, extras) {
    extras = extras || {};
    var cellK = cellKey(dow, row);
    if (!state.assignments[cellK]) state.assignments[cellK] = [];
    var br = (extras.branch === 'Branch 1' || extras.branch === 'Branch 2') ? extras.branch : state.default_branch;
    var dup = false;
    for (var di = 0; di < state.assignments[cellK].length; di++) {
      var item = state.assignments[cellK][di];
      if (String(item.employee_id) === String(empId) && item.branch === br) {
        dup = true;
        break;
      }
    }
    if (dup) return; // already in this exact cell with same branch
    var s = shiftByKey(row) || {};
    var entry = {
      employee_id: parseInt(empId, 10),
      day_of_week: parseInt(dow, 10),
      shift_row: row,
      branch: br,
      shift_label: extras.shift_label || row,
      shift_in: extras.shift_in  || s.start_time || '',
      shift_out: extras.shift_out || s.end_time   || '',
      notes: extras.notes || ''
    };
    state.assignments[cellK].push(entry);
  }
  function removeAssignment(dow, row, empId, branch) {
    var k = cellKey(dow, row);
    if (!state.assignments[k]) return;
    state.assignments[k] = state.assignments[k].filter(function (x) {
      if (branch) {
        return !(String(x.employee_id) === String(empId) && x.branch === branch);
      }
      return String(x.employee_id) !== String(empId);
    });
  }
  function findAssignment(dow, row, empId, branch) {
    var k = cellKey(dow, row);
    var list = state.assignments[k] || [];
    for (var i = 0; i < list.length; i++) {
      if (String(list[i].employee_id) === String(empId)) {
        if (!branch || list[i].branch === branch) return list[i];
      }
    }
    return null;
  }

  // ---------- Render ----------
  function applyPoolDimmedState() {
    // Dim employees that are already assigned somewhere for this day's slot
    var cards = document.querySelectorAll('.bb-emp-card');
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i];
      var empId = c.getAttribute('data-emp');
      // If selected → highlight
      if (String(state.selected_emp_id) === String(empId)) {
        c.classList.add('bb-emp-selected');
        c.style.boxShadow = '0 0 0 3px rgba(28,200,138,0.4)';
      } else {
        c.classList.remove('bb-emp-selected');
        if (!c.getAttribute('style-orig')) c.setAttribute('style-orig', c.getAttribute('style') || '');
        c.style.boxShadow = '';
      }
      // If already assigned somewhere this week → slightly dimmed
      var assigned = false;
      for (var k in state.assignments) {
        if (!Object.prototype.hasOwnProperty.call(state.assignments, k)) continue;
        var list = state.assignments[k] || [];
        for (var j = 0; j < list.length; j++) {
          if (String(list[j].employee_id) === String(empId)) { assigned = true; break; }
        }
        if (assigned) break;
      }
      c.style.opacity = assigned ? '0.55' : '1';
    }
    // Update "click mode" hint
    var hint = document.getElementById('bb-assign-hint');
    if (hint) {
      var se = state.selected_emp_id ? empById(state.selected_emp_id) : null;
      if (se) {
        var name = (se.first_name || '') + ' ' + (se.last_name || '');
        hint.innerHTML = '<i class="fas fa-check-circle text-success mr-1"></i>Selected: <b class="text-success">' + name + '</b> — now CLICK any cell below to assign, or click again here to deselect.';
      } else {
        var sr = state.selected_row_key;
        var rowName = sr || '';
        if (sr === 'Off') {
          hint.innerHTML = '<i class="fas fa-umbrella-beach text-danger mr-1"></i><b>Rest Day</b> mode. Click a green-highlighted employee or drag them to any cell.';
        } else if (sr) {
          hint.innerHTML = '<i class="fas fa-clipboard-list text-primary mr-1"></i>Preset selected: <b>' + rowName + '</b>. Click a green employee then click a cell, or drag employees.';
        } else {
          hint.innerHTML = '<i class="fas fa-lightbulb text-info mr-1"></i>Click employee on the left to select, then click any slot. Or just drag & drop cards.';
        }
      }
    }
  }

  function renderGrid() {
    var tbody = document.getElementById('bb-grid-body');
    if (!tbody) return;
    // Clear existing chips first (but keep cell DOM, just clear contents)
    var cells = tbody.querySelectorAll('td.bb-cell');
    for (var i = 0; i < cells.length; i++) {
      var cell = cells[i];
      var chips = cell.querySelectorAll('.bb-chip');
      for (var k = 0; k < chips.length; k++) if (chips[k].parentNode) chips[k].parentNode.removeChild(chips[k]);
      var empty = cell.querySelector('.bb-empty-msg');
      if (empty) if (empty.parentNode) empty.parentNode.removeChild(empty);
    }

    // Fill assignments + empty hint
    for (var j = 0; j < cells.length; j++) {
      var c2 = cells[j];
      var dow = c2.getAttribute('data-dow');
      var row = c2.getAttribute('data-row');
      var k2 = cellKey(dow, row);
      var list = state.assignments[k2] || [];
      // Filter list based on state.branch_filter
      var visibleList = [];
      for (var vi = 0; vi < list.length; vi++) {
        if (!state.branch_filter || state.branch_filter === 'all' || list[vi].branch === state.branch_filter) {
          visibleList.push(list[vi]);
        }
      }
      // Add chips
      var wrapper = document.createElement('div');
      wrapper.className = 'text-left';
      for (var l = 0; l < visibleList.length; l++) {
        wrapper.insertAdjacentHTML('beforeend', buildChipHTML(visibleList[l]));
      }
      if (visibleList.length === 0) {
        var emp = document.createElement('div');
        emp.className = 'bb-empty-msg text-xs text-gray-300 pt-2 px-2';
        var bHint = (state.branch_filter && state.branch_filter !== 'all') ? (' (' + state.branch_filter + ')') : '';
        emp.innerHTML = '<i class="fas fa-plus mr-1"></i>Click here' + bHint;
        c2.appendChild(emp);
      } else {
        c2.appendChild(wrapper);
      }
    }
  }

  function updateCountBadge() {
    var totalCnt = 0;
    var b1Cnt = 0;
    var b2Cnt = 0;
    for (var k in state.assignments) {
      if (!Object.prototype.hasOwnProperty.call(state.assignments, k)) continue;
      var list = state.assignments[k] || [];
      for (var i = 0; i < list.length; i++) {
        totalCnt++;
        if (list[i].branch === 'Branch 2') b2Cnt++;
        else b1Cnt++;
      }
    }
    var el = document.getElementById('bb-count-assigned');
    if (el) {
      if (state.branch_filter === 'Branch 1') {
        el.textContent = b1Cnt + ' assigned in Branch 1';
      } else if (state.branch_filter === 'Branch 2') {
        el.textContent = b2Cnt + ' assigned in Branch 2';
      } else {
        el.textContent = totalCnt + ' total (B1: ' + b1Cnt + ' • B2: ' + b2Cnt + ')';
      }
    }
  }
  function renderAll() {
    renderGrid();
    applyPoolDimmedState();
    updateCountBadge();
    // Wire up drag events on newly-added chips
    wireChipEvents();
  }

  // ---------- Event Wiring ----------
  function openEditModal(dow, row, empId, branch) {
    var e = empById(empId);
    if (!e) return;
    var a = findAssignment(dow, row, empId, branch);
    if (!a) return;
    var dayLabel = DAY_HDRS && DAY_HDRS[parseInt(dow,10) - 1] ? (DAY_HDRS[parseInt(dow,10)-1].label + ' ' + DAY_HDRS[parseInt(dow,10)-1].sub) : ('Day ' + dow);
    var curBranch = (a.branch === 'Branch 1' || a.branch === 'Branch 2') ? a.branch : 'Branch 1';
    document.getElementById('bb-edit-title').textContent =
      (e.first_name || '') + ' ' + (e.last_name || '') + ' — ' + dayLabel;
    document.getElementById('bb-edit-empid').value = empId;
    document.getElementById('bb-edit-dow').value = dow;
    document.getElementById('bb-edit-row').value = row;
    var origBrEl = document.getElementById('bb-edit-orig-branch');
    if (origBrEl) origBrEl.value = curBranch;
    document.getElementById('bb-edit-label').value = a.shift_label || row;
    document.getElementById('bb-edit-branch').value = curBranch;
    document.getElementById('bb-edit-in').value = a.shift_in || '';
    document.getElementById('bb-edit-out').value = a.shift_out || '';
    document.getElementById('bb-edit-notes').value = a.notes || '';
    // Show modal (Bootstrap 4)
    if (window.jQuery) { jQuery('#bb-edit-modal').modal('show'); }
    else { document.getElementById('bb-edit-modal').style.display = 'block'; document.getElementById('bb-edit-modal').classList.add('show'); }
  }
  function closeEditModal() {
    if (window.jQuery) { jQuery('#bb-edit-modal').modal('hide'); }
    else { document.getElementById('bb-edit-modal').style.display = 'none'; document.getElementById('bb-edit-modal').classList.remove('show'); }
  }

  function wirePoolCardEvents() {
    // ---------- POOL: Drag start --------------------------
    document.body.addEventListener('dragstart', function (ev) {
      var card = ev.target.closest('.bb-emp-card');
      if (!card) return;
      var empId = card.getAttribute('data-emp');
      state.drag = { source: 'pool', empId: parseInt(empId, 10) };
      try {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', 'emp:' + empId);
        // Use a nice drag image (the card itself)
        try { ev.dataTransfer.setDragImage(card, 20, 20); } catch (e) {}
      } catch (e) {}
    }, false);
    document.body.addEventListener('dragend', function () {
      state.drag = null;
      clearDragHighlights();
    }, false);

    // ---------- POOL: Click to select (toggle) ------------
    var justDraggedPool = 0;
    document.body.addEventListener('dragstart', function (ev) {
      if (ev.target.closest('.bb-emp-card')) justDraggedPool = Date.now();
    });
    document.body.addEventListener('click', function (ev) {
      var card = ev.target.closest('.bb-emp-card');
      if (!card) return;
      // Guard: if this click came within 300ms after drag, ignore (Firefox fires click after drag)
      if (justDraggedPool && (Date.now() - justDraggedPool < 350)) {
        justDraggedPool = 0;
        ev.preventDefault();
        ev.stopPropagation();
        return false;
      }
      var empId = card.getAttribute('data-emp');
      if (String(state.selected_emp_id) === String(empId)) {
        state.selected_emp_id = null;
      } else {
        state.selected_emp_id = parseInt(empId, 10);
      }
      applyPoolDimmedState();
      ev.preventDefault();
      ev.stopPropagation();
      return false;
    }, false);
  }

  function clearDragHighlights() {
    var hs = document.querySelectorAll('.bb-drag-over');
    for (var i = 0; i < hs.length; i++) hs[i].classList.remove('bb-drag-over');
  }

  function findCellFromEvent(ev) {
    // Walk up from target until we find a .bb-cell td, or null
    var el = ev.target;
    while (el && el !== document.body) {
      if (el.classList && el.classList.contains('bb-cell')) return el;
      el = el.parentNode;
    }
    return null;
  }

  function wireCellEvents() {
    // ---------- DRAGOVER (fires on ANY element inside the body) ---
    document.body.addEventListener('dragover', function (ev) {
      var cell = findCellFromEvent(ev);
      if (!cell) return;  // not a drop target

      ev.preventDefault();
      ev.stopPropagation();
      try {
        ev.dataTransfer.dropEffect = 'move';
        ev.dataTransfer.effectAllowed = 'move';
      } catch (e) {}
      // Only this cell should have the blue highlight
      clearDragHighlights();
      cell.classList.add('bb-drag-over');
    }, false);

    // ---------- DRAGLEAVE: remove highlight WHEN leaving the window / cell container
    document.body.addEventListener('dragleave', function (ev) {
      // Only clear if we're leaving to outside the cell subtree
      // (prevent flicker when moving between children of cell)
      var cell = findCellFromEvent(ev);
      if (!cell) return;
      var related = ev.relatedTarget;
      if (related && cell.contains(related)) return;
      cell.classList.remove('bb-drag-over');
    }, false);

    // Document-level drop guard (prevent default to stop browser from navigating)
    window.addEventListener('dragover', function (ev) { ev.preventDefault(); }, false);
    window.addEventListener('drop', function (ev) { ev.preventDefault(); }, false);

    // ---------- DROP on CELL -------------------------------------------------
    document.body.addEventListener('drop', function (ev) {
      var cell = findCellFromEvent(ev);
      if (!cell) return;
      ev.preventDefault();
      ev.stopPropagation();
      clearDragHighlights();

      if (!state.drag || !state.drag.empId) return;

      var dow = cell.getAttribute('data-dow');
      var row = cell.getAttribute('data-row');
      if (!dow || !row) return;

      if (state.drag.source !== 'pool') {
        // Move from another cell:
        // - IF TARGET IS SAME DAY BUT DIFFERENT SHIFT ROW  → COPY (keep source so employee appears in BOTH morning + afternoon on same day = double shift)
        // - IF DIFFERENT DAY → MOVE (remove from source, because can't be Monday+Tuesday same row by drag, intended move)
        // - IF EXACT SAME CELL → no-op (dedupe handled in addAssignment anyway)
        var sDow = state.drag.dow;
        var sRow = state.drag.row;
        if (String(sDow) !== String(dow)) {
          // Different day → treat as normal MOVE (remove from source)
          if (String(sRow) !== String(row)) {
            removeAssignment(sDow, sRow, state.drag.empId);
          }
        } else if (String(sDow) === String(dow) && String(sRow) === String(row)) {
          // Same cell drag-and-drop on self: no-op
        }
        // else: SAME DAY, DIFFERENT shift row → INTENTIONAL COPY for double-shift (don't delete source!)
      }
      var extra = { shift_label: state.selected_row_key || row };
      addAssignment(dow, row, state.drag.empId, extra);
      state.drag = null;
      renderAll();
      return false;
    }, false);

    // ---------- CLICK CELL: assign if employee is selected --------------
    var justDroppedAt = 0;
    document.body.addEventListener('drop', function () { justDroppedAt = Date.now(); });
    document.body.addEventListener('click', function (ev) {
      var cell = findCellFromEvent(ev);
      if (!cell) return;
      // If a chip was clicked: handled separately (stopPropagation already called)
      if (ev.target.closest('.bb-chip')) return;
      // Guard: don't fire click after drop (Firefox quirk)
      if (justDroppedAt && (Date.now() - justDroppedAt < 350)) return;

      var dow = cell.getAttribute('data-dow');
      var row = cell.getAttribute('data-row');

      if (state.selected_emp_id) {
        addAssignment(dow, row, state.selected_emp_id, {
          shift_label: state.selected_row_key || row
        });
        renderAll();
        ev.preventDefault();
        ev.stopPropagation();
        return false;
      }
      // No employee selected → gentle toast
      showToast('<i class="fas fa-info-circle mr-1"></i>Click an employee on the left first (turns green), then click this slot.', 'info');
    }, false);
  }

  function wireChipEvents() {
    // Chips are inside cells; drag from cell → cell, or click to edit modal
    var justDraggedChip = 0;
    document.body.addEventListener('dragstart', function (ev) {
      var chip = ev.target.closest('.bb-chip');
      if (!chip) return;
      var empId = chip.getAttribute('data-emp');
      var dow = chip.getAttribute('data-dow');
      var row = chip.getAttribute('data-row');
      state.drag = {
        source: 'grid',
        empId: parseInt(empId, 10),
        dow: parseInt(dow, 10),
        row: row
      };
      try {
        ev.dataTransfer.effectAllowed = 'move';
        ev.dataTransfer.setData('text/plain', 'emp:' + empId);
        try { ev.dataTransfer.setDragImage(chip, 20, 12); } catch (e) {}
      } catch (e) {}
      justDraggedChip = Date.now();
      ev.stopPropagation();
    }, false);

    document.body.addEventListener('click', function (ev) {
      var chip = ev.target.closest('.bb-chip');
      if (!chip) return;
      // Guard: don't open modal right after a drag
      if (justDraggedChip && (Date.now() - justDraggedChip < 350)) {
        justDraggedChip = 0;
        ev.preventDefault();
        ev.stopPropagation();
        return false;
      }
      var empId = chip.getAttribute('data-emp');
      var dow = chip.getAttribute('data-dow');
      var row = chip.getAttribute('data-row');
      var branch = chip.getAttribute('data-branch') || 'Branch 1';
      ev.preventDefault();
      ev.stopPropagation();
      openEditModal(dow, row, empId, branch);
      return false;
    }, false);
  }

  function wirePresetButtons() {
    var btns = document.querySelectorAll('.bb-preset');
    for (var i = 0; i < btns.length; i++) {
      (function (b) {
        b.addEventListener('click', function () {
          var k = b.getAttribute('data-key');
          // Toggle
          if (state.selected_row_key === k) {
            state.selected_row_key = null;
            b.style.boxShadow = '';
            b.style.opacity = '0.85';
          } else {
            state.selected_row_key = k;
            // Unselect all others
            document.querySelectorAll('.bb-preset').forEach(function(x){ x.style.boxShadow=''; x.style.opacity='0.85'; });
            b.style.boxShadow = '0 0 0 3px rgba(78,115,223,0.25)';
            b.style.opacity = '1';
          }
          applyPoolDimmedState();
        });
      })(btns[i]);
    }
    // Branch presets — switch view filter & default branch (NEVER CLEAR ASSIGNMENTS!)
    var brBtns = document.querySelectorAll('.bb-branch-preset');
    for (var j = 0; j < brBtns.length; j++) {
      (function (bb) {
        var brVal = bb.getAttribute('data-branch');
        if (brVal === state.branch_filter) {
          bb.style.boxShadow = '0 0 0 3px rgba(0,0,0,.22)';
          bb.style.opacity = '1';
        } else {
          bb.style.boxShadow = '';
          bb.style.opacity = '0.75';
        }
        function onBranchActivate(e) {
          var v = bb.getAttribute('data-branch');
          if (v === 'Branch 1' || v === 'Branch 2') {
            state.default_branch = v;
            state.branch_filter = v;
          } else if (v === 'all') {
            state.branch_filter = 'all';
          }
          // Highlight active button
          var all = document.querySelectorAll('.bb-branch-preset');
          for (var k = 0; k < all.length; k++) {
            var bVal = all[k].getAttribute('data-branch');
            if (bVal === state.branch_filter) {
              all[k].style.boxShadow = '0 0 0 3px rgba(0,0,0,.25)';
              all[k].style.opacity = '1';
            } else {
              all[k].style.boxShadow = '';
              all[k].style.opacity = '0.75';
            }
          }
          renderAll();
          var toastMsg = (state.branch_filter === 'all')
            ? '<i class="fas fa-layer-group mr-1"></i>Showing <b>All Branches</b> schedule.'
            : '<i class="fas fa-store mr-1"></i>Showing <b>' + state.branch_filter + '</b> schedule (other branch shifts are safely preserved!).';
          showToast(toastMsg, 'info');
          if (e) {
            try { e.preventDefault(); } catch(_){}
            try { e.stopPropagation(); } catch(_){}
          }
        }
        bb.addEventListener('click', onBranchActivate);
        bb.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' || e.key === ' ') { onBranchActivate(e); }
        });
      })(brBtns[j]);
    }
  }

  function wireButtons() {
    // Save Draft
    var saveBtn = document.getElementById('bb-save-draft');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () { doSave('save', saveBtn, '<i class="fas fa-save mr-1"></i>Save Draft'); });
    }
    // Publish
    var pubBtn = document.getElementById('bb-publish');
    if (pubBtn) {
      pubBtn.addEventListener('click', function () {
        var cnt = 0;
        for (var k in state.assignments) {
          if (!Object.prototype.hasOwnProperty.call(state.assignments, k)) continue;
          cnt += (state.assignments[k] || []).length;
        }
        var msg;
        if (cnt === 0) msg = 'No assignments yet. Publish an EMPTY schedule and notify all staff?';
        else msg = 'Publish this schedule and NOTIFY staff now? Staff will see their shift in their accounts immediately.';
        if (!window.confirm(msg)) return;
        doSave('publish', pubBtn, '<i class="fas fa-paper-plane mr-1"></i>Publish & Notify Staff');
      });
    }
    // Edit modal save
    var eSave = document.getElementById('bb-edit-save');
    if (eSave) {
      eSave.addEventListener('click', function () {
        var empId = document.getElementById('bb-edit-empid').value;
        var dow   = document.getElementById('bb-edit-dow').value;
        var row   = document.getElementById('bb-edit-row').value;
        var origBrEl = document.getElementById('bb-edit-orig-branch');
        var origBranch = origBrEl ? origBrEl.value : null;
        var a = findAssignment(dow, row, empId, origBranch);
        if (a) {
          a.shift_label = document.getElementById('bb-edit-label').value || row;
          var br = document.getElementById('bb-edit-branch').value;
          a.branch = (br === 'Branch 1' || br === 'Branch 2') ? br : 'Branch 1';
          a.shift_in    = document.getElementById('bb-edit-in').value;
          a.shift_out   = document.getElementById('bb-edit-out').value;
          a.notes       = document.getElementById('bb-edit-notes').value;
        }
        renderAll();
        closeEditModal();
      });
    }
    var eDel = document.getElementById('bb-edit-delete');
    if (eDel) {
      eDel.addEventListener('click', function () {
        var empId = document.getElementById('bb-edit-empid').value;
        var dow   = document.getElementById('bb-edit-dow').value;
        var row   = document.getElementById('bb-edit-row').value;
        var origBrEl = document.getElementById('bb-edit-orig-branch');
        var origBranch = origBrEl ? origBrEl.value : null;
        removeAssignment(dow, row, empId, origBranch);
        renderAll();
        closeEditModal();
      });
    }
  }

  function serializeFlat() {
    var flat = [];
    for (var k in state.assignments) {
      if (!Object.prototype.hasOwnProperty.call(state.assignments, k)) continue;
      var list = state.assignments[k] || [];
      for (var i = 0; i < list.length; i++) flat.push(list[i]);
    }
    return flat;
  }

  function doSave(action, btn, originalHTML) {
    btn.disabled = true;
    var oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving…';
    var payload = { action: action, data: JSON.stringify(serializeFlat()) };
    // Build x-www-form-urlencoded body
    var body = Object.keys(payload).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(payload[k]);
    }).join('&');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', SAVE_URL, true);
    xhr.setRequestHeader('Content-Type','application/x-www-form-urlencoded; charset=UTF-8');
    xhr.onload = function () {
      btn.disabled = false;
      btn.innerHTML = originalHTML || oldHtml;
      try {
        var resp = JSON.parse(xhr.responseText);
        if (resp && resp.ok) {
          showToast(resp.msg, resp.published ? 'success' : 'info');
          try {
            // Reset dismissed banner state for current week (so banner re-appears after reload as visual confirmation)
            var ws = (typeof window.SHIFT_APP_DATA !== 'undefined' && window.SHIFT_APP_DATA && window.SHIFT_APP_DATA.week_start)
              ? window.SHIFT_APP_DATA.week_start
              : ((typeof window.WEEK_START !== 'undefined') ? window.WEEK_START : null);
            if (ws) {
              window.localStorage.removeItem('bb-shift-banner-' + ws + '-published');
              window.localStorage.removeItem('bb-shift-banner-' + ws + '-draft');
              window.localStorage.removeItem('bb-shift-banner-tip');
            }
          } catch(e) {}
          setTimeout(function(){ window.location.reload(); }, resp.published ? 900 : 1500);
        } else {
          showToast(resp.msg || 'Save failed.', 'danger');
        }
      } catch (err) {
        showToast('Server error. Please try again.', 'danger');
      }
    };
    xhr.onerror = function () {
      btn.disabled = false;
      btn.innerHTML = originalHTML || oldHtml;
      showToast('Network error. Check connection.', 'danger');
    };
    xhr.send(body);
  }

  // ---------- Seed initial assignments from PHP ----------
  function seedInitial() {
    for (var i = 0; i < INITIAL.length; i++) {
      var a = INITIAL[i];
      var rowKey = a.shift_row || a.shift_label || 'Mid';
      if (!rowKey) rowKey = 'Mid';
      a.shift_row = rowKey;
      a.shift_label = a.shift_label || rowKey;
      var k = cellKey(a.day_of_week, rowKey);
      if (!state.assignments[k]) state.assignments[k] = [];
      state.assignments[k].push(a);
    }
  }

  // ---------- Boot ----------
  function boot() {
    // Defensive: re-read SHIFT_APP_DATA from window NOW (DOM is ready) regardless of script tag parse order
    ensureDataFromWindow();
    // Quick validation
    if (!EMPLOYEES.length && !SHIFT_ROWS.length && !DAY_HDRS.length) {
      // Nothing to render; PHP already showed the warnings
      return;
    }
    seedInitial();
    wirePoolCardEvents();
    wireCellEvents();
    wirePresetButtons();
    wireButtons();
    wireChipEvents();
    renderAll();
  }

  // Try boot when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
