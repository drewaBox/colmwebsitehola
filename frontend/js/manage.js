// Staff pages (admin/registrar and cashier): work queue and staff accounts.
(function () {
  let user = null;

  // ---------- Dashboard: requests that need this person's attention ----------
  async function loadQueue() {
    const body = document.getElementById("queueBody");
    const hint = document.getElementById("queueHint");
    // Admin and registrar: requests waiting for review.
    const wanted = { params: { status: "FOR_REVIEW" }, text: "Requests waiting for review." };
    try {
      const data = await Api.get("requests.php", { action: "list", ...wanted.params });
      let rows = data.requests;
      hint.textContent = wanted.text;
      body.innerHTML = rows.length ? rows.slice(0, 8).map(r => `
        <tr><td>${escapeHtml(r.tracking_no)}</td><td>${escapeHtml(r.full_name)}</td>
        <td>${r.items.map(i => escapeHtml(i.name)).join("<br>")}</td><td>${window.statusPill(r.status)}</td>
        <td><button class="request-table-action" type="button" data-open="${r.id}">Review</button></td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">Nothing is waiting right now.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }
  document.getElementById("queueBody").addEventListener("click", event => {
    const button = event.target.closest("[data-open]");
    if (button) window.openRequestDetail(Number(button.dataset.open));
  });

  // ---------- Staff accounts (admin/registrar) ----------
  const staffRoleLabel = role => ({ admin: "Admin", registrar: "Registrar", cashier: "Cashier" }[role] || role);
  const usersBody = document.getElementById("usersBody");
  const roleFilter = document.getElementById("userRoleFilter");
  const userStatusFilter = document.getElementById("userStatusFilter");
  let userLoadCounter = 0;

  async function loadUsers() {
    if (!usersBody) return;
    const params = {};
    if (roleFilter.value) params.role = roleFilter.value;
    if (userStatusFilter.value) params.status = userStatusFilter.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    const myTurn = ++userLoadCounter;
    try {
      const users = (await Api.get("users.php", params)).users;
      if (myTurn !== userLoadCounter) return;               // a newer search already started
      usersBody.innerHTML = users.length ? users.map(u => `
        <tr><td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.full_name)}</td><td>${escapeHtml(staffRoleLabel(u.role))}</td>
        <td>${u.is_active ? "Active" : "Disabled"}</td>
        <td><button class="request-table-action" type="button" data-toggle-user="${u.id}">${u.is_active ? "Disable" : "Enable"}</button>
            <button class="request-table-action" type="button" data-reset-user="${u.id}" data-name="${escapeHtml(u.full_name)}">Reset password</button></td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">No users found.</td></tr>';
      document.dispatchEvent(new CustomEvent("table:rendered"));
    } catch (error) {
      if (myTurn !== userLoadCounter) return;
      usersBody.innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // Create an Admin, Registrar or Cashier account. The server checks everything again.
  document.getElementById("userForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const message = document.getElementById("userMessage");
    message.className = "form-message";
    const values = {
      full_name: document.getElementById("newFullName").value.trim(),
      username: document.getElementById("newUsername").value.trim(),
      email: document.getElementById("newEmail").value.trim(),
      role: document.getElementById("newRole").value,
      password: document.getElementById("newPasswordUser").value,
      confirm_password: document.getElementById("newPasswordConfirm").value
    };
    // The same checks as the server, so the person gets the answer without waiting.
    const fail = text => { message.textContent = text; message.classList.add("error"); };
    if (!values.full_name || !values.username || !values.role || !values.password || !values.confirm_password) { fail("Please complete all required fields."); return; }
    if (values.password !== values.confirm_password) { fail("Passwords do not match."); return; }
    await Portal.busy(form.querySelector("button[type=submit]"), "Creating...", async () => {
      try {
        const result = await Api.postJson("users.php", values, { action: "create" });
        form.reset();
        // The eye buttons: show both password boxes as hidden again
        form.querySelectorAll("input[type=text][id^=newPassword]").forEach(input => { input.type = "password"; });
        form.querySelectorAll(".eye-btn").forEach(button => { button.textContent = "visibility"; button.setAttribute("aria-label", "Show password"); });
        message.textContent = result.message;
        message.classList.add("success");
        loadUsers();
      } catch (error) {
        fail(error.message);
      }
    });
  });

  usersBody?.addEventListener("click", async event => {
    const toggle = event.target.closest("[data-toggle-user]");
    if (toggle) {
      await Portal.busy(toggle, "Saving...", async () => {
        await Api.postJson("users.php", { id: Number(toggle.dataset.toggleUser) }, { action: "toggle" });
        loadUsers();
      });
      return;
    }
    const reset = event.target.closest("[data-reset-user]");
    if (reset) {
      const password = window.prompt(`New password for ${reset.dataset.name} (at least 8 characters):`);
      if (!password) return;
      await Portal.busy(reset, "Saving...", async () => {
        await Api.postJson("users.php", { id: Number(reset.dataset.resetUser), password }, { action: "reset" });
        Portal.toast("Password changed.", "success");
      });
    }
  });
  roleFilter?.addEventListener("change", loadUsers);
  userStatusFilter?.addEventListener("change", loadUsers);
  document.getElementById("userFiltersReset")?.addEventListener("click", () => {
    roleFilter.value = ""; userStatusFilter.value = "";
    document.getElementById("searchInput").value = "";
    loadUsers();
  });

  document.getElementById("searchInput").addEventListener("input", () => {
    if (Portal.currentPage === "Accounts") loadUsers();
  });

  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (event.detail === "Overview & KPIs") loadQueue();
    if (event.detail === "Accounts") loadUsers();
  });
  document.addEventListener("portal:ready", event => { user = event.detail; });
  // ---------- Settings: check that the free AI tools are installed (admin) ----------
  document.getElementById("aiTestBtn")?.addEventListener("click", async event => {
    const list = document.getElementById("aiTestList");
    list.innerHTML = "<li>Checking...</li>";
    await Portal.busy(event.currentTarget, "Checking...", async () => {
      try {
        const result = await Api.postJson("ai_test.php", {}, {}, 60000);
        list.innerHTML = result.tools.map(t => `<li class="${t.ok ? "tool-ok" : "tool-bad"}"><strong>${t.ok ? "✓" : "✗"} ${escapeHtml(t.name)}</strong><br>${escapeHtml(t.message)}</li>`).join("");
      } catch (error) {
        list.innerHTML = `<li class="tool-bad">${escapeHtml(error.message)}</li>`;
      }
    });
  });

  // Refresh the work queue after a request changed.
  document.addEventListener("requests:changed", () => {
    if (Portal.currentPage === "Overview & KPIs") loadQueue();
  });
})();
