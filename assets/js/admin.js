(function () {
  "use strict";

  /* ─────────────────────────────────────────────────────────────
     1. ONBOARDING STEPPER
     Manages the progressive setup flow on the Settings page.
     Each step can be:
       - is-pending  (locked, future)
       - is-active   (current, body shown)
       - is-done     (complete, body hidden but editable via "Edit")
     Steps are stored in data-step="strategy|google|done" attrs.
  ─────────────────────────────────────────────────────────────── */
  function initStepper() {
    var stepper = document.querySelector(".seoworkerai-stepper");
    if (!stepper) return;

    var steps = Array.from(stepper.querySelectorAll(".seoworkerai-step"));

    function openStep(step) {
      steps.forEach(function (s) {
        s.classList.remove("is-open");
      });
      step.classList.add("is-open");
    }

    function closeStep(step) {
      step.classList.remove("is-open");
    }

    steps.forEach(function (step) {
      var header = step.querySelector(".seoworkerai-step-header");
      var editLink = step.querySelector(".seoworkerai-step-edit-link");

      // Clicking header on a done step opens it for editing
      if (header) {
        header.addEventListener("click", function () {
          if (step.classList.contains("is-pending")) return;
          if (step.classList.contains("is-active")) return; // active always open
          if (step.classList.contains("is-open")) {
            closeStep(step);
          } else {
            openStep(step);
          }
        });
      }

      // Explicit "Edit" link
      if (editLink) {
        editLink.addEventListener("click", function (e) {
          e.stopPropagation();
          openStep(step);
        });
      }
    });

    // "Continue" / "Save" buttons within steps advance flow
    stepper.addEventListener("click", function (e) {
      var continueBtn = e.target.closest("[data-seoworkerai-step-continue]");
      if (!continueBtn) return;

      var currentStep = continueBtn.closest(".seoworkerai-step");
      if (!currentStep) return;

      // Mark current as done
      currentStep.classList.remove("is-active", "is-open");
      currentStep.classList.add("is-done");

      // Activate next pending step
      var nextStep = currentStep.nextElementSibling;
      while (nextStep) {
        if (
          nextStep.classList.contains("seoworkerai-step") &&
          nextStep.classList.contains("is-pending")
        ) {
          nextStep.classList.remove("is-pending");
          nextStep.classList.add("is-active");
          break;
        }
        nextStep = nextStep.nextElementSibling;
      }
    });
  }

  /* ─────────────────────────────────────────────────────────────
     2. CHIP FILTER BAR (reusable — Change Center, Action Items, etc.)
  ─────────────────────────────────────────────────────────────── */
  function initChipFilterBar(barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;
    var form = bar.querySelector(".seoworkerai-filter-form");
    if (!form) return;

    var labelMaps = {};
    try {
      labelMaps = JSON.parse(bar.getAttribute("data-label-maps") || "{}");
    } catch (e) {
      /* silent */
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
        .querySelectorAll(
          ".seoworkerai-filter-option input[type=checkbox]:checked",
        )
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
      var chipRow = bar.querySelector(".seoworkerai-active-chips");
      if (!chipRow) return;
      var checked = getCheckedValues();
      var html = "";
      Object.keys(checked).forEach(function (key) {
        checked[key].forEach(function (val) {
          var label =
            labelMaps[key] && labelMaps[key][val] ? labelMaps[key][val] : val;
          html +=
            '<span class="seoworkerai-active-chip"><span>' +
            esc(label) +
            "</span>" +
            '<button type="button" data-chip-key="' +
            esc(key) +
            '" data-chip-val="' +
            esc(val) +
            '" aria-label="Remove">×</button></span>';
        });
      });
      chipRow.innerHTML = html;

      bar
        .querySelectorAll(".seoworkerai-filter-dropdown")
        .forEach(function (dd) {
          var key = dd.getAttribute("data-filter-key");
          var vals = checked[key] || [];
          var btn = dd.querySelector(".seoworkerai-filter-btn");
          if (!btn) return;
          btn.classList.toggle("has-active", vals.length > 0);
          var countEl = btn.querySelector(".seoworkerai-filter-count");
          if (vals.length > 0) {
            if (!countEl) {
              countEl = document.createElement("span");
              countEl.className = "seoworkerai-filter-count";
              var chevron = btn.querySelector(".seoworkerai-filter-chevron");
              btn.insertBefore(countEl, chevron);
            }
            countEl.textContent = vals.length;
          } else if (countEl) {
            countEl.remove();
          }
        });
    }

    function submitForm() {
      var hiddenContainer = bar.querySelector(
        ".seoworkerai-filter-hidden-inputs",
      );
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

    bar.addEventListener("click", function (e) {
      var filterBtn = e.target.closest(".seoworkerai-filter-btn");
      if (filterBtn) {
        e.preventDefault();
        var dropdown = filterBtn.closest(".seoworkerai-filter-dropdown");
        var panel = dropdown
          ? dropdown.querySelector(".seoworkerai-filter-panel")
          : null;
        bar.querySelectorAll(".seoworkerai-filter-panel").forEach(function (p) {
          if (p !== panel) p.style.display = "none";
        });
        if (panel)
          panel.style.display =
            panel.style.display === "none" || !panel.style.display
              ? "block"
              : "none";
        return;
      }
      var chipBtn = e.target.closest("[data-chip-key]");
      if (chipBtn) {
        e.preventDefault();
        var key = chipBtn.getAttribute("data-chip-key");
        var val = chipBtn.getAttribute("data-chip-val");
        bar
          .querySelectorAll(
            'input[name="' +
              key +
              '[]"][value="' +
              val.replace(/"/g, '\\"') +
              '"]',
          )
          .forEach(function (cb) {
            cb.checked = false;
          });
        submitForm();
        return;
      }
      var clrBtn = e.target.closest(".seoworkerai-filter-clear-one");
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
      if (e.target.type === "checkbox") {
        refreshChips();
        return;
      }
      if (!e.target.closest(".seoworkerai-filter-dropdown")) {
        bar.querySelectorAll(".seoworkerai-filter-panel").forEach(function (p) {
          p.style.display = "none";
        });
      }
    });

    bar.querySelectorAll(".seoworkerai-filter-apply").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        submitForm();
      });
    });

    bar
      .querySelectorAll(".seoworkerai-filter-post-search")
      .forEach(function (inp) {
        inp.addEventListener("input", function () {
          var q = inp.value.toLowerCase().trim();
          inp
            .closest(".seoworkerai-filter-panel")
            .querySelectorAll(".seoworkerai-filter-option")
            .forEach(function (opt) {
              var lbl = (
                opt.getAttribute("data-label") || opt.textContent
              ).toLowerCase();
              opt.style.display = !q || lbl.includes(q) ? "" : "none";
            });
        });
      });

    refreshChips();
  }

  function initAllFilterBars() {
    document
      .querySelectorAll(".seoworkerai-chip-filter-bar")
      .forEach(function (bar) {
        if (bar.id) initChipFilterBar(bar.id);
      });
  }

  /* ─────────────────────────────────────────────────────────────
     3. POST PICKER (Content Briefs)
  ─────────────────────────────────────────────────────────────── */
  function initPostPickers() {
    document
      .querySelectorAll(".seoworkerai-post-picker")
      .forEach(function (picker) {
        if (picker.getAttribute("data-init") === "1") return;
        picker.setAttribute("data-init", "1");
        var hiddenInput = picker.querySelector('input[type="hidden"]');
        var display = picker.querySelector(".seoworkerai-post-picker-display");
        var dropdown = picker.querySelector(
          ".seoworkerai-post-picker-dropdown",
        );
        var search = picker.querySelector(".seoworkerai-post-picker-search");
        if (!hiddenInput || !display || !dropdown || !search) return;

        display.addEventListener("click", function () {
          document
            .querySelectorAll(".seoworkerai-post-picker-dropdown.is-open")
            .forEach(function (d) {
              if (d !== dropdown) d.classList.remove("is-open");
            });
          dropdown.classList.toggle("is-open");
          if (dropdown.classList.contains("is-open")) search.focus();
        });
        search.addEventListener("input", function () {
          var q = search.value.toLowerCase().trim();
          picker
            .querySelectorAll(".seoworkerai-post-picker-option")
            .forEach(function (opt) {
              var title = (
                opt.getAttribute("data-post-title") || ""
              ).toLowerCase();
              opt.style.display = !q || title.indexOf(q) !== -1 ? "" : "none";
            });
        });
        picker
          .querySelectorAll(".seoworkerai-post-picker-option")
          .forEach(function (opt) {
            opt.addEventListener("click", function () {
              hiddenInput.value = opt.getAttribute("data-post-id") || "";
              display.textContent =
                opt.getAttribute("data-post-title") || "Select a post or page";
              dropdown.classList.remove("is-open");
            });
          });
      });
    document.addEventListener("click", function (e) {
      if (!e.target.closest(".seoworkerai-post-picker")) {
        document
          .querySelectorAll(".seoworkerai-post-picker-dropdown.is-open")
          .forEach(function (d) {
            d.classList.remove("is-open");
          });
      }
    });
  }

  /* ─────────────────────────────────────────────────────────────
     4. SITE SETTINGS TEMPLATE PICKER (auto-fills fields)
  ─────────────────────────────────────────────────────────────── */
  function initSiteSettingsTemplatePicker() {
    var select = document.getElementById(
      "seoworkerai-site-settings-template-id",
    );
    if (!select) return;
    var templates = [];
    try {
      templates = JSON.parse(
        select.getAttribute("data-template-configs") || "[]",
      );
    } catch (_) {}
    var templateMap = {};
    templates.forEach(function (t) {
      templateMap[String(t.id || 0)] = t;
    });

    var fieldMap = {
      min_search_volume: document.getElementById(
        "seoworkerai-site-settings-min-search-volume",
      ),
      max_search_volume: document.getElementById(
        "seoworkerai-site-settings-max-search-volume",
      ),
      max_keyword_difficulty: document.getElementById(
        "seoworkerai-site-settings-max-keyword-difficulty",
      ),
      preferred_keyword_type: document.getElementById(
        "seoworkerai-site-settings-preferred-keyword-type",
      ),
      content_briefs_per_run: document.getElementById(
        "seoworkerai-site-settings-content-briefs-per-run",
      ),
      selection_notes: document.getElementById(
        "seoworkerai-site-settings-selection-notes",
      ),
      prefer_low_difficulty: document.querySelector(
        'input[name="site_settings_prefer_low_difficulty"]',
      ),
      allow_low_volume: document.querySelector(
        'input[name="site_settings_allow_low_volume"]',
      ),
    };

    select.addEventListener("change", function () {
      var t = templateMap[String(select.value || "0")];
      if (!t) return;
      if (fieldMap.min_search_volume)
        fieldMap.min_search_volume.value = String(t.min_search_volume ?? 0);
      if (fieldMap.max_search_volume)
        fieldMap.max_search_volume.value =
          t.max_search_volume === null ? "" : String(t.max_search_volume);
      if (fieldMap.max_keyword_difficulty)
        fieldMap.max_keyword_difficulty.value = String(
          t.max_keyword_difficulty ?? 100,
        );
      if (fieldMap.preferred_keyword_type)
        fieldMap.preferred_keyword_type.value = String(
          t.preferred_keyword_type || "",
        );
      if (fieldMap.content_briefs_per_run)
        fieldMap.content_briefs_per_run.value = String(
          t.content_briefs_per_run ?? 3,
        );
      if (fieldMap.selection_notes)
        fieldMap.selection_notes.value = String(t.selection_notes || "");
      if (fieldMap.prefer_low_difficulty)
        fieldMap.prefer_low_difficulty.checked = !!t.prefer_low_difficulty;
      if (fieldMap.allow_low_volume)
        fieldMap.allow_low_volume.checked = !!t.allow_low_volume;
    });
  }

  /* ─────────────────────────────────────────────────────────────
     5. LOCATIONS TABLE
  ─────────────────────────────────────────────────────────────── */
  function initLocationsTable() {
    var wrap = document.querySelector(".seoworkerai-locations-table-wrap");
    var tbody = document.getElementById("seoworkerai-locations-body");
    var addBtn = document.getElementById("seoworkerai-add-location-row");
    if (!wrap || !tbody || !addBtn) return;

    var options = [];
    try {
      options = JSON.parse(wrap.getAttribute("data-location-options") || "[]");
    } catch (_) {}
    if (!Array.isArray(options) || !options.length) return;

    function escHtml(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }
    function buildOptions(sel) {
      return options
        .map(function (o) {
          return (
            '<option value="' +
            o.code +
            '" data-location-name="' +
            escHtml(o.name) +
            '"' +
            (String(o.code) === String(sel) ? " selected" : "") +
            ">" +
            escHtml(o.label) +
            "</option>"
          );
        })
        .join("");
    }
    function syncRow(row) {
      var sel = row.querySelector(".seoworkerai-location-select");
      var hidden = row.querySelector(".seoworkerai-location-name");
      var cell = row.querySelector(".seoworkerai-location-code-cell");
      if (!sel || !hidden || !cell) return;
      var opt = sel.options[sel.selectedIndex];
      hidden.value = opt ? opt.getAttribute("data-location-name") || "" : "";
      cell.textContent = sel.value || "";
    }
    function syncPrimary(changedSel) {
      if (!changedSel || changedSel.value !== "primary") return;
      tbody
        .querySelectorAll(".seoworkerai-location-type")
        .forEach(function (s) {
          if (s !== changedSel) s.value = "secondary";
        });
    }
    function ensurePrimary() {
      var found = false;
      tbody
        .querySelectorAll(".seoworkerai-location-type")
        .forEach(function (s) {
          if (s.value === "primary") found = true;
        });
      if (!found) {
        var f = tbody.querySelector(".seoworkerai-location-type");
        if (f) f.value = "primary";
      }
    }
    function reindex() {
      tbody
        .querySelectorAll(".seoworkerai-location-row")
        .forEach(function (row, i) {
          row.querySelectorAll("select, input").forEach(function (f) {
            var n = f.getAttribute("name") || "";
            f.setAttribute(
              "name",
              n.replace(/site_locations\[\d+\]/, "site_locations[" + i + "]"),
            );
          });
        });
    }
    function appendRow(code, type) {
      var row = document.createElement("tr");
      row.className = "seoworkerai-location-row";
      row.innerHTML =
        '<td><select name="site_locations[0][location_code]" class="seoworkerai-location-select">' +
        buildOptions(code || options[0].code) +
        "</select>" +
        '<input type="hidden" name="site_locations[0][location_name]" value="" class="seoworkerai-location-name"></td>' +
        '<td class="seoworkerai-location-code-cell"></td>' +
        '<td><select name="site_locations[0][location_type]" class="seoworkerai-location-type"><option value="primary">Primary</option><option value="secondary">Secondary</option></select></td>' +
        '<td><button type="button" class="seoworkerai-remove-location" style="font-size:11px;color:var(--red);background:none;border:none;cursor:pointer;">Remove</button></td>';
      tbody.appendChild(row);
      row.querySelector(".seoworkerai-location-type").value =
        type || "secondary";
      syncRow(row);
      reindex();
      ensurePrimary();
      syncPrimary(row.querySelector(".seoworkerai-location-type"));
    }

    tbody.querySelectorAll(".seoworkerai-location-row").forEach(syncRow);
    ensurePrimary();

    addBtn.addEventListener("click", function () {
      appendRow(
        options[0].code,
        tbody.children.length === 0 ? "primary" : "secondary",
      );
    });
    tbody.addEventListener("change", function (e) {
      if (e.target.classList.contains("seoworkerai-location-select"))
        syncRow(e.target.closest(".seoworkerai-location-row"));
      if (e.target.classList.contains("seoworkerai-location-type")) {
        syncPrimary(e.target);
        ensurePrimary();
      }
    });
    tbody.addEventListener("click", function (e) {
      var rm = e.target.closest(".seoworkerai-remove-location");
      if (!rm) return;
      if (tbody.querySelectorAll(".seoworkerai-location-row").length <= 1)
        return;
      rm.closest(".seoworkerai-location-row").remove();
      reindex();
      ensurePrimary();
    });
  }

  /* ─────────────────────────────────────────────────────────────
     6. AUTHOR PROFILES TABLE (search + sort + pagination)
  ─────────────────────────────────────────────────────────────── */
  function initAuthorProfilesTable() {
    var table = document.getElementById("seoworkerai-author-table");
    if (!table) return;
    var tbody = table.querySelector("tbody");
    if (!tbody) return;
    var searchInput = document.getElementById("seoworkerai-author-search");
    var pagination = document.getElementById("seoworkerai-author-pagination");
    var pageSize = parseInt(table.getAttribute("data-page-size") || "10", 10);
    if (!isFinite(pageSize) || pageSize <= 0) pageSize = 10;

    var rows = Array.from(tbody.querySelectorAll("tr"));
    var currentPage = 1;
    var currentSortKey = "author";
    var currentSortDir = "asc";
    var currentQuery = "";

    function getCellValue(row, key) {
      return String(row.getAttribute("data-" + key) || "");
    }
    function getFiltered() {
      if (!currentQuery) return rows.slice();
      return rows.filter(function (r) {
        return (
          (r.getAttribute("data-author") || "").indexOf(currentQuery) !== -1 ||
          (r.getAttribute("data-email") || "").indexOf(currentQuery) !== -1
        );
      });
    }
    function render() {
      var filtered = getFiltered();
      filtered.sort(function (a, b) {
        var av = getCellValue(a, currentSortKey),
          bv = getCellValue(b, currentSortKey);
        return av === bv
          ? 0
          : (av < bv ? -1 : 1) * (currentSortDir === "asc" ? 1 : -1);
      });
      var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (currentPage > totalPages) currentPage = totalPages;
      var start = (currentPage - 1) * pageSize;
      var visible = new Set(filtered.slice(start, start + pageSize));
      rows.forEach(function (r) {
        r.style.display = visible.has(r) ? "" : "none";
      });
      if (pagination) {
        pagination.innerHTML =
          '<button type="button" class="button-link" data-author-page="prev"' +
          (currentPage <= 1 ? " disabled" : "") +
          ">Prev</button> " +
          "<strong>" +
          currentPage +
          "/" +
          totalPages +
          "</strong> " +
          '<button type="button" class="button-link" data-author-page="next"' +
          (currentPage >= totalPages ? " disabled" : "") +
          ">Next</button>";
      }
    }
    if (searchInput) {
      searchInput.addEventListener("input", function () {
        currentQuery = String(searchInput.value || "")
          .toLowerCase()
          .trim();
        currentPage = 1;
        render();
      });
    }
    table.querySelectorAll("thead [data-sort-key]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var key = String(btn.getAttribute("data-sort-key") || "author");
        if (key === currentSortKey)
          currentSortDir = currentSortDir === "asc" ? "desc" : "asc";
        else {
          currentSortKey = key;
          currentSortDir = "asc";
        }
        render();
      });
    });
    if (pagination) {
      pagination.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-author-page]");
        if (!btn || btn.disabled) return;
        var dir = btn.getAttribute("data-author-page");
        if (dir === "prev") currentPage = Math.max(1, currentPage - 1);
        if (dir === "next") currentPage++;
        render();
      });
    }
    render();
  }

  /* ─────────────────────────────────────────────────────────────
     7. RADIO CARD SELECTION (Automation Preferences)
  ─────────────────────────────────────────────────────────────── */
  function initRadioCards() {
    document
      .querySelectorAll(".seoworkerai-radio-group")
      .forEach(function (group) {
        group
          .querySelectorAll(".seoworkerai-radio-card input[type=radio]")
          .forEach(function (radio) {
            radio.addEventListener("change", function () {
              group
                .querySelectorAll(".seoworkerai-radio-card")
                .forEach(function (card) {
                  card.classList.toggle(
                    "is-selected",
                    card.querySelector("input[type=radio]").checked,
                  );
                });
            });
          });
        // Init
        group
          .querySelectorAll(".seoworkerai-radio-card")
          .forEach(function (card) {
            var r = card.querySelector("input[type=radio]");
            if (r && r.checked) card.classList.add("is-selected");
          });
      });
  }

  /* ─────────────────────────────────────────────────────────────
     8. PROGRESSION TIMELINE TOGGLE (Change Center)
  ─────────────────────────────────────────────────────────────── */
  document.addEventListener("click", function (e) {
    var toggler = e.target.closest(".seoworkerai-progression-toggle");
    if (!toggler) return;
    e.stopPropagation();
    var targetId = toggler.getAttribute("data-target");
    var subRow = targetId ? document.getElementById(targetId) : null;
    if (!subRow) return;
    var isOpen = subRow.style.display === "table-row";
    subRow.style.display = isOpen ? "none" : "table-row";
    var arrow = toggler.querySelector(".seoworkerai-prog-arrow");
    if (arrow) arrow.textContent = isOpen ? "▸" : "▾";
  });

  /* ─────────────────────────────────────────────────────────────
     9. INLINE EDIT (Change Center — Proposed Change column)
  ─────────────────────────────────────────────────────────────── */
  document.addEventListener("click", function (e) {
    var isEdit = e.target.getAttribute("data-seoworkerai-edit-toggle") === "1";
    var isCancel =
      e.target.getAttribute("data-seoworkerai-cancel-button") === "1";
    if (!isEdit && !isCancel) return;
    var container = e.target.closest(".seoworkerai-inline-edit-container");
    if (!container) return;
    var displayView = container.querySelector(".seoworkerai-inline-display");
    var editView = container.querySelector(".seoworkerai-inline-edit-fields");
    var editBtn = container.querySelector('[data-seoworkerai-edit-toggle="1"]');
    var saveBtn = container.querySelector('[data-seoworkerai-save-button="1"]');
    var cancelBtn = container.querySelector(
      '[data-seoworkerai-cancel-button="1"]',
    );
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

  // "Currently applied" toggle
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".seoworkerai-toggle-current");
    if (!btn) return;
    var panel = btn.nextElementSibling;
    if (!panel) return;
    var vis = panel.style.display !== "none";
    panel.style.display = vis ? "none" : "block";
    btn.textContent = vis ? "▸ Currently applied" : "▴ Currently applied";
  });

  // Inline edit validation
  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form || form.tagName !== "FORM") return;
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== "seoworkerai_edit_action_payload")
      return;
    var errors = [];
    form
      .querySelectorAll(
        ".seoworkerai-inline-edit-fields input[name^='payload_fields'], .seoworkerai-inline-edit-fields textarea[name^='payload_fields']",
      )
      .forEach(function (field) {
        var val = (field.value || "").trim();
        var label = field.closest("label")
          ? field.closest("label").childNodes[0].textContent.trim()
          : "Field";
        var minLen = parseInt(field.getAttribute("data-min-length") || "0", 10);
        var maxLen = parseInt(field.getAttribute("data-max-length") || "0", 10);
        var validation = field.getAttribute("data-validation") || "";
        if (minLen > 0 && val.length < minLen)
          errors.push(label + " must be at least " + minLen + " characters.");
        if (maxLen > 0 && val.length > maxLen)
          errors.push(label + " must be " + maxLen + " characters or fewer.");
        if (
          validation === "twitter_handle" &&
          val &&
          !/^@?[A-Za-z0-9_]{1,15}$/.test(val)
        )
          errors.push(
            label +
              " must be a valid handle (1–15 chars, letters/numbers/underscore).",
          );
        if (validation === "json" && val) {
          try {
            JSON.parse(val);
          } catch (_) {
            errors.push(label + " must be valid JSON.");
          }
        }
      });
    if (errors.length > 0) {
      e.preventDefault();
      alert(errors.join("\n"));
    }
  });

  /* ─────────────────────────────────────────────────────────────
     10. EXCLUDED PAGES TAG-CHIP UI (Automation Preferences)
  ─────────────────────────────────────────────────────────────── */
  function initExclusionTagUI() {
    var container = document.getElementById("seoworkerai-exclusion-tag-ui");
    if (!container) return;
    var searchInp = container.querySelector(".seoworkerai-excl-search");
    var dropdown = container.querySelector(".seoworkerai-excl-dropdown");
    var chipsWrap = container.querySelector(".seoworkerai-excl-chips");
    var hiddenInp = document.getElementById("seoworkerai-exclusion-hidden");
    var allOptions = container.querySelectorAll(".seoworkerai-excl-option");

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

    function escHtml(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }
    function getLabel(id) {
      var o = container.querySelector(
        '.seoworkerai-excl-option[data-id="' + id + '"]',
      );
      return o ? o.textContent.trim() : id;
    }
    function syncHidden() {
      if (hiddenInp) hiddenInp.value = Object.keys(selectedIds).join("\n");
    }
    function renderChips() {
      if (!chipsWrap) return;
      chipsWrap.innerHTML = "";
      Object.keys(selectedIds).forEach(function (id) {
        var chip = document.createElement("span");
        chip.className = "seoworkerai-active-chip";
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
    function filterDropdown(q) {
      allOptions.forEach(function (opt) {
        var lbl = (opt.getAttribute("data-label") || "").toLowerCase();
        opt.style.display = !q || lbl.includes(q) ? "" : "none";
      });
    }

    allOptions.forEach(function (opt) {
      var id = opt.getAttribute("data-id");
      opt.classList.toggle("is-selected", !!selectedIds[id]);
      var chk = opt.querySelector(".seoworkerai-excl-checkmark");
      if (chk) chk.textContent = selectedIds[id] ? "✓" : "";
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
      document.addEventListener("click", function (e) {
        if (!container.contains(e.target)) {
          if (dropdown) dropdown.style.display = "none";
        }
      });
    }
    container.addEventListener("click", function (e) {
      var opt = e.target.closest(".seoworkerai-excl-option");
      if (opt) {
        var id = opt.getAttribute("data-id");
        var chk = opt.querySelector(".seoworkerai-excl-checkmark");
        if (selectedIds[id]) {
          delete selectedIds[id];
          opt.classList.remove("is-selected");
          if (chk) chk.textContent = "";
        } else {
          selectedIds[id] = true;
          opt.classList.add("is-selected");
          if (chk) chk.textContent = "✓";
        }
        syncHidden();
        renderChips();
        return;
      }
      var rmBtn = e.target.closest("[data-excl-remove]");
      if (rmBtn) {
        var rid = rmBtn.getAttribute("data-excl-remove");
        delete selectedIds[rid];
        var mOpt = container.querySelector(
          '.seoworkerai-excl-option[data-id="' + rid + '"]',
        );
        if (mOpt) {
          mOpt.classList.remove("is-selected");
          var chk = mOpt.querySelector(".seoworkerai-excl-checkmark");
          if (chk) chk.textContent = "";
        }
        syncHidden();
        renderChips();
      }
    });
    var frm = hiddenInp ? hiddenInp.closest("form") : null;
    if (frm) frm.addEventListener("submit", syncHidden);
  }

  /* ─────────────────────────────────────────────────────────────
     INIT
  ─────────────────────────────────────────────────────────────── */
  function boot() {
    initStepper();
    initAllFilterBars();
    initPostPickers();
    initSiteSettingsTemplatePicker();
    initLocationsTable();
    initAuthorProfilesTable();
    initRadioCards();
    initExclusionTagUI();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
