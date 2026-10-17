// Soft copy (PDF) screens. All the rules are checked again in PHP (api/softcopy.php); this file only shows the buttons and sends the requests.
//   Student:  "My Document Requests" table (dashboard), Download PDF, Request Additional Download.
//   Staff:    the soft copy block inside the request window (upload, replace, mark ready, view, history),
//             the "Download Requests" page (approve / reject) and the download history window.
(function () {
  const isStudent = () => window.portalUser && window.portalUser.role === "student";
  const dateText = value => (value ? Portal.formatDate(value, true) : "-");
  // Reuse the colours of the existing status labels.
  const STATE_CLASS = { AVAILABLE: "ready", APPROVED: "ready", WAITING: "submitted", USED: "for_review", REQUEST_PENDING: "processing", REJECTED: "rejected", NONE: "submitted" };
  const statePill = state => `<span class="status-pill status-${STATE_CLASS[state.code] || "submitted"}">${escapeHtml(state.label)}</span>`;
  let reasons = [];

  function refreshAll(requestId) {
    if (requestId) window.openRequestDetail?.(requestId);
    window.reloadRequests?.();        // also reloads "My Document Requests" (requests:changed)
    loadDownloadRequests();
  }

  // ---------- Student: buttons ----------
  function studentButtons(item) {
    const s = item.softcopy;
    let html = "";
    if (s.can_download) html += `<button type="button" class="request-table-action" data-sc-download="${item.item_id}">Download PDF</button> `;
    if (s.can_request_more) html += `<button type="button" class="request-table-action" data-sc-more="${item.item_id}" data-doc="${escapeHtml(item.document_name)}" data-tracking="${escapeHtml(item.tracking_no)}">Request Additional Download</button>`;
    return html;
  }

  function softcopyCell(item) {
    const s = item.softcopy;
    if (!s.wanted) return '<span class="muted">-</span>';
    let html = statePill(s);
    if (["AVAILABLE", "APPROVED", "USED", "REQUEST_PENDING", "REJECTED"].includes(s.code)) html += `<br><small>Downloads used: ${s.downloads_used} of ${s.downloads_allowed}</small>`;
    if (s.rejection_reason) html += `<br><small>Reason: ${escapeHtml(s.rejection_reason)}</small>`;
    return html;
  }

  // ---------- Student: MY DOCUMENT REQUESTS ----------
  async function loadMine() {
    const body = document.getElementById("myDocsRequestsBody");
    if (!body || !isStudent()) return;
    try {
      const data = await Api.get("softcopy.php", { action: "mine" });
      reasons = data.reasons;
      body.innerHTML = data.items.length ? data.items.map(item => `
        <tr class="student-data-row"><td data-label="Request No.">${escapeHtml(item.tracking_no)}</td>
          <td data-label="Document">${escapeHtml(item.document_name)}${item.quantity > 1 ? ` <small>× ${item.quantity}</small>` : ""}</td>
          <td data-label="Format">${escapeHtml(formatShort(item.release_method))}</td>
          <td data-label="Status">${window.statusPill(item.status)}</td>
          <td data-label="Payment">${escapeHtml(item.payment_status)}</td>
          <td data-label="Soft copy">${softcopyCell(item)}</td>
          <td data-label="Actions" class="row-actions wrap">${studentButtons(item)}</td></tr>`).join("")
        : '<tr><td colspan="7" class="requests-empty">You have no document requests yet.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="7" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // The block shown under every document in the request window (student and staff).
  window.softcopyBlockHtml = function (request, item) {
    const s = item.softcopy;
    if (!s || !s.wanted) return "";
    if (isStudent()) {
      const row = { item_id: item.id, softcopy: s, document_name: item.name, tracking_no: request.tracking_no };
      return `<div class="softcopy-block"><strong>Soft Copy (PDF):</strong> ${statePill(s)}
        <small>Downloads used: ${s.downloads_used} of ${s.downloads_allowed}</small>
        ${s.rejection_reason ? `<p class="muted">Additional download rejected: ${escapeHtml(s.rejection_reason)}</p>` : ""}
        <div class="detail-buttons">${studentButtons(row) === '<span class="muted">-</span>' ? "" : studentButtons(row)}</div></div>`;
    }
    // staff
    const canUpload = ["PROCESSING", "READY"].includes(request.status) && (Number(request.total_amount) === 0 || request.payment_status === "Paid");
    const fileInfo = s.file_name
      ? `<p><strong>${escapeHtml(s.file_name)}</strong> (${Math.max(1, Math.round(s.file_size / 1024))} KB)<br>
         <small>Uploaded ${escapeHtml(dateText(s.uploaded_at))} by ${escapeHtml(s.uploaded_by || "-")}${s.ready_at ? ` · Ready since ${escapeHtml(dateText(s.ready_at))}` : " · Not marked ready yet"}</small></p>`
      : '<p class="muted">No PDF uploaded yet.</p>';
    const pending = s.pending_request
      ? `<div class="notice notice-warning"><strong>Additional download requested:</strong> ${escapeHtml(s.pending_request.reason_type)} - ${escapeHtml(s.pending_request.reason)}
         <div class="detail-buttons"><button type="button" class="mini-btn ok" data-sc-decide="approve" data-id="${s.pending_request.id}">Approve Additional Download</button>
         <button type="button" class="mini-btn bad" data-sc-decide="reject" data-id="${s.pending_request.id}">Reject Request</button></div></div>` : "";
    const upload = canUpload ? `<div class="release-row">
        <input type="file" class="softcopy-file" data-item-id="${item.id}" accept=".pdf,application/pdf" aria-label="PDF of ${escapeHtml(item.name)}">
        <button type="button" class="mini-btn" data-sc-upload="${item.id}">${s.file_name ? "Replace PDF" : "Upload Soft Copy"}</button>
        ${s.status === "UPLOADED" ? `<button type="button" class="mini-btn ok" data-sc-ready="${item.id}">Mark ready for release</button>` : ""}</div>`
      : '<p class="muted"><small>A PDF can be uploaded after the payment was confirmed and the request is processing.</small></p>';
    return `<div class="softcopy-block"><strong>Soft Copy (PDF):</strong> ${statePill(s)} <small>Downloads used: ${s.downloads_used} of ${s.downloads_allowed}</small>
      ${fileInfo}${pending}${upload}
      <div class="detail-buttons">${s.file_name ? `<a class="request-table-action" href="${API_URL}softcopy.php?action=view&item_id=${item.id}" target="_blank" rel="noopener">View PDF</a>` : ""}
        <button type="button" class="request-back-btn" data-sc-history="${item.id}">Download history</button></div></div>`;
  };

  // ---------- Student: download ----------
  async function downloadPdf(itemId, button) {
    await Portal.busy(button, "Downloading...", async () => {
      try {
        const { blob, filename } = await Api.postForFile("softcopy.php", { item_id: itemId }, { action: "download" });
        const link = document.createElement("a");
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(link.href), 4000);
        Portal.toast("Your download was recorded. If you need another copy later, use Request Additional Download.", "success", "Download started");
      } catch (error) {
        Portal.toast(error.message, "error", "Download not allowed");
      }
      refreshAll(modalRequestId());
    });
  }
  const modalRequestId = () => Number(document.querySelector("#modalBackdrop .modal-body")?.dataset.requestId) || 0;

  // ---------- Student: request an additional download ----------
  function moreWindow(itemId, documentName, tracking) {
    const modal = Portal.openModal(`
      <h2>Request Additional Download</h2>
      <p>${escapeHtml(documentName)} (${escapeHtml(tracking)})</p>
      <p class="muted">You already used your download. The registrar will review your reason.</p>
      <form class="student-form" novalidate>
        <label>Reason for Additional Download
          <select class="request-text-input" name="reason_type" required><option value="">Select a reason</option>${reasons.map(r => `<option>${escapeHtml(r)}</option>`).join("")}</select></label>
        <label>Details<textarea class="detail-remarks" name="reason" rows="3" maxlength="1000" required placeholder="Tell the registrar why you need another copy"></textarea></label>
        <p class="form-message error" role="alert"></p>
        <div class="detail-buttons"><button class="request-next-btn" type="submit">Send request</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`);
    const form = modal.querySelector("form");
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = form.querySelector(".form-message");
      message.textContent = "";
      if (!form.elements.reason_type.value || form.elements.reason.value.trim().length < 5) { message.textContent = "Please choose a reason and write the details."; return; }
      await Portal.busy(form.querySelector("button[type=submit]"), "Sending...", async () => {
        try {
          const result = await Api.postJson("softcopy.php", { item_id: itemId, reason_type: form.elements.reason_type.value, reason: form.elements.reason.value }, { action: "request_more" });
          Portal.closeModal();
          Portal.toast(result.message, "success", "Request sent");
          refreshAll(0);
        } catch (error) { message.textContent = error.message; }
      });
    });
  }

  // ---------- Staff: upload / ready ----------
  async function uploadPdf(itemId, button) {
    const requestId = modalRequestId();
    const input = document.querySelector(`#modalBackdrop .softcopy-file[data-item-id="${itemId}"]`);
    const file = input && input.files[0];
    if (!file) { Portal.toast("Choose a PDF file first.", "error"); return; }
    if (!/\.pdf$/i.test(file.name) || (file.type && file.type !== "application/pdf")) { Portal.toast("Only PDF files can be uploaded.", "error", "Invalid File"); return; }
    if (file.size > 10 * 1048576) { Portal.toast("The PDF must be 10 MB or smaller.", "error", "File too large"); return; }
    await Portal.busy(button, "Uploading...", async () => {
      const form = new FormData();
      form.append("item_id", itemId);
      form.append("file", file);
      try {
        await Api.postForm("requests.php", form, { action: "attach_release", id: requestId });
        Portal.toast("The PDF was saved. Mark it ready so the student can download it.", "success", "Soft copy uploaded");
      } catch (error) { Portal.toast(error.message, "error", "Upload failed"); }
      refreshAll(requestId);
    });
  }

  async function markReady(itemId, button) {
    const requestId = modalRequestId();
    await Portal.busy(button, "Saving...", async () => {
      try {
        const result = await Api.postJson("requests.php", { id: requestId, item_id: itemId }, { action: "softcopy_ready" });
        Portal.toast(result.all_ready ? "The soft copy is ready for release." : "Marked ready. Other documents still need their PDF.", "success", "Soft copy ready");
      } catch (error) { Portal.toast(error.message, "error"); }
      refreshAll(requestId);
    });
  }

  // ---------- Staff: approve / reject ----------
  function rejectWindow(id) {
    const modal = Portal.openModal(`
      <h2>Reject Request</h2>
      <form class="student-form" novalidate>
        <label>Reason shown to the student (optional)<textarea class="detail-remarks" name="reason" rows="3" maxlength="500"></textarea></label>
        <div class="detail-buttons"><button class="request-next-btn bad" type="submit">Reject Request</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`);
    const form = modal.querySelector("form");
    form.addEventListener("submit", async event => {
      event.preventDefault();
      await Portal.busy(form.querySelector("button[type=submit]"), "Saving...", async () => {
        await decide(id, "reject", form.elements.reason.value);
      });
    });
  }

  async function decide(id, decision, rejectionReason = "") {
    const requestId = modalRequestId();
    try {
      await Api.postJson("softcopy.php", { id, decision, rejection_reason: rejectionReason }, { action: "decide" });
      Portal.toast(decision === "approve" ? "The student can download once more." : "The request was rejected.", "success", decision === "approve" ? "Approved" : "Rejected");
      if (decision === "reject") Portal.closeModal();
    } catch (error) { Portal.toast(error.message, "error"); }
    refreshAll(requestId && document.querySelector("#modalBackdrop") ? requestId : 0);
  }

  // ---------- Staff: DOCUMENT DOWNLOAD HISTORY ----------
  async function historyWindow(itemId, backToRequest = 0) {
    try {
      const data = await Api.get("softcopy.php", { action: "history", item_id: itemId });
      const i = data.item;
      const rows = data.downloads.map(d => {
        if (d.download_type === "STAFF") return `<li><strong>Viewed by staff</strong><br><small>${escapeHtml(dateText(d.downloaded_at))} · ${escapeHtml(d.by_name || "-")}${d.ip_address ? " · IP " + escapeHtml(d.ip_address) : ""}</small></li>`;
        const extra = d.download_type === "ADDITIONAL"
          ? `<br>Reason: ${escapeHtml(d.reason_type || "-")}${d.reason ? " - " + escapeHtml(d.reason) : ""}<br>Status: ${escapeHtml(d.approval_status === "USED" ? "Approved" : d.approval_status || "-")}<br>Approved by: ${escapeHtml(d.approved_by_name || "-")} (${escapeHtml(dateText(d.approved_at))})`
          : "<br>Status: Original Download";
        return `<li><strong>Download #${d.download_number}</strong><br>${escapeHtml(dateText(d.downloaded_at))}${extra}
          <br><small>Downloaded by ${escapeHtml(d.by_name || "-")}${d.ip_address ? " · IP " + escapeHtml(d.ip_address) : ""}</small></li>`;
      }).join("");
      const requests = data.additional_requests.map(q => `<li>${escapeHtml(dateText(q.created_at))} · <strong>${escapeHtml(q.status)}</strong> · ${escapeHtml(q.reason_type)}${q.decided_by_name ? " · by " + escapeHtml(q.decided_by_name) : ""}${q.rejection_reason ? "<br><small>Rejected: " + escapeHtml(q.rejection_reason) + "</small>" : ""}</li>`).join("");
      Portal.openModal(`
        <h2>Document Download History</h2>
        <dl class="detail-grid">
          <div><dt>Student</dt><dd>${escapeHtml(i.student_name)} (${escapeHtml(i.student_no || "")})</dd></div>
          <div><dt>Document</dt><dd>${escapeHtml(i.document_name)}</dd></div>
          <div><dt>Request No.</dt><dd>${escapeHtml(i.tracking_no)}</dd></div>
          <div><dt>Downloads used</dt><dd>${i.download_count} of ${i.download_limit}</dd></div>
        </dl>
        ${backToRequest ? `<div class="detail-buttons"><button type="button" class="request-back-btn" data-sc-back="${backToRequest}">Back to request</button></div>` : ""}
        <h3>Downloads</h3>${rows ? `<ol class="history-list">${rows}</ol>` : '<p class="muted">Nobody downloaded this document yet.</p>'}
        <h3>Additional download requests</h3>${requests ? `<ul class="history-list">${requests}</ul>` : '<p class="muted">None.</p>'}`, true);
    } catch (error) { Portal.toast(error.message, "error"); }
  }

  // ---------- Staff: Download Requests page ----------
  const dlBody = document.getElementById("dlRequestsBody");
  const dlStatus = document.getElementById("dlStatusFilter");
  let dlPage = 1, dlCounter = 0;
  const dlPager = dlBody ? Portal.makePager(dlBody.closest(".requests-table-wrap"), number => { dlPage = number; loadDownloadRequests(); }) : null;

  async function loadDownloadRequests() {
    if (!dlBody || !window.portalUser || isStudent() || window.portalUser.role === "cashier") return;
    const params = { action: "requests", status: dlStatus.value, page: dlPage };
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    const myTurn = ++dlCounter;
    try {
      const data = await Api.get("softcopy.php", params);
      if (myTurn !== dlCounter) return;
      const lastPage = Math.max(1, Math.ceil(data.total / data.per_page));
      if (dlPage > lastPage) { dlPage = lastPage; loadDownloadRequests(); return; }
      dlPager.render(dlPage, data.total, data.per_page);
      document.getElementById("dlCount").textContent = data.total === 1 ? "1 request found" : `${data.total} requests found`;
      dlBody.innerHTML = data.requests.length ? data.requests.map(q => `
        <tr><td>${escapeHtml(q.student_name)}<br><small>${escapeHtml(q.student_no || "")}</small></td><td>${escapeHtml(q.document_name)}</td><td>${escapeHtml(q.tracking_no)}</td>
          <td>${escapeHtml(dateText(q.first_download_at))}</td><td>${q.download_count}</td>
          <td><strong>${escapeHtml(q.reason_type)}</strong><br><small>${escapeHtml(q.reason)}</small></td><td>${window.statusPill(q.request_status)}</td>
          <td>${escapeHtml(q.status === "USED" ? "Approved (used)" : q.status[0] + q.status.slice(1).toLowerCase())}${q.decided_by_name ? `<br><small>by ${escapeHtml(q.decided_by_name)}</small>` : ""}${q.rejection_reason ? `<br><small>${escapeHtml(q.rejection_reason)}</small>` : ""}</td>
          <td class="row-actions wrap">${q.status === "PENDING" ? `<button class="request-table-action" type="button" data-sc-decide="approve" data-id="${q.id}">Approve Additional Download</button>
            <button class="request-table-action danger" type="button" data-sc-decide="reject" data-id="${q.id}">Reject Request</button>` : ""}
            <button class="request-table-action" type="button" data-sc-history="${q.item_id}">History</button></td></tr>`).join("")
        : '<tr><td colspan="9" class="requests-empty">No additional download requests match.</td></tr>';
    } catch (error) {
      if (myTurn !== dlCounter) return;
      dlBody.innerHTML = `<tr><td colspan="9" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // ---------- one click handler for all the buttons of this file ----------
  document.addEventListener("click", event => {
    const t = event.target;
    const download = t.closest("[data-sc-download]");
    if (download) { downloadPdf(Number(download.dataset.scDownload), download); return; }
    const more = t.closest("[data-sc-more]");
    if (more) { moreWindow(Number(more.dataset.scMore), more.dataset.doc, more.dataset.tracking); return; }
    const upload = t.closest("[data-sc-upload]");
    if (upload) { uploadPdf(Number(upload.dataset.scUpload), upload); return; }
    const ready = t.closest("[data-sc-ready]");
    if (ready) { markReady(Number(ready.dataset.scReady), ready); return; }
    const history = t.closest("[data-sc-history]");
    if (history) { historyWindow(Number(history.dataset.scHistory), modalRequestId()); return; }
    const back = t.closest("[data-sc-back]");
    if (back) { window.openRequestDetail(Number(back.dataset.scBack)); return; }
    const decision = t.closest("[data-sc-decide]");
    if (decision) {
      const id = Number(decision.dataset.id);
      if (decision.dataset.scDecide === "reject") rejectWindow(id);
      else if (window.confirm("Approve one additional download for this student?")) Portal.busy(decision, "Saving...", () => decide(id, "approve"));
    }
  });

  if (dlStatus) {
    dlStatus.addEventListener("change", () => { dlPage = 1; loadDownloadRequests(); });
    document.getElementById("dlReset").addEventListener("click", () => { dlStatus.value = "PENDING"; document.getElementById("searchInput").value = ""; dlPage = 1; loadDownloadRequests(); });
    document.getElementById("searchInput").addEventListener("input", Portal.debounce(() => { if (Portal.currentPage === "Download Requests") { dlPage = 1; loadDownloadRequests(); } }));
  }
  document.addEventListener("page:change", event => {
    if (event.detail === "Download Requests") loadDownloadRequests();
    if (["Dashboard", "My Requests"].includes(event.detail)) loadMine();
  });
  document.addEventListener("requests:changed", () => { loadMine(); });
  document.addEventListener("portal:ready", () => { loadMine(); });
})();
