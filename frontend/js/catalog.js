// Admin/registrar: Catalog management - add, edit, delete, activate / deactivate documents and their requirements.
(function () {
  const body = document.getElementById("catalogAdminBody");
  const CATEGORIES = ["Certification", "Academic Records", "Clearance", "Other"];
  let documents = [];

  const categoryFilter = document.getElementById("catalogCategoryFilter");
  const statusFilter = document.getElementById("catalogStatusFilter");

  // The whole catalog is loaded once; the filters and the search box only choose which rows are shown.
  async function loadCatalog() {
    try {
      documents = (await Api.get("documents.php")).documents;
      const chosen = categoryFilter.value;
      categoryFilter.innerHTML = '<option value="">All categories</option>' + [...new Set(documents.map(d => d.category))].sort().map(c => `<option>${escapeHtml(c)}</option>`).join("");
      categoryFilter.value = [...categoryFilter.options].some(o => o.value === chosen) ? chosen : "";
      renderCatalog();
    } catch (error) {
      body.innerHTML = `<tr><td colspan="6" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  function renderCatalog() {
    const text = document.getElementById("searchInput").value.trim().toLowerCase();
    const shown = documents.filter(d =>
      (!categoryFilter.value || d.category === categoryFilter.value) &&
      (!statusFilter.value || (statusFilter.value === "active") === d.is_active) &&
      (text === "" || (d.name + " " + d.category + " " + (d.description || "")).toLowerCase().includes(text)));
    body.innerHTML = shown.length ? shown.map(d => `
        <tr class="${d.is_active ? "" : "row-inactive"}">
          <td><strong>${escapeHtml(d.name)}</strong><br><small>${escapeHtml(d.description || "")}</small></td>
          <td>${escapeHtml(d.category)}</td>
          <td>${d.requirements.length ? d.requirements.map(r => escapeHtml(r.name) + (r.is_required ? "" : " (optional)")).join("<br>") : '<span class="muted">None</span>'}</td>
          <td>${Portal.formatMoney(d.price)}</td>
          <td><span class="status-pill ${d.is_active ? "status-completed" : "status-rejected"}">${d.is_active ? "Active" : "Inactive"}</span></td>
          <td class="row-actions">
            <button class="request-table-action" type="button" data-edit="${d.id}">Edit</button>
            <button class="request-table-action" type="button" data-toggle="${d.id}">${d.is_active ? "Deactivate" : "Activate"}</button>
            <button class="request-table-action danger" type="button" data-delete="${d.id}">Delete</button>
          </td></tr>`).join("")
      : `<tr><td colspan="6" class="requests-empty">${documents.length ? "No documents match the filters." : "No documents yet. Click Add Document."}</td></tr>`;
  }

  function requirementRow(req = {}) {
    const types = (req.allowed_types || "pdf,jpg,jpeg,png").split(",");
    const row = document.createElement("div");
    row.className = "requirement-editor";
    row.dataset.id = req.id || 0;
    row.innerHTML = `
      <div class="req-line">
        <input class="request-text-input req-name" placeholder="Requirement name (e.g. Valid school ID)" value="${escapeHtml(req.name || "")}" maxlength="150" aria-label="Requirement name">
        <button type="button" class="mini-btn bad req-remove">Remove</button>
      </div>
      <input class="request-text-input req-description" placeholder="Description shown to the student" value="${escapeHtml(req.description || "")}" maxlength="1000" aria-label="Requirement description">
      <input class="request-text-input req-hint" placeholder="Short note about this document (for the registrar)" value="${escapeHtml(req.ai_hint || "")}" maxlength="1000" aria-label="Note">
      <input class="request-text-input req-keywords" placeholder="Words the document must contain, separated by commas (e.g. student,id,school). Used by the automatic check." value="${escapeHtml(req.keywords || "")}" maxlength="255" aria-label="Keywords">
      <div class="req-options">
        <label><input type="checkbox" class="req-required" ${req.is_required === false ? "" : "checked"}> Required</label>
        <label><input type="checkbox" class="req-name-check" ${req.requires_name === false ? "" : "checked"}> Student's name must appear on it</label>
        ${["pdf", "jpg", "jpeg", "png"].map(t => `<label><input type="checkbox" class="req-type" value="${t}" ${types.includes(t) ? "checked" : ""}> ${t.toUpperCase()}</label>`).join("")}
        <label>Max MB <input type="number" class="request-text-input req-size" min="1" max="10" value="${req.max_size_mb || 3}"></label>
      </div>`;
    return row;
  }

  function documentForm(doc) {
    const d = doc || { id: 0, name: "", description: "", category: "Certification", price: 0, processing_min_days: 1, processing_max_days: 3, release_methods: ["Hardcopy"], is_active: true, requirements: [] };
    const editing = d.id > 0;
    const categories = CATEGORIES.includes(d.category) ? CATEGORIES : [...CATEGORIES, d.category];
    const modal = Portal.openModal(`
      <h2>${editing ? "Edit Document" : "Add Document"}</h2>
      <form class="student-form" novalidate>
        <label>Document name / title<input class="request-text-input" name="name" value="${escapeHtml(d.name)}" required maxlength="150"></label>
        <label>Description<textarea class="detail-remarks" name="description" rows="2" maxlength="2000">${escapeHtml(d.description || "")}</textarea></label>
        <label>Category / type<input class="request-text-input" name="category" list="categoryList" value="${escapeHtml(d.category)}" required maxlength="60">
          <datalist id="categoryList">${categories.map(c => `<option value="${escapeHtml(c)}">`).join("")}</datalist></label>
        <div class="form-row">
          <label>Price (PHP)<input class="request-text-input" name="price" type="number" min="0" max="100000" step="0.01" value="${d.price}" required></label>
          <label>Processing working days (from)<input class="request-text-input" name="processing_min_days" type="number" min="0" max="365" value="${d.processing_min_days}"></label>
          <label>to<input class="request-text-input" name="processing_max_days" type="number" min="0" max="365" value="${d.processing_max_days}"></label>
        </div>
        <fieldset class="form-row"><legend>Release methods</legend>
          <label><input type="checkbox" name="release" value="Hardcopy" ${d.release_methods.includes("Hardcopy") ? "checked" : ""}> Hardcopy</label>
          <label><input type="checkbox" name="release" value="Softcopy" ${d.release_methods.includes("Softcopy") ? "checked" : ""}> Softcopy</label>
        </fieldset>
        <label class="inline-check"><input type="checkbox" name="is_active" ${d.is_active ? "checked" : ""}> Active (students can request it)</label>
        <h3>File / document requirements</h3>
        <div id="requirementList"></div>
        <button type="button" class="request-back-btn" id="addRequirement">+ Add requirement</button>
        <p class="form-message error" role="alert"></p>
        <div class="detail-buttons"><button class="request-next-btn" type="submit">${editing ? "Save changes" : "Add document"}</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`, true);

    const form = modal.querySelector("form");
    const list = modal.querySelector("#requirementList");
    d.requirements.forEach(req => list.append(requirementRow(req)));
    modal.querySelector("#addRequirement").addEventListener("click", () => list.append(requirementRow()));
    list.addEventListener("click", event => { if (event.target.closest(".req-remove")) event.target.closest(".requirement-editor").remove(); });

    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = form.querySelector(".form-message");
      message.textContent = "";
      const payload = {
        id: d.id,
        name: form.elements.name.value,
        description: form.elements.description.value,
        category: form.elements.category.value,
        price: form.elements.price.value,
        processing_min_days: form.elements.processing_min_days.value,
        processing_max_days: form.elements.processing_max_days.value,
        release_methods: [...form.querySelectorAll("input[name=release]:checked")].map(i => i.value),
        is_active: form.elements.is_active.checked,
        requirements: [...list.querySelectorAll(".requirement-editor")].map(row => ({
          id: Number(row.dataset.id),
          name: row.querySelector(".req-name").value,
          description: row.querySelector(".req-description").value,
          ai_hint: row.querySelector(".req-hint").value,
          keywords: row.querySelector(".req-keywords").value,
          is_required: row.querySelector(".req-required").checked,
          requires_name: row.querySelector(".req-name-check").checked,
          allowed_types: [...row.querySelectorAll(".req-type:checked")].map(i => i.value),
          max_size_mb: row.querySelector(".req-size").value
        }))
      };
      await Portal.busy(form.querySelector("button[type=submit]"), "Saving...", async () => {
        try {
          await Api.postJson("documents.php", payload, { action: "save" });
          Portal.closeModal();
          Portal.toast(editing ? "Document saved." : "Document added to the catalog.", "success");
          loadCatalog();
        } catch (error) { message.textContent = error.message; }
      });
    });
  }

  document.getElementById("addDocumentBtn").addEventListener("click", () => documentForm(null));

  body.addEventListener("click", async event => {
    const button = event.target.closest("button[data-edit], button[data-toggle], button[data-delete]");
    if (!button) return;
    const id = Number(button.dataset.edit || button.dataset.toggle || button.dataset.delete);
    const doc = documents.find(d => d.id === id);
    if (button.dataset.edit) documentForm(doc);
    else if (button.dataset.toggle) {
      await Portal.busy(button, "Saving...", async () => {
        await Api.postJson("documents.php", { id }, { action: "toggle" });
        loadCatalog();
      });
    } else if (button.dataset.delete) {
      if (!window.confirm(`Delete "${doc.name}" and its requirements? This cannot be undone.`)) return;
      await Portal.busy(button, "Deleting...", async () => {
        await Api.postJson("documents.php", { id }, { action: "delete" });
        Portal.toast("Document deleted.", "success");
        loadCatalog();
      });
    }
  });

  categoryFilter.addEventListener("change", renderCatalog);
  statusFilter.addEventListener("change", renderCatalog);
  document.getElementById("catalogFiltersReset").addEventListener("click", () => {
    categoryFilter.value = ""; statusFilter.value = "";
    document.getElementById("searchInput").value = "";
    renderCatalog();
  });
  document.getElementById("searchInput").addEventListener("input", () => { if (Portal.currentPage === "Catalog") renderCatalog(); });
  document.addEventListener("page:change", event => { if (event.detail === "Catalog") loadCatalog(); });
})();
