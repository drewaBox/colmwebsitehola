// Admin: Academic Management - Levels, Courses, Year Levels and Sections (api/academic.php).
// Every list has: Add, Edit (rename), Deactivate / Activate and Remove. An item that students already use is only deactivated.
(function () {
  const body = document.getElementById("academicBody");
  const head = document.getElementById("academicHead");
  const statusFilter = document.getElementById("academicStatusFilter");
  const search = document.getElementById("academicSearch");
  const addButton = document.getElementById("academicAddBtn");
  const courseGroupFilter = document.getElementById("academicCourseGroupFilter");
  const courseGroupSelect = document.getElementById("academicCourseGroup");
  let data = { levels: [], courses: [], year_levels: [], sections: [] };
  let tab = "levels";

  // What each tab shows: its title, the type name used by the API, and the columns (header + how to show the cell).
  const TABS = {
    levels: { type: "level", add: "Add Level", one: "level", columns: [["Level", i => `<strong>${escapeHtml(i.name)}</strong>`], ["Courses", i => i.children], ["Students", i => i.students]] },
    courses: { type: "course", add: "Add Course", one: "course", columns: [["Course", i => `<strong>${escapeHtml(i.name)}</strong>`], ["Level", i => escapeHtml(i.level)], ["Year levels", i => i.children], ["Students", i => i.students]] },
    year_levels: { type: "year_level", add: "Add Year Level", one: "year level", columns: [["Year level", i => `<strong>${escapeHtml(i.name)}</strong>`], ["Number", i => i.year], ["Course", i => `${escapeHtml(i.course)} <small>(${escapeHtml(i.level)})</small>`], ["Sections", i => i.children], ["Students", i => i.students]] },
    sections: { type: "section", add: "Add Section", one: "section", columns: [["Section", i => `<strong>${escapeHtml(i.name)}</strong>`], ["Year level", i => escapeHtml(i.year)], ["Course", i => `${escapeHtml(i.course)} <small>(${escapeHtml(i.level)})</small>`], ["Students", i => i.students]] }
  };

  async function load() {
    try {
      data = await Api.get("academic.php");
      render();
    } catch (error) {
      body.innerHTML = `<tr><td colspan="6" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  function render() {
    const def = TABS[tab];
    const text = search.value.trim().toLowerCase();
    const courseGroup = courseGroupSelect.value;
    const items = data[tab].filter(i =>
      (tab !== "courses" || i.level === courseGroup) &&
      (!statusFilter.value || (statusFilter.value === "active") === i.is_active) &&
      (text === "" || [i.name, i.level, i.course, i.year].join(" ").toLowerCase().includes(text)));
    const addText = tab === "courses"
      ? courseGroup === "COLLEGE" ? "Add College Course" : "Add Senior High Strand"
      : def.add;
    document.getElementById("academicAddText").textContent = addText;
    courseGroupFilter.hidden = tab !== "courses";
    const columns = tab === "courses"
      ? def.columns.map((column, index) => index === 0
        ? [courseGroup === "SENIOR HIGH SCHOOL" ? "Strand" : "Course", column[1]]
        : column)
      : def.columns;
    head.innerHTML = "<tr>" + columns.map(c => `<th>${c[0]}</th>`).join("") + "<th>Status</th><th>Actions</th></tr>";
    body.innerHTML = items.length ? items.map(i => `
      <tr class="${i.is_active ? "" : "row-inactive"}">${columns.map(c => `<td>${c[1](i)}</td>`).join("")}
        <td><span class="status-pill ${i.is_active ? "status-completed" : "status-rejected"}">${i.is_active ? "Active" : "Inactive"}</span></td>
        <td class="row-actions wrap">
          <button class="request-table-action" type="button" data-edit="${i.id}">Edit</button>
          <button class="request-table-action" type="button" data-toggle="${i.id}">${i.is_active ? "Deactivate" : "Activate"}</button>
          <button class="request-table-action danger" type="button" data-remove="${i.id}">Remove</button></td></tr>`).join("")
      : `<tr><td colspan="${def.columns.length + 2}" class="requests-empty">${data[tab].length ? "Nothing matches the filters." : "Nothing here yet. Use the Add button."}</td></tr>`;
  }

  // ---------- Add / Edit ----------
  const options = (list, valueOf, textOf) => list.map(i => `<option value="${valueOf(i)}">${escapeHtml(textOf(i))}</option>`).join("");

  function formWindow(item, parentLevel = null) {
    const def = TABS[tab];
    const editing = !!item;
    const courseKind = (parentLevel || (item && item.level)) === "SENIOR HIGH SCHOOL" ? "strand" : "course";
    const itemLabel = tab === "courses" ? courseKind : def.one;
    const formTitle = tab === "courses"
      ? `${editing ? "Edit" : "Add"} ${courseKind === "strand" ? "Senior High Strand" : "College Course"}`
      : `${editing ? "Edit " + def.one : def.add}`;
    let fields = "";
    if (!editing && tab === "courses") fields = `<label>School level<select class="request-text-input" name="parent_id" required><option value="">Select level</option>${data.levels.filter(l => l.is_active).map(l => `<option value="${l.id}"${l.name === parentLevel ? " selected" : ""}>${escapeHtml(l.name)}</option>`).join("")}</select></label>`;
    if (!editing && tab === "year_levels") {
      fields = `<label>Course<select class="request-text-input" name="parent_id" required><option value="">Select course</option>${options(data.courses.filter(c => c.is_active), c => c.id, c => `${c.name} (${c.level})`)}</select></label>
                <label>Year number<input class="request-text-input" name="year_number" type="number" min="1" max="12" step="1" required placeholder="1 for First Year, 11 for Grade 11"></label>`;
    }
    if (!editing && tab === "sections") fields = `<label>Year level<select class="request-text-input" name="parent_id" required><option value="">Select year level</option>${options(data.year_levels.filter(y => y.is_active), y => y.id, y => `${y.course} - ${y.name} (${y.level})`)}</select></label>`;
    const nameLabel = tab === "year_levels" ? "Year level name (for example Second Year)" : `Name of the ${itemLabel}`;
    const modal = Portal.openModal(`
      <h2>${formTitle}</h2>
      ${editing && item.students > 0 ? `<p class="notice notice-warning">${item.students} student(s) use this ${itemLabel}. Their records are renamed too.</p>` : ""}
      <form class="student-form" novalidate>
        ${fields}
        <label>${nameLabel}<input class="request-text-input" name="name" value="${escapeHtml(item ? item.name : "")}" required maxlength="${tab === "sections" ? 20 : 80}"></label>
        <p class="form-message error" role="alert"></p>
        <div class="detail-buttons"><button class="request-next-btn" type="submit">${editing ? "Save changes" : "Add"}</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`);
    const form = modal.querySelector("form");
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = form.querySelector(".form-message");
      message.textContent = "";
      const values = Object.fromEntries(new FormData(form));
      if (!values.name.trim() || (form.elements.parent_id && !values.parent_id) || (form.elements.year_number && !values.year_number)) { message.textContent = "Please complete all the fields."; return; }
      const body = { type: def.type, name: values.name, id: editing ? item.id : 0, parent_id: values.parent_id ? Number(values.parent_id) : 0, year_number: values.year_number };
      await Portal.busy(form.querySelector("button[type=submit]"), "Saving...", async () => {
        try {
          await Api.postJson("academic.php", body, { action: "save" });
          Portal.closeModal();
          Portal.toast(editing ? "Saved." : `${itemLabel[0].toUpperCase() + itemLabel.slice(1)} added.`, "success");
          window.clearStructureCache?.();             // the Add Student form and the filters read the new lists
          load();
        } catch (error) { message.textContent = error.message; }
      });
    });
  }

  // ---------- Deactivate / Remove (with a question first) ----------
  async function changeItem(action, item, button) {
    const def = TABS[tab];
    const used = item.students > 0 || item.children > 0;
    const usedText = "This item is currently being used by existing records. It will be deactivated instead of permanently deleted.";
    let question;
    if (action === "toggle" && item.is_active) question = `Are you sure you want to deactivate ${item.name}?` + (used ? `\n\n${usedText}` : "");
    else if (action === "toggle") question = `Activate ${item.name} again?`;
    else question = used ? `Are you sure you want to remove ${item.name}?\n\n${usedText}` : `Are you sure you want to remove ${item.name}? Nothing uses it, so it will be deleted permanently.`;
    if (!window.confirm(question)) return;
    await Portal.busy(button, "Saving...", async () => {
      try {
        const result = await Api.postJson("academic.php", { type: def.type, id: item.id }, { action });
        Portal.toast(result.message || (result.is_active ? "Activated." : "Deactivated."), "success");
        window.clearStructureCache?.();
        load();
      } catch (error) { Portal.toast(error.message, "error"); }
    });
  }

  // ---------- events ----------
  body.addEventListener("click", event => {
    const button = event.target.closest("button[data-edit], button[data-toggle], button[data-remove]");
    if (!button) return;
    const id = Number(button.dataset.edit || button.dataset.toggle || button.dataset.remove);
    const item = data[tab].find(i => i.id === id);
    if (!item) return;
    if (button.dataset.edit) formWindow(item);
    else changeItem(button.dataset.toggle ? "toggle" : "remove", item, button);
  });
  addButton.addEventListener("click", () => formWindow(null, tab === "courses" ? courseGroupSelect.value : null));
  document.querySelectorAll("[data-academic-tab]").forEach(button => button.addEventListener("click", () => {
    tab = button.dataset.academicTab;
    document.querySelectorAll("[data-academic-tab]").forEach(b => { b.classList.toggle("active", b === button); b.setAttribute("aria-selected", String(b === button)); });
    render();
  }));
  courseGroupSelect.addEventListener("change", render);
  statusFilter.addEventListener("change", render);
  search.addEventListener("input", render);
  document.getElementById("academicReset").addEventListener("click", () => { courseGroupSelect.value = "COLLEGE"; statusFilter.value = ""; search.value = ""; render(); });
  document.addEventListener("page:change", event => { if (event.detail === "Academic Management") load(); });
})();
