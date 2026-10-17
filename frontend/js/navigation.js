// Sidebar, page switching, search, theme, toasts and pop-up windows shared by all portal pages.
const sidebar = document.getElementById("sidebar");
const main = document.querySelector(".main");
const content = document.getElementById("content");
const pageTitle = document.getElementById("pageTitle");
const pageIcon = document.getElementById("pageIcon");
const sidebarBackdrop = document.getElementById("sidebarBackdrop");

const pageSections = {
  "Dashboard": ".dashboard",
  "Overview & KPIs": ".dashboard",
  "Document Catalog": ".catalog",
  "My Requests": ".requests",
  "All Requests": ".requests",
  "Payment Dashboard": ".payment-dashboard-page",
  "Pending Payments": ".pending-payments-page",
  "Payment History": ".payment-history-page",
  "Download Requests": ".download-requests-page",
  "Academic Management": ".academic-page",
  "Students": ".students-page",
  "Catalog": ".catalog-admin-page",
  "Accounts": ".users-page",
  "My Profile": ".profile-page",
  "Help & FAQs": ".help-page",
  "Settings": ".settings-page",
  "Organizational Chart": ".org-chart"
};
const pageIcons = {
  "Dashboard": "dashboard", "Overview & KPIs": "home", "Document Catalog": "description",
  "My Requests": "assignment_turned_in", "All Requests": "assignment", "Payment Dashboard": "payments", "Pending Payments": "credit_card", "Payment History": "receipt_long", "Download Requests": "download_for_offline", "Academic Management": "account_balance", "Students": "school",
  "Catalog": "description", "Accounts": "groups", "My Profile": "badge", "Help & FAQs": "quiz", "Settings": "settings", "Organizational Chart": "account_tree"
};

// ---- Things other files can use: Portal.goToPage, Portal.toast, Portal.openModal ----
const Portal = {
  currentPage: "",

  goToPage(name) {
    // A page whose menu item was removed for this role (for example a cashier asking for "Students") is refused.
    // The PHP files refuse these people as well - this only keeps the screen tidy.
    if (window.portalUser && !document.querySelector(`.nav-item[data-page="${name}"]`)) {
      name = document.querySelector(".nav-item")?.dataset.page;
    }
    if (window.portalUser?.must_change_password && name !== "Settings") {
      Portal.toast("Please change your default password first.", "warning");
      name = "Settings";
    }
    const selector = pageSections[name];
    if (!selector || !document.querySelector(selector)) return;
    Portal.currentPage = name;
    Object.values(pageSections).forEach(sel => {
      const section = document.querySelector(sel);
      if (section) section.hidden = true;
    });
    document.querySelector(selector).hidden = false;
    content.classList.toggle("content-page-bg", ["Document Catalog", "My Requests", "All Requests"].includes(name));
    pageTitle.textContent = name;
    pageIcon.textContent = pageIcons[name];
    document.querySelectorAll(".nav-item").forEach(item => item.classList.toggle("active", item.dataset.page === name));
    applySearch();
    document.dispatchEvent(new CustomEvent("page:change", { detail: name }));
    if (window.innerWidth <= 800) closeMobileSidebar();
  },

  // type: "success" | "error" | "warning" | "info". The optional title is shown in bold above the message.
  toast(message, type = "info", title = "", durationMs = type === "error" ? 8000 : 5000) {
    let box = document.getElementById("toastBox");
    if (!box) {
      box = document.createElement("div");
      box.id = "toastBox";
      box.className = "toast-box";
      box.setAttribute("aria-live", "polite");
      document.body.append(box);
    }
    const icons = { success: "check_circle", error: "error", warning: "warning", info: "info" };
    const toast = document.createElement("div");
    toast.className = "toast toast-" + type;
    toast.setAttribute("role", type === "error" ? "alert" : "status");
    const icon = document.createElement("span");
    icon.className = "material-symbol toast-icon";
    icon.textContent = icons[type] || "info";
    const text = document.createElement("div");
    text.className = "toast-text";
    if (title) {
      const strong = document.createElement("strong");
      strong.textContent = title;
      text.append(strong);
    }
    const body = document.createElement("span");
    body.textContent = message;           // textContent: a message can never inject HTML
    text.append(body);
    const close = document.createElement("button");
    close.type = "button";
    close.className = "toast-close material-symbol";
    close.setAttribute("aria-label", "Close");
    close.textContent = "close";
    close.addEventListener("click", () => toast.remove());
    toast.append(icon, text, close);
    box.append(toast);
    setTimeout(() => toast.remove(), durationMs);
  },

  // Opens a pop-up window with the given HTML. Returns the modal element.
  openModal(html, wide = false) {
    Portal.closeModal();
    const backdrop = document.createElement("div");
    backdrop.className = "modal-backdrop";
    backdrop.id = "modalBackdrop";
    backdrop.innerHTML = `<div class="modal${wide ? " modal-wide" : ""}" role="dialog" aria-modal="true">
      <button class="modal-close material-symbol" type="button" aria-label="Close" data-close-modal>close</button>
      <div class="modal-body">${html}</div></div>`;
    document.body.append(backdrop);
    document.body.classList.add("modal-open");           // the page behind does not scroll
    backdrop.addEventListener("click", event => {
      if (event.target === backdrop || event.target.closest("[data-close-modal]")) Portal.closeModal();
    });
    return backdrop.querySelector(".modal");
  },

  closeModal() {
    document.getElementById("modalBackdrop")?.remove();
    document.body.classList.remove("modal-open");
  },

  formatMoney(amount) {
    return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 2 }).format(amount);
  },

  // Accepts "2026-10-05" or "2026-10-05 13:20:00" from the server.
  formatDate(value, withTime = false) {
    if (!value) return "";
    const text = String(value);
    const date = /^\d{4}-\d{2}-\d{2}$/.test(text)
      ? new Date(`${text}T12:00:00`)
      : new Date(text.replace(" ", "T"));
    if (isNaN(date)) return "";
    return new Intl.DateTimeFormat("en-PH", withTime
      ? { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" }
      : { month: "short", day: "numeric", year: "numeric" }).format(date);
  },

  // Disables a button while an action runs, shows an error toast if it fails.
  async busy(button, text, action) {
    const original = button ? button.textContent : "";
    if (button) { button.disabled = true; button.textContent = text; }
    try {
      return await action();
    } catch (error) {
      Portal.toast(error.message, "error");
      return undefined;
    } finally {
      if (button && button.isConnected) { button.disabled = false; button.textContent = original; }
    }
  }
};

// ---- A "Previous / Page 1 of 3 / Next" bar under a table. onPage(number) is called when a button is pressed. ----
// Usage:  const pager = Portal.makePager(tableWrapElement, number => { page = number; load(); });  pager.render(page, total, perPage);
Portal.makePager = function (tableWrap, onPage) {
  const bar = document.createElement("div");
  bar.className = "pager";
  tableWrap.after(bar);
  bar.addEventListener("click", event => {
    const button = event.target.closest("button[data-page]");
    if (button && !button.disabled) onPage(Number(button.dataset.page));
  });
  return {
    render(page, total, perPage) {
      const pages = Math.max(1, Math.ceil(total / perPage));
      bar.innerHTML = pages <= 1 ? "" : `
        <button type="button" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}>Previous</button>
        <span>Page ${page} of ${pages}</span>
        <button type="button" data-page="${page + 1}" ${page >= pages ? "disabled" : ""}>Next</button>`;
    }
  };
};

// Runs fn after the person stopped typing for a moment (used by the search and the filter boxes).
Portal.debounce = function (fn, ms = 300) {
  let timer = null;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
};

// ---- Sidebar ----
function updateSidebarIcon() {
  const isOpen = window.innerWidth <= 800 ? sidebar.classList.contains("open") : !sidebar.classList.contains("collapsed");
  document.getElementById("sidebarIcon").textContent = isOpen ? "left_panel_close" : "left_panel_open";
  document.getElementById("mobileSidebarIcon").textContent = sidebar.classList.contains("open") ? "left_panel_close" : "left_panel_open";
}
function closeMobileSidebar() {
  sidebar.classList.remove("open");
  sidebarBackdrop.classList.remove("show");
  updateSidebarIcon();
}
function toggleSidebar() {
  if (window.innerWidth <= 800) {
    sidebar.classList.toggle("open");
    sidebarBackdrop.classList.toggle("show");
  } else {
    sidebar.classList.toggle("collapsed");
    main.classList.toggle("expanded");
  }
  updateSidebarIcon();
}
document.getElementById("collapseBtn").addEventListener("click", toggleSidebar);
document.getElementById("mobileSidebarBtn").addEventListener("click", toggleSidebar);
sidebarBackdrop.addEventListener("click", closeMobileSidebar);
window.addEventListener("resize", updateSidebarIcon);
updateSidebarIcon();

// ---- Menu clicks (works for menu items and for links like "View requests") ----
document.addEventListener("click", event => {
  const link = event.target.closest(".nav-item[data-page], [data-page-action]");
  if (!link) return;
  event.preventDefault();
  Portal.goToPage(link.dataset.page || link.dataset.pageAction);
});

// Links like "Ask the chatbot" open the floating chat window.
document.addEventListener("click", event => {
  const link = event.target.closest("[data-open-chat]");
  if (!link) return;
  event.preventDefault();
  window.openChat?.();
});

// First page after login
document.addEventListener("portal:ready", event => {
  Portal.goToPage(document.querySelector(".nav-item")?.dataset.page);
  // An account with a default password (for example an imported student) must choose its own password first.
  if (event.detail.must_change_password) {
    Portal.goToPage("Settings");
    const banner = document.createElement("div");
    banner.className = "notice notice-warning";
    banner.id = "passwordBanner";
    banner.textContent = "Welcome! For your safety please change your default password now. The other pages unlock after that.";
    document.getElementById("passwordForm").before(banner);
  }
});

// ---- Eye button next to a password box: only switches the box between password and text ----
document.addEventListener("click", event => {
  const eye = event.target.closest("[data-eye]");
  if (!eye) return;
  const input = document.getElementById(eye.dataset.eye);
  const show = input.type === "password";
  input.type = show ? "text" : "password";
  eye.textContent = show ? "visibility_off" : "visibility";
  eye.setAttribute("aria-label", show ? "Hide password" : "Show password");
});

// ---- Profile menu ----
const profileMenu = document.getElementById("profileMenu");
const profileDropdown = document.getElementById("profileDropdown");
profileMenu.addEventListener("click", event => {
  event.stopPropagation();
  profileDropdown.classList.toggle("show");
});
document.addEventListener("click", () => profileDropdown.classList.remove("show"));
// Escape closes the profile menu and the phone menu (windows, the chat and the notification list close themselves)
document.addEventListener("keydown", event => {
  if (event.key !== "Escape") return;
  profileDropdown.classList.remove("show");
  if (window.innerWidth <= 800 && sidebar.classList.contains("open")) closeMobileSidebar();
});

// ---- Search: hides table rows and document cards that do not match ----
const searchControl = document.getElementById("searchControl");
const searchToggle = document.getElementById("searchToggle");
const searchInput = document.getElementById("searchInput");

function setSearchOpen(isOpen) {
  searchControl.classList.toggle("open", isOpen);
  searchToggle.setAttribute("aria-expanded", String(isOpen));
  searchInput.hidden = !isOpen;
  if (isOpen) searchInput.focus();
}
// The search box hides catalog cards that do not match. Tables are searched by the server (so records on other pages are
// found too, and a record that matches by a field the table does not show is never hidden by mistake).
function applySearch() {
  const text = searchInput.value.trim().toLowerCase();
  content.querySelectorAll(".doc-item").forEach(card => {
    card.hidden = text !== "" && !card.textContent.toLowerCase().includes(text);
  });
}
searchToggle.addEventListener("click", () => setSearchOpen(!searchControl.classList.contains("open")));
document.addEventListener("click", event => { if (!searchControl.contains(event.target)) setSearchOpen(false); });
searchInput.addEventListener("keydown", event => {
  if (event.key === "Escape") { setSearchOpen(false); searchToggle.focus(); }
});
searchInput.addEventListener("input", applySearch);
document.addEventListener("table:rendered", applySearch);

// ---- Theme ----
const themeIcon = document.getElementById("themeIcon");
function applyTheme(dark) {
  document.body.classList.toggle("dark", dark);
  themeIcon.textContent = dark ? "light_mode" : "dark_mode";
}
applyTheme(localStorage.getItem("colmTheme") === "dark");
document.getElementById("themeBtn").addEventListener("click", event => {
  event.stopPropagation();
  const dark = !document.body.classList.contains("dark");
  localStorage.setItem("colmTheme", dark ? "dark" : "light");
  applyTheme(dark);
});

// ---- Date on the dashboard, Esc closes pop-ups ----
document.getElementById("dashboardDate").textContent = new Intl.DateTimeFormat("en-US", {
  weekday: "long", month: "long", day: "numeric", year: "numeric"
}).format(new Date());
document.addEventListener("keydown", event => { if (event.key === "Escape") Portal.closeModal(); });

// ---- Settings page: change password (same form for every role) ----
const passwordForm = document.getElementById("passwordForm");
if (passwordForm) {
  passwordForm.addEventListener("submit", async event => {
    event.preventDefault();
    const message = document.getElementById("passwordMessage");
    const current = document.getElementById("currentPassword").value;
    const next = document.getElementById("newPassword").value;
    message.className = "form-message";
    if (next.length < 8) { message.textContent = "The new password must be at least 8 characters."; message.classList.add("error"); return; }
    if (next !== document.getElementById("confirmPassword").value) { message.textContent = "The new passwords do not match."; message.classList.add("error"); return; }
    await Portal.busy(passwordForm.querySelector("button[type=submit]"), "Saving...", async () => {
      try {
        await Api.postJson("password.php", { current_password: current, new_password: next });
        passwordForm.reset();
        message.textContent = "Password changed.";
        message.classList.add("success");
        if (window.portalUser?.must_change_password) window.location.reload();   // unlock the portal
      } catch (error) {
        message.textContent = error.message;
        message.classList.add("error");
      }
    });
  });
}
