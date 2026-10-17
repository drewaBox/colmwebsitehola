// The bell icon: unread count, list, mark as read, delete.
(function () {
  const bell = document.getElementById("notifBtn");
  const badge = document.getElementById("notifBadge");
  let panel = null;
  let latestNotificationId = 0;
  let initialized = false;
  let refreshing = false;
  let pollTimer = null;

  function updateBadge(count) {
    badge.textContent = count > 99 ? "99+" : count;
    badge.hidden = count === 0;
  }

  async function refreshNotifications(showPopups = false) {
    if (refreshing) return;
    refreshing = true;
    try {
      const params = initialized ? { after_id: latestNotificationId } : {};
      const data = await Api.get("notifications.php", params);
      updateBadge(data.unread);
      const newNotifications = initialized ? data.notifications : [];
      if (!initialized) {
        latestNotificationId = data.notifications.reduce((max, note) => Math.max(max, Number(note.id)), 0);
        initialized = true;
      }
      for (const note of newNotifications) {
        const id = Number(note.id);
        if (id <= latestNotificationId) continue;
        latestNotificationId = id;
        if (showPopups) {
          const type = ["success", "error", "warning", "info"].includes(note.type) ? note.type : "info";
          Portal.toast(note.message, type, note.title, 5000);
        }
      }
      if (panel && !panel.hidden) render(await Api.get("notifications.php"));
    } catch (error) {
      console.error("Unable to refresh notifications.", error);
    } finally {
      refreshing = false;
    }
  }

  function render(data) {
    updateBadge(data.unread);
    const list = data.notifications.map(note => `
      <li class="notif-item${note.is_read ? "" : " unread"} notif-${escapeHtml(note.type)}" data-id="${note.id}" data-request-id="${note.request_id || ""}">
        <button class="notif-open" type="button">
          <strong>${escapeHtml(note.title)}</strong>
          <span>${escapeHtml(note.message)}</span>
          <time>${escapeHtml(Portal.formatDate(note.created_at, true))}</time>
        </button>
        <button class="notif-delete material-symbol" type="button" aria-label="Delete notification">delete</button>
      </li>`).join("");
    panel.innerHTML = `
      <div class="notif-header"><strong>Notifications</strong>
        <button class="notif-readall" type="button">Mark all as read</button></div>
      ${list ? `<ul class="notif-list">${list}</ul>` : '<p class="notif-empty">No notifications yet.</p>'}`;
  }

  async function openPanel() {
    if (!panel) {
      panel = document.createElement("div");
      panel.className = "notif-panel";
      bell.parentElement.append(panel);
      bell.parentElement.style.position = "relative";
      panel.addEventListener("click", onPanelClick);
    }
    panel.hidden = false;
    bell.setAttribute("aria-expanded", "true");
    panel.innerHTML = '<p class="notif-empty">Loading...</p>';
    try { render(await Api.get("notifications.php")); }
    catch (error) { panel.innerHTML = `<p class="notif-empty">${escapeHtml(error.message)}</p>`; }
  }

  function closePanel() {
    if (panel) panel.hidden = true;
    bell.setAttribute("aria-expanded", "false");
  }

  async function onPanelClick(event) {
    event.stopPropagation();
    const item = event.target.closest(".notif-item");
    try {
      if (event.target.closest(".notif-readall")) {
        await Api.postJson("notifications.php", {}, { action: "read_all" });
        render(await Api.get("notifications.php"));
      } else if (event.target.closest(".notif-delete")) {
        await Api.postJson("notifications.php", { id: Number(item.dataset.id) }, { action: "delete" });
        render(await Api.get("notifications.php"));
      } else if (event.target.closest(".notif-open")) {
        await Api.postJson("notifications.php", { id: Number(item.dataset.id) }, { action: "read" });
        closePanel();
        refreshNotifications();
        if (item.dataset.requestId) {
          Portal.goToPage(window.portalUser.role === "student" ? "My Requests" : "All Requests");
          window.openRequestDetail?.(Number(item.dataset.requestId));
        }
      }
    } catch (error) {
      Portal.toast(error.message, "error");
    }
  }

  bell.addEventListener("click", event => {
    event.stopPropagation();
    if (panel && !panel.hidden) closePanel(); else openPanel();
  });
  document.addEventListener("click", event => { if (panel && !panel.contains(event.target)) closePanel(); });
  // Escape closes the panel and gives the focus back to the bell
  document.addEventListener("keydown", event => {
    if (event.key === "Escape" && panel && !panel.hidden) { closePanel(); bell.focus(); }
  });

  document.addEventListener("portal:ready", () => {
    if (pollTimer) return;
    refreshNotifications();
    pollTimer = setInterval(() => refreshNotifications(true), 5000);
  });
  document.addEventListener("visibilitychange", () => {
    if (!document.hidden && initialized) refreshNotifications(true);
  });
  window.refreshNotifications = () => refreshNotifications(true);
})();
