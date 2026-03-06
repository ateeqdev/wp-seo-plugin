(function () {
  "use strict";

  // ─── 1 & 3. Chip Filter Bar (Change Center + reusable) ─────────────────────
  function initChipFilterBar(barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;

    var form = bar.querySelector(".seoauto-filter-form");
    if (!form) return;

    // Build label maps from data attribute on bar
    var labelMaps = {};
    try {
      labelMaps = JSON.parse(bar.getAttribute("data-label-maps") || "{}");
    } catch (e) {
      console.error("Failed to parse label maps:", e);
    }

    function esc(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function getCheckedValues() {
      var result = {};
      bar
        .querySelectorAll(".seoauto-filter-option input[type=checkbox]:checked")
        .forEach(function (cb) {
          var dropdown = cb.closest("[data-filter-key]");
          if (!dropdown) return;
          var key = dropdown.getAttribute("data-filter-key");
          if (!result[key]) result[key] = [];
          result[key].push(cb.value);
        });
      return result;
    }

    function refreshChips() {
      var chipRow = bar.querySelector(".seoauto-active-chips");
      if (!chipRow) return;
      var checked = getCheckedValues();
      var html = "";
      Object.keys(checked).forEach(function (key) {
        checked[key].forEach(function (val) {
          var label =
            labelMaps[key] && labelMaps[key][val] ? labelMaps[key][val] : val;
          html +=
            '<span class="seoauto-active-chip">' +
            "<span>" +
            esc(label) +
            "</span>" +
            '<button type="button" data-chip-key="' +
            esc(key) +
            '" data-chip-val="' +
            esc(val) +
            '" aria-label="Remove">×</button>' +
            "</span>";
        });
      });
      chipRow.innerHTML = html;
      // Update filter btn badge counts
      bar.querySelectorAll(".seoauto-filter-dropdown").forEach(function (dd) {
        var key = dd.getAttribute("data-filter-key");
        var vals = checked[key] || [];
        var btn = dd.querySelector(".seoauto-filter-btn");
        if (!btn) return;
        btn.classList.toggle("has-active", vals.length > 0);
        var countEl = btn.querySelector(".seoauto-filter-count");
        if (vals.length > 0) {
          if (!countEl) {
            countEl = document.createElement("span");
            countEl.className = "seoauto-filter-count";
            var chevron = btn.querySelector(".seoauto-filter-chevron");
            btn.insertBefore(countEl, chevron);
          }
          countEl.textContent = vals.length;
        } else if (countEl) {
          countEl.remove();
        }
      });
    }

    function submitForm() {
      // Before submitting, sync checkboxes → hidden inputs
      // Remove all existing hidden filter inputs
      var hiddenContainer = bar.querySelector(".seoauto-filter-hidden-inputs");
      if (hiddenContainer) hiddenContainer.innerHTML = "";
      var checked = getCheckedValues();
      Object.keys(checked).forEach(function (key) {
        checked[key].forEach(function (val) {
          var inp = document.createElement("input");
          inp.type = "hidden";
          inp.name = key + "[]";
          inp.value = val;
          if (hiddenContainer) hiddenContainer.appendChild(inp);
        });
      });
      form.submit();
    }

    // Toggle panels
    bar.addEventListener("click", function (e) {
      // Filter button toggle
      var filterBtn = e.target.closest(".seoauto-filter-btn");
      if (filterBtn) {
        e.preventDefault();
        var dropdown = filterBtn.closest(".seoauto-filter-dropdown");
        var panel = dropdown
          ? dropdown.querySelector(".seoauto-filter-panel")
          : null;
        var allPanels = bar.querySelectorAll(".seoauto-filter-panel");
        allPanels.forEach(function (p) {
          if (p !== panel) p.style.display = "none";
        });
        if (panel) {
          panel.style.display =
            panel.style.display === "none" || panel.style.display === ""
              ? "block"
              : "none";
        }
        return;
      }

      // Chip removal
      var chipBtn = e.target.closest("[data-chip-key]");
      if (chipBtn) {
        e.preventDefault();
        var key = chipBtn.getAttribute("data-chip-key");
        var val = chipBtn.getAttribute("data-chip-val");
        // Uncheck the matching checkbox
        var selector =
          'input[name="' +
          key +
          '[]"][value="' +
          val.replace(/"/g, '\\"') +
          '"]';
        bar.querySelectorAll(selector).forEach(function (cb) {
          cb.checked = false;
        });
        submitForm();
        return;
      }

      // Clear-one
      var clrBtn = e.target.closest(".seoauto-filter-clear-one");
      if (clrBtn) {
        e.preventDefault();
        var ckey = clrBtn.getAttribute("data-filter-key");
        bar
          .querySelectorAll('input[name="' + ckey + '[]"]')
          .forEach(function (cb) {
            cb.checked = false;
          });
        refreshChips();
        return;
      }

      // Checkbox change → refresh chips
      if (e.target.type === "checkbox") {
        refreshChips();
        return;
      }

      // Close on outside click
      if (!e.target.closest(".seoauto-filter-dropdown")) {
        bar.querySelectorAll(".seoauto-filter-panel").forEach(function (p) {
          p.style.display = "none";
        });
      }
    });

    // Apply button submits the form
    bar.querySelectorAll(".seoauto-filter-apply").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        submitForm();
      });
    });

    // Post search filter inside panel
    bar.querySelectorAll(".seoauto-filter-post-search").forEach(function (inp) {
      inp.addEventListener("input", function () {
        var q = inp.value.toLowerCase().trim();
        inp
          .closest(".seoauto-filter-panel")
          .querySelectorAll(".seoauto-filter-option")
          .forEach(function (opt) {
            var lbl = (
              opt.getAttribute("data-label") || opt.textContent
            ).toLowerCase();
            opt.style.display = !q || lbl.includes(q) ? "" : "none";
          });
      });
    });

    // Init chips on load
    refreshChips();
  }

  // Init all chip filter bars on the page after DOM is ready
  function initAllFilterBars() {
    document
      .querySelectorAll(".seoauto-chip-filter-bar")
      .forEach(function (bar) {
        if (bar.id) initChipFilterBar(bar.id);
      });
  }

  // Run on DOM ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAllFilterBars);
  } else {
    initAllFilterBars();
  }

  // ─── 2. Progression Timeline Toggle ────────────────────────────────────────
  // Use document-level delegation so it works regardless of bar scope
  document.addEventListener("click", function (e) {
    var toggler = e.target.closest(".seoauto-progression-toggle");
    if (!toggler) return;
    e.stopPropagation();
    var targetId = toggler.getAttribute("data-target");
    var subRow = targetId ? document.getElementById(targetId) : null;
    if (!subRow) return;
    var isOpen = subRow.style.display === "table-row";
    subRow.style.display = isOpen ? "none" : "table-row";
    var arrow = toggler.querySelector(".seoauto-prog-arrow");
    if (arrow) arrow.textContent = isOpen ? "▸" : "▾";
  });

  // ─── "Currently applied" toggle ────────────────────────────────────────────
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".seoauto-toggle-current");
    if (!btn) return;
    var panel = btn.nextElementSibling;
    if (!panel) return;
    var vis = panel.style.display !== "none";
    panel.style.display = vis ? "none" : "block";
    btn.textContent = vis ? "▸ Currently applied" : "▴ Currently applied";
  });

  // ─── 5. Inline Edit in Proposed-Change Column ──────────────────────────────
  // Edit/Cancel toggle for inline edit panels embedded in the proposed-change td
  document.addEventListener("click", function (e) {
    var isEdit = e.target.getAttribute("data-seoauto-edit-toggle") === "1";
    var isCancel = e.target.getAttribute("data-seoauto-cancel-button") === "1";
    if (!isEdit && !isCancel) return;

    var container = e.target.closest(".seoauto-inline-edit-container");
    if (!container) return;

    var displayView = container.querySelector(".seoauto-inline-display");
    var editView = container.querySelector(".seoauto-inline-edit-fields");
    var editBtn = container.querySelector('[data-seoauto-edit-toggle="1"]');
    var saveBtn = container.querySelector('[data-seoauto-save-button="1"]');
    var cancelBtn = container.querySelector('[data-seoauto-cancel-button="1"]');

    if (isEdit) {
      if (displayView) displayView.style.display = "none";
      if (editView) editView.style.display = "block";
      if (editBtn) editBtn.style.display = "none";
      if (saveBtn) saveBtn.style.display = "inline-flex";
      if (cancelBtn) cancelBtn.style.display = "inline-flex";
    } else {
      if (displayView) displayView.style.display = "block";
      if (editView) editView.style.display = "none";
      if (editBtn) editBtn.style.display = "inline-flex";
      if (saveBtn) saveBtn.style.display = "none";
      if (cancelBtn) cancelBtn.style.display = "none";
    }
  });

  // ─── 4. Excluded Pages Tag-Chip UI ─────────────────────────────────────────
  function initExclusionTagUI() {
    var container = document.getElementById("seoauto-exclusion-tag-ui");
    if (!container) return;

    var searchInp = container.querySelector(".seoauto-excl-search");
    var dropdown = container.querySelector(".seoauto-excl-dropdown");
    var chipsWrap = container.querySelector(".seoauto-excl-chips");
    var hiddenInp = document.getElementById("seoauto-exclusion-hidden");
    var allOptions = container.querySelectorAll(".seoauto-excl-option");

    // Parse current excluded IDs from hidden input
    var selectedIds = {};
    if (hiddenInp && hiddenInp.value.trim()) {
      hiddenInp.value
        .trim()
        .split("\n")
        .forEach(function (id) {
          id = id.trim();
          if (id) selectedIds[id] = true;
        });
    }

    function getLabel(id) {
      var opt = container.querySelector(
        '.seoauto-excl-option[data-id="' + id + '"]',
      );
      return opt ? opt.textContent.trim() : id;
    }

    function syncHidden() {
      if (hiddenInp) hiddenInp.value = Object.keys(selectedIds).join("\n");
    }

    function renderChips() {
      if (!chipsWrap) return;
      chipsWrap.innerHTML = "";
      Object.keys(selectedIds).forEach(function (id) {
        var chip = document.createElement("span");
        chip.className = "seoauto-active-chip";
        chip.innerHTML =
          "<span>" +
          escHtml(getLabel(id)) +
          "</span>" +
          '<button type="button" data-excl-remove="' +
          escHtml(id) +
          '" aria-label="Remove">×</button>';
        chipsWrap.appendChild(chip);
      });
    }

    function escHtml(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function filterDropdown(q) {
      allOptions.forEach(function (opt) {
        var lbl = (opt.getAttribute("data-label") || "").toLowerCase();
        opt.style.display = !q || lbl.includes(q) ? "" : "none";
      });
    }

    // Pre-select options
    allOptions.forEach(function (opt) {
      var id = opt.getAttribute("data-id");
      opt.classList.toggle("is-selected", !!selectedIds[id]);
      var checkmark = opt.querySelector(".seoauto-excl-checkmark");
      if (checkmark) checkmark.textContent = selectedIds[id] ? "✓" : "";
    });

    renderChips();

    if (searchInp) {
      searchInp.addEventListener("focus", function () {
        if (dropdown) dropdown.style.display = "block";
      });
      searchInp.addEventListener("input", function () {
        filterDropdown(searchInp.value.toLowerCase().trim());
        if (dropdown) dropdown.style.display = "block";
      });
      // Close on outside click
      document.addEventListener("click", function (e) {
        if (!container.contains(e.target)) {
          if (dropdown) dropdown.style.display = "none";
        }
      });
    }

    container.addEventListener("click", function (e) {
      // Option click → toggle selection
      var opt = e.target.closest(".seoauto-excl-option");
      if (opt) {
        var id = opt.getAttribute("data-id");
        var checkmark = opt.querySelector(".seoauto-excl-checkmark");
        if (selectedIds[id]) {
          delete selectedIds[id];
          opt.classList.remove("is-selected");
          if (checkmark) checkmark.textContent = "";
        } else {
          selectedIds[id] = true;
          opt.classList.add("is-selected");
          if (checkmark) checkmark.textContent = "✓";
        }
        syncHidden();
        renderChips();
        return;
      }
      // Chip remove
      var rmBtn = e.target.closest("[data-excl-remove]");
      if (rmBtn) {
        var rid = rmBtn.getAttribute("data-excl-remove");
        delete selectedIds[rid];
        var matchOpt = container.querySelector(
          '.seoauto-excl-option[data-id="' + rid + '"]',
        );
        if (matchOpt) {
          matchOpt.classList.remove("is-selected");
          var checkmark = matchOpt.querySelector(".seoauto-excl-checkmark");
          if (checkmark) checkmark.textContent = "";
        }
        syncHidden();
        renderChips();
        return;
      }
    });

    // Sync before form submit
    var form = hiddenInp ? hiddenInp.closest("form") : null;
    if (form) {
      form.addEventListener("submit", syncHidden);
    }
  }

  // Init exclusion UI on DOM ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initExclusionTagUI);
  } else {
    initExclusionTagUI();
  }
})();
