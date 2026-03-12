(function () {
  "use strict";

  // ─── 1 & 3. Chip Filter Bar (Change Center + reusable) ─────────────────────
  function initChipFilterBar(barId) {
    var bar = document.getElementById(barId);
    if (!bar) return;

    var form = bar.querySelector(".seoworkerai-filter-form");
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
        .querySelectorAll(".seoworkerai-filter-option input[type=checkbox]:checked")
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
            '<span class="seoworkerai-active-chip">' +
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
      bar.querySelectorAll(".seoworkerai-filter-dropdown").forEach(function (dd) {
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
      // Before submitting, sync checkboxes → hidden inputs
      // Remove all existing hidden filter inputs
      var hiddenContainer = bar.querySelector(".seoworkerai-filter-hidden-inputs");
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
      var filterBtn = e.target.closest(".seoworkerai-filter-btn");
      if (filterBtn) {
        e.preventDefault();
        var dropdown = filterBtn.closest(".seoworkerai-filter-dropdown");
        var panel = dropdown
          ? dropdown.querySelector(".seoworkerai-filter-panel")
          : null;
        var allPanels = bar.querySelectorAll(".seoworkerai-filter-panel");
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

      // Checkbox change → refresh chips
      if (e.target.type === "checkbox") {
        refreshChips();
        return;
      }

      // Close on outside click
      if (!e.target.closest(".seoworkerai-filter-dropdown")) {
        bar.querySelectorAll(".seoworkerai-filter-panel").forEach(function (p) {
          p.style.display = "none";
        });
      }
    });

    // Apply button submits the form
    bar.querySelectorAll(".seoworkerai-filter-apply").forEach(function (btn) {
      btn.addEventListener("click", function (e) {
        e.preventDefault();
        submitForm();
      });
    });

    // Post search filter inside panel
    bar.querySelectorAll(".seoworkerai-filter-post-search").forEach(function (inp) {
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

    // Init chips on load
    refreshChips();
  }

  // Init all chip filter bars on the page after DOM is ready
  function initAllFilterBars() {
    document
      .querySelectorAll(".seoworkerai-chip-filter-bar")
      .forEach(function (bar) {
        if (bar.id) initChipFilterBar(bar.id);
      });
  }

  function initPostPickers() {
    document.querySelectorAll(".seoworkerai-post-picker").forEach(function (picker) {
      if (picker.getAttribute("data-init") === "1") return;
      picker.setAttribute("data-init", "1");

      var hiddenInput = picker.querySelector('input[type="hidden"]');
      var display = picker.querySelector(".seoworkerai-post-picker-display");
      var dropdown = picker.querySelector(".seoworkerai-post-picker-dropdown");
      var search = picker.querySelector(".seoworkerai-post-picker-search");

      if (!hiddenInput || !display || !dropdown || !search) return;

      display.addEventListener("click", function () {
        document.querySelectorAll(".seoworkerai-post-picker-dropdown.is-open").forEach(function (openDropdown) {
          if (openDropdown !== dropdown) openDropdown.classList.remove("is-open");
        });
        dropdown.classList.toggle("is-open");
        if (dropdown.classList.contains("is-open")) search.focus();
      });

      search.addEventListener("input", function () {
        var query = search.value.toLowerCase().trim();
        picker.querySelectorAll(".seoworkerai-post-picker-option").forEach(function (option) {
          var title = (option.getAttribute("data-post-title") || "").toLowerCase();
          var type = (option.getAttribute("data-post-type") || "").toLowerCase();
          option.style.display = !query || title.indexOf(query) !== -1 || type.indexOf(query) !== -1 ? "" : "none";
        });
      });

      picker.querySelectorAll(".seoworkerai-post-picker-option").forEach(function (option) {
        option.addEventListener("click", function () {
          hiddenInput.value = option.getAttribute("data-post-id") || "";
          display.textContent = option.getAttribute("data-post-title") || "Select a post or page";
          dropdown.classList.remove("is-open");
        });
      });
    });

    document.addEventListener("click", function (event) {
      if (!event.target.closest(".seoworkerai-post-picker")) {
        document.querySelectorAll(".seoworkerai-post-picker-dropdown.is-open").forEach(function (dropdown) {
          dropdown.classList.remove("is-open");
        });
      }
    });
  }

  function initSiteSettingsTemplatePicker() {
    var select = document.getElementById("seoworkerai-site-settings-template-id");
    if (!select) return;

    var rawConfigs = select.getAttribute("data-template-configs") || "[]";
    var templates = [];
    try {
      templates = JSON.parse(rawConfigs);
    } catch (_err) {
      templates = [];
    }

    var templateMap = {};
    templates.forEach(function (template) {
      templateMap[String(template.id || 0)] = template;
    });

    var fieldMap = {
      min_search_volume: document.getElementById("seoworkerai-site-settings-min-search-volume"),
      max_search_volume: document.getElementById("seoworkerai-site-settings-max-search-volume"),
      max_keyword_difficulty: document.getElementById("seoworkerai-site-settings-max-keyword-difficulty"),
      preferred_keyword_type: document.getElementById("seoworkerai-site-settings-preferred-keyword-type"),
      content_briefs_per_run: document.getElementById("seoworkerai-site-settings-content-briefs-per-run"),
      selection_notes: document.getElementById("seoworkerai-site-settings-selection-notes"),
      prefer_low_difficulty: document.querySelector('input[name="site_settings_prefer_low_difficulty"]'),
      allow_low_volume: document.querySelector('input[name="site_settings_allow_low_volume"]'),
    };

    select.addEventListener("change", function () {
      var template = templateMap[String(select.value || "0")];
      if (!template) return;

      if (fieldMap.min_search_volume) fieldMap.min_search_volume.value = String(template.min_search_volume ?? 0);
      if (fieldMap.max_search_volume) fieldMap.max_search_volume.value = template.max_search_volume === null ? "" : String(template.max_search_volume);
      if (fieldMap.max_keyword_difficulty) fieldMap.max_keyword_difficulty.value = String(template.max_keyword_difficulty ?? 100);
      if (fieldMap.preferred_keyword_type) fieldMap.preferred_keyword_type.value = String(template.preferred_keyword_type || "");
      if (fieldMap.content_briefs_per_run) fieldMap.content_briefs_per_run.value = String(template.content_briefs_per_run ?? 3);
      if (fieldMap.selection_notes) fieldMap.selection_notes.value = String(template.selection_notes || "");
      if (fieldMap.prefer_low_difficulty) fieldMap.prefer_low_difficulty.checked = !!template.prefer_low_difficulty;
      if (fieldMap.allow_low_volume) fieldMap.allow_low_volume.checked = !!template.allow_low_volume;
    });
  }

  function initLocationsTable() {
    var wrap = document.querySelector(".seoworkerai-locations-table-wrap");
    var tbody = document.getElementById("seoworkerai-locations-body");
    var addButton = document.getElementById("seoworkerai-add-location-row");
    if (!wrap || !tbody || !addButton) return;

    var rawOptions = wrap.getAttribute("data-location-options") || "[]";
    var options = [];
    try {
      options = JSON.parse(rawOptions);
    } catch (_err) {
      options = [];
    }
    if (!Array.isArray(options) || options.length === 0) return;

    function escHtml(s) {
      return String(s)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
    }

    function buildOptions(selectedCode) {
      return options
        .map(function (option) {
          var selected =
            String(option.code) === String(selectedCode)
              ? ' selected="selected"'
              : "";
          return (
            '<option value="' +
            String(option.code) +
            '" data-location-name="' +
            escHtml(option.name) +
            '"' +
            selected +
            ">" +
            escHtml(option.label) +
            "</option>"
          );
        })
        .join("");
    }

    function syncRow(row) {
      var select = row.querySelector(".seoworkerai-location-select");
      var hiddenName = row.querySelector(".seoworkerai-location-name");
      var codeCell = row.querySelector(".seoworkerai-location-code-cell");
      if (!select || !hiddenName || !codeCell) return;

      var selectedOption = select.options[select.selectedIndex];
      hiddenName.value = selectedOption
        ? selectedOption.getAttribute("data-location-name") || ""
        : "";
      codeCell.textContent = select.value || "";
    }

    function syncPrimaryState(changedSelect) {
      if (!changedSelect || changedSelect.value !== "primary") return;
      tbody.querySelectorAll(".seoworkerai-location-type").forEach(function (select) {
        if (select !== changedSelect) select.value = "secondary";
      });
    }

    function ensurePrimary() {
      var foundPrimary = false;
      tbody.querySelectorAll(".seoworkerai-location-type").forEach(function (select) {
        if (select.value === "primary") foundPrimary = true;
      });
      if (!foundPrimary) {
        var firstType = tbody.querySelector(".seoworkerai-location-type");
        if (firstType) firstType.value = "primary";
      }
    }

    function reindexRows() {
      tbody.querySelectorAll(".seoworkerai-location-row").forEach(function (row, index) {
        row.querySelectorAll("select, input").forEach(function (field) {
          var name = field.getAttribute("name") || "";
          field.setAttribute(
            "name",
            name.replace(/site_locations\[\d+\]/, "site_locations[" + index + "]"),
          );
        });
      });
    }

    function appendRow(selectedCode, locationType) {
      var row = document.createElement("tr");
      row.className = "seoworkerai-location-row";
      row.innerHTML =
        '<td><select name="site_locations[0][location_code]" class="seoworkerai-location-select">' +
        buildOptions(selectedCode || options[0].code) +
        '</select><input type="hidden" name="site_locations[0][location_name]" value="" class="seoworkerai-location-name"></td>' +
        '<td class="seoworkerai-location-code-cell"></td>' +
        '<td><select name="site_locations[0][location_type]" class="seoworkerai-location-type"><option value="primary">Primary</option><option value="secondary">Secondary</option></select></td>' +
        '<td><button type="button" class="button-link-delete seoworkerai-remove-location">Remove</button></td>';
      tbody.appendChild(row);
      row.querySelector(".seoworkerai-location-type").value =
        locationType || "secondary";
      syncRow(row);
      reindexRows();
      ensurePrimary();
      syncPrimaryState(row.querySelector(".seoworkerai-location-type"));
    }

    tbody.querySelectorAll(".seoworkerai-location-row").forEach(syncRow);
    ensurePrimary();

    addButton.addEventListener("click", function () {
      appendRow(options[0].code, tbody.children.length === 0 ? "primary" : "secondary");
    });

    tbody.addEventListener("change", function (event) {
      if (event.target.classList.contains("seoworkerai-location-select")) {
        syncRow(event.target.closest(".seoworkerai-location-row"));
      }
      if (event.target.classList.contains("seoworkerai-location-type")) {
        syncPrimaryState(event.target);
        ensurePrimary();
      }
    });

    tbody.addEventListener("click", function (event) {
      var removeButton = event.target.closest(".seoworkerai-remove-location");
      if (!removeButton) return;

      var rows = tbody.querySelectorAll(".seoworkerai-location-row");
      if (rows.length <= 1) return;

      removeButton.closest(".seoworkerai-location-row").remove();
      reindexRows();
      ensurePrimary();
    });
  }

  function initAuthorProfilesTable() {
    var table = document.getElementById("seoworkerai-author-table");
    if (!table) return;

    var tbody = table.querySelector("tbody");
    if (!tbody) return;
    var searchInput = document.getElementById("seoworkerai-author-search");
    var pagination = document.getElementById("seoworkerai-author-pagination");
    var pageSize = parseInt(table.getAttribute("data-page-size") || "10", 10);
    if (!Number.isFinite(pageSize) || pageSize <= 0) pageSize = 10;

    var rows = Array.prototype.slice.call(tbody.querySelectorAll("tr"));
    var currentPage = 1;
    var currentSortKey = "author";
    var currentSortDir = "asc";
    var currentQuery = "";

    function getCellValue(row, key) {
      if (key === "email") return String(row.getAttribute("data-email") || "");
      return String(row.getAttribute("data-author") || "");
    }

    function getFilteredRows() {
      if (!currentQuery) return rows.slice();
      return rows.filter(function (row) {
        var author = String(row.getAttribute("data-author") || "");
        var email = String(row.getAttribute("data-email") || "");
        return author.indexOf(currentQuery) !== -1 || email.indexOf(currentQuery) !== -1;
      });
    }

    function sortRows(filteredRows) {
      filteredRows.sort(function (a, b) {
        var av = getCellValue(a, currentSortKey);
        var bv = getCellValue(b, currentSortKey);
        if (av === bv) return 0;
        var compare = av < bv ? -1 : 1;
        return currentSortDir === "asc" ? compare : -compare;
      });
    }

    function render() {
      var filtered = getFilteredRows();
      sortRows(filtered);

      var totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
      if (currentPage > totalPages) currentPage = totalPages;
      var start = (currentPage - 1) * pageSize;
      var end = start + pageSize;
      var visibleSet = new Set(filtered.slice(start, end));

      rows.forEach(function (row) {
        row.style.display = visibleSet.has(row) ? "" : "none";
      });

      if (pagination) {
        var prevDisabled = currentPage <= 1 ? "disabled" : "";
        var nextDisabled = currentPage >= totalPages ? "disabled" : "";
        pagination.innerHTML =
          '<button type="button" class="button-link" data-author-page="prev" ' + prevDisabled + ">Prev</button>" +
          " <strong>" + currentPage + "/" + totalPages + "</strong> " +
          '<button type="button" class="button-link" data-author-page="next" ' + nextDisabled + ">Next</button>";
      }
    }

    if (searchInput) {
      searchInput.addEventListener("input", function () {
        currentQuery = String(searchInput.value || "").toLowerCase().trim();
        currentPage = 1;
        render();
      });
    }

    table.querySelectorAll("thead [data-sort-key]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var nextKey = String(btn.getAttribute("data-sort-key") || "author");
        if (nextKey === currentSortKey) {
          currentSortDir = currentSortDir === "asc" ? "desc" : "asc";
        } else {
          currentSortKey = nextKey;
          currentSortDir = "asc";
        }
        render();
      });
    });

    if (pagination) {
      pagination.addEventListener("click", function (event) {
        var button = event.target.closest("[data-author-page]");
        if (!button || button.disabled) return;
        var direction = button.getAttribute("data-author-page");
        if (direction === "prev") currentPage = Math.max(1, currentPage - 1);
        if (direction === "next") currentPage += 1;
        render();
      });
    }

    render();
  }

  // Run on DOM ready
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initAllFilterBars();
      initPostPickers();
      initSiteSettingsTemplatePicker();
      initLocationsTable();
      initAuthorProfilesTable();
    });
  } else {
    initAllFilterBars();
    initPostPickers();
    initSiteSettingsTemplatePicker();
    initLocationsTable();
    initAuthorProfilesTable();
  }

  // ─── 2. Progression Timeline Toggle ────────────────────────────────────────
  // Use document-level delegation so it works regardless of bar scope
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

  // ─── "Currently applied" toggle ────────────────────────────────────────────
  document.addEventListener("click", function (e) {
    var btn = e.target.closest(".seoworkerai-toggle-current");
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
    var isEdit = e.target.getAttribute("data-seoworkerai-edit-toggle") === "1";
    var isCancel = e.target.getAttribute("data-seoworkerai-cancel-button") === "1";
    if (!isEdit && !isCancel) return;

    var container = e.target.closest(".seoworkerai-inline-edit-container");
    if (!container) return;

    var displayView = container.querySelector(".seoworkerai-inline-display");
    var editView = container.querySelector(".seoworkerai-inline-edit-fields");
    var editBtn = container.querySelector('[data-seoworkerai-edit-toggle="1"]');
    var saveBtn = container.querySelector('[data-seoworkerai-save-button="1"]');
    var cancelBtn = container.querySelector('[data-seoworkerai-cancel-button="1"]');

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

  // Inline edit validation before submit
  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!form || form.tagName !== "FORM") return;
    var actionInput = form.querySelector('input[name="action"]');
    if (!actionInput || actionInput.value !== "seoworkerai_edit_action_payload") return;

    var errors = [];
    var fields = form.querySelectorAll(
      ".seoworkerai-inline-edit-fields input[name^='payload_fields'], .seoworkerai-inline-edit-fields textarea[name^='payload_fields']",
    );

    fields.forEach(function (field) {
      var raw = field.value || "";
      var val = raw.trim();
      var label = field.closest("label")
        ? field.closest("label").childNodes[0].textContent.trim()
        : "Field";
      var minLen = parseInt(field.getAttribute("data-min-length") || "0", 10);
      var maxLen = parseInt(field.getAttribute("data-max-length") || "0", 10);
      var validation = field.getAttribute("data-validation") || "";

      if (minLen > 0 && val.length < minLen) {
        errors.push(label + " must be at least " + minLen + " characters.");
      }

      if (maxLen > 0 && val.length > maxLen) {
        errors.push(label + " must be " + maxLen + " characters or fewer.");
      }

      if (validation === "twitter_handle" && val) {
        var re = /^@?[A-Za-z0-9_]{1,15}$/;
        if (!re.test(val)) {
          errors.push(
            label +
              " must be a valid handle (1-15 chars, letters/numbers/underscore).",
          );
        }
      }

      if (validation === "json" && val) {
        try {
          JSON.parse(val);
        } catch (_err) {
          errors.push(label + " must be valid JSON.");
        }
      }
    });

    if (errors.length > 0) {
      e.preventDefault();
      alert(errors.join("\n"));
    }
  });

  // ─── 4. Excluded Pages Tag-Chip UI ─────────────────────────────────────────
  function initExclusionTagUI() {
    var container = document.getElementById("seoworkerai-exclusion-tag-ui");
    if (!container) return;

    var searchInp = container.querySelector(".seoworkerai-excl-search");
    var dropdown = container.querySelector(".seoworkerai-excl-dropdown");
    var chipsWrap = container.querySelector(".seoworkerai-excl-chips");
    var hiddenInp = document.getElementById("seoworkerai-exclusion-hidden");
    var allOptions = container.querySelectorAll(".seoworkerai-excl-option");

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
        '.seoworkerai-excl-option[data-id="' + id + '"]',
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
      var checkmark = opt.querySelector(".seoworkerai-excl-checkmark");
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
      var opt = e.target.closest(".seoworkerai-excl-option");
      if (opt) {
        var id = opt.getAttribute("data-id");
        var checkmark = opt.querySelector(".seoworkerai-excl-checkmark");
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
          '.seoworkerai-excl-option[data-id="' + rid + '"]',
        );
        if (matchOpt) {
          matchOpt.classList.remove("is-selected");
          var checkmark = matchOpt.querySelector(".seoworkerai-excl-checkmark");
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
