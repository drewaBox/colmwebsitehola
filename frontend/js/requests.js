// Request list, dashboard numbers and the request details window. Used by every role.
(function () {
  const requestsBody = document.getElementById("requestsBody");
  // Filters above the request table (the same ids exist on the student page and on the staff page)
  const statusFilter = document.getElementById("statusFilter");
  const paymentFilter = document.getElementById("paymentFilter");
  const documentFilter = document.getElementById("documentFilter");
  const dateFrom = document.getElementById("dateFrom");
  const dateTo = document.getElementById("dateTo");
  let user = null;
  let page = 1;
  let loadCounter = 0;          // so an old answer can never replace a newer one when the filters change quickly
  const pager = Portal.makePager(requestsBody.closest(".requests-table-wrap"), number => { page = number; loadRequests(); });

  const STATUS_LABELS = {
    SUBMITTED: "Submitted", AI_VERIFYING: "Document Checking", FOR_REVIEW: "For Admin Review",
    NEEDS_CORRECTION: "Needs Correction", PROCESSING: "Processing", READY: "Ready for Release",
    COMPLETED: "Completed", REJECTED: "Rejected"
  };
  window.STATUS_LABELS = STATUS_LABELS;

  const AI_LABELS = { PASS: "AI: No problem found", REVIEW: "AI: Not sure", FAIL: "AI: Declined", UNAVAILABLE: "AI unavailable" };
  // The school uses three words: Pending, Verified, Declined.
  const REVIEW_LABELS = { Pending: "Pending", Verified: "Verified", Rejected: "Declined", Resubmit: "Declined" };

  const statusPill = status => `<span class="status-pill status-${escapeHtml(status.toLowerCase())}">${escapeHtml(STATUS_LABELS[status] || status)}</span>`;
  window.statusPill = statusPill;

  function releaseDateLabel(request) {
    if (request.released_at) return `Released ${Portal.formatDate(request.released_at)}`;
    if (request.status === "COMPLETED") return request.completed_at ? `Released ${Portal.formatDate(request.completed_at)}` : "Released";
    const start = Portal.formatDate(request.target_start);
    const end = Portal.formatDate(request.target_end);
    return start === end ? start : `${start} – ${end}`;
  }

  // ---------- Dashboard numbers ----------
  async function loadStats() {
    try {
      const stats = (await Api.get("requests.php", { action: "stats" })).stats;
      const isStudent = user.role === "student";
      const second = isStudent ? stats.processing : stats.for_review;
      const values = { statTotal: stats.total, statProcessing: second, statPending: stats.pending_payment, statReady: stats.ready, statCompleted: stats.completed };
      Object.entries(values).forEach(([id, value]) => { document.getElementById(id).textContent = value; });
      if (!isStudent) {
        document.getElementById("statTotalLabel").textContent = "All Requests";
        document.getElementById("statProcessingLabel").textContent = "Waiting for Review";
        document.getElementById("statReadyLabel").textContent = "Ready for Release";
        document.getElementById("statCompletedLabel").textContent = "Completed";
      }
      const empty = document.getElementById("dashboardEmpty");
      if (empty && isStudent) empty.hidden = stats.total > 0;
    } catch (error) { /* the dashboard just keeps its old numbers */ }
  }

  // ---------- Request table ----------
  function actionButtons(request) {
    const view = (label) => `<button class="request-table-action" type="button" data-view="${request.id}">${label}</button>`;
    if (user.role === "student") return view(request.status === "NEEDS_CORRECTION" ? "Fix documents" : "View");
    if (isStaff(user.role)) return view("Review");
    return view("View");
  }

  function renderRows(requests) {
    if (!requests.length) {
      requestsBody.innerHTML = '<tr><td colspan="10" class="requests-empty">No requests found.</td></tr>';
    } else {
      requestsBody.innerHTML = requests.map(request => `
        <tr>
          <td>${escapeHtml(request.tracking_no)}</td>
          <td>${escapeHtml(request.full_name)}</td>
          <td>${request.items.map(item => `${escapeHtml(item.name)} <span class="request-history-copies">× ${item.quantity}</span>`).join("<br>")}</td>
          <td>${Portal.formatMoney(request.total_amount)}</td>
          <td>${escapeHtml(releaseDateLabel(request))}</td>
          <td>${escapeHtml(request.payment_status)}</td>
          <td>${escapeHtml(request.payment_method)}</td>
          <td>${statusPill(request.status)}${request.softcopy_label ? `<br><small>${escapeHtml(request.softcopy_label)}</small>` : ""}</td>
          <td>${request.is_overdue ? '<span class="overdue-flag">Overdue</span>' : ""}</td>
          <td>${actionButtons(request)}</td>
        </tr>`).join("");
    }
    document.dispatchEvent(new CustomEvent("table:rendered"));
  }

  async function loadRequests() {
    const params = { action: "list", page };
    if (statusFilter && statusFilter.value) params.status = statusFilter.value;
    if (paymentFilter && paymentFilter.value) params.payment = paymentFilter.value;
    if (documentFilter && documentFilter.value) params.document = documentFilter.value;
    if (dateFrom && dateFrom.value) params.from = dateFrom.value;
    if (dateTo && dateTo.value) params.to = dateTo.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    const myTurn = ++loadCounter;
    try {
      const data = await Api.get("requests.php", params);
      if (myTurn !== loadCounter) return;                         // a newer search already started
      // If the filter result has fewer pages than the page we are on, go back to the last page.
      const lastPage = Math.max(1, Math.ceil(data.total / data.per_page));
      if (page > lastPage) { page = lastPage; loadRequests(); return; }
      renderRows(data.requests);
      pager.render(page, data.total, data.per_page);
      const count = document.getElementById("requestCount");
      if (count) count.textContent = data.total === 1 ? "1 request found" : `${data.total} requests found`;
    } catch (error) {
      if (myTurn !== loadCounter) return;
      requestsBody.innerHTML = `<tr><td colspan="10" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
      pager.render(1, 0, 1);
    }
  }

  // Other files call this after something changed.
  window.reloadRequests = function () {
    loadRequests();
    loadStats();
    window.refreshNotifications?.();
    document.dispatchEvent(new CustomEvent("requests:changed"));
  };
  window.loadStats = loadStats;

  // ---------- Request details window ----------
  async function openRequestDetail(id) {
    try {
      const data = await Api.get("requests.php", { action: "detail", id });
      if (id !== fixesRequestId) { discardFixes(); fixesRequestId = id; }
      let modal = document.querySelector("#modalBackdrop .modal-body");
      if (!modal || Number(modal.dataset.requestId) !== id) modal = Portal.openModal("", true).querySelector(".modal-body");
      modal.dataset.requestId = id;
      modal.innerHTML = detailHtml(data);
      const qrBox = modal.querySelector("#detailQr");
      if (qrBox) showQrCode(qrBox, id);         // the QR is saved on the server, so it is always the same code
    } catch (error) {
      Portal.toast(error.message, "error");
    }
  }
  window.openRequestDetail = openRequestDetail;

  function fileSize(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(bytes / 1024)) + " KB"; }
  const fileUrl = (id, inline) => `${API_URL}file.php?id=${id}${inline ? "&inline=1" : ""}`;

  function aiHtml(file, isRegistrar) {
    if (!file.ai) return '<span class="ai-pill ai-none">AI not run yet</span>';
    const ai = file.ai;
    let html = `<span class="ai-pill ai-${escapeHtml(ai.status.toLowerCase())}">${escapeHtml(AI_LABELS[ai.status])}</span>
      <small class="ai-reason">${escapeHtml(ai.reason || "")}</small>`;
    if (isRegistrar && ai.status !== "UNAVAILABLE" && ai.extracted_text !== undefined) {
      html += `<details class="ai-details"><summary>Details</summary>
        <p>Name on document: <strong>${escapeHtml(ai.name_on_document || "not found")}</strong>${ai.name_match_score !== null ? ` (match ${ai.name_match_score}%)` : ""}</p>
        <p>Confidence: ${ai.confidence !== null ? Math.round(ai.confidence * 100) + "%" : "not given"} · Model: ${escapeHtml(ai.model || "")} · ${escapeHtml(Portal.formatDate(ai.created_at, true))}</p>
        <p class="ai-text">${escapeHtml(ai.extracted_text || "No text was extracted.")}</p></details>`;
    }
    if (isRegistrar && ai.error_message) html += `<small class="ai-error">${escapeHtml(ai.error_message)}</small>`;
    return html;
  }

  // Documents a student uploads again after a correction request: "itemId:requirementId" -> { state, checkId, message, file }
  const fixes = new Map();
  let fixesRequestId = 0;
  let fixesRunning = 0;

  function discardFixes() {
    fixes.forEach(f => { if (f.checkId) Api.postJson("verify.php", { id: f.checkId }, { action: "discard" }).catch(() => {}); });
    fixes.clear();
    fixesRunning = 0;
  }

  function redrawFixBox(key) {
    const box = document.querySelector(`#modalBackdrop .upload-box[data-fix-key="${CSS.escape(key)}"]`);
    if (!box) return;
    const f = fixes.get(key);
    box.innerHTML = uploadBoxHtml(f, f && f.file, box.dataset.inputId, box.dataset.types.toUpperCase().split(",").join(", "), box.dataset.maxMb, key);
  }

  function updateSendButton() {
    const button = document.querySelector("#modalBackdrop [data-action='send-corrections']");
    if (!button) return;
    button.disabled = fixesRunning > 0;
    button.title = fixesRunning > 0 ? "Please wait while your documents are being verified." : "";
  }

  // "Try Again": check the stored file again (or send the file again if it was never stored).
  async function retryFix(key, input) {
    const old = fixes.get(key);
    if (!old || !old.file) return;
    if (!old.checkId) { verifyFix(key, input, old.file); return; }
    fixes.set(key, { state: "checking", checkId: old.checkId, file: old.file });
    redrawFixBox(key);
    fixesRunning++;
    updateSendButton();
    try {
      const state = stateFromCheck(await retryUpload(old.checkId));
      fixes.set(key, { ...state, file: old.file });
      Portal.toast(state.message, state.state === "verified" ? "success" : "error", "Verification");
    } catch (error) {
      fixes.set(key, { ...stateFromError(error), checkId: old.checkId, file: old.file });
    } finally {
      fixesRunning = Math.max(0, fixesRunning - 1);
      updateSendButton();
      redrawFixBox(key);
    }
  }

  async function verifyFix(key, input, file) {
    const old = fixes.get(key);
    fixes.set(key, { state: "checking", file });
    redrawFixBox(key);
    fixesRunning++;
    updateSendButton();
    try {
      const result = await verifyUpload(file, input.dataset.reqId, old && old.checkId);
      if (!fixes.has(key) || fixes.get(key).file !== file) {
        if (result.id) Api.postJson("verify.php", { id: result.id }, { action: "discard" }).catch(() => {});
        return;
      }
      const state = stateFromCheck(result);
      fixes.set(key, { ...state, file });
      const types = { verified: ["success", "Document verified"], declined: ["error", "Verification declined"], needs_correction: ["error", "Needs correction"], pending: ["warning", "Verification not completed"] };
      Portal.toast(state.message, types[state.state][0], types[state.state][1]);
    } catch (error) {
      if (!fixes.has(key) || fixes.get(key).file !== file) return;
      const state = stateFromError(error);
      fixes.set(key, { ...state, file });
      Portal.toast(state.message, "error", "Verification not completed");
    } finally {
      fixesRunning = Math.max(0, fixesRunning - 1);
      updateSendButton();
      redrawFixBox(key);
    }
  }

  function requirementRow(request, item, req) {
    const isRegistrar = isStaff(user.role);
    const canSeeFiles = request.can_see_files;
    const studentFixes = user.role === "student" && request.status === "NEEDS_CORRECTION" && req.needs_fix;
    let fileCell, aiCell = "", reviewCell = "";

    if (!canSeeFiles) {
      return `<tr><td><strong>${escapeHtml(req.name)}</strong></td><td colspan="3" class="muted">Uploaded documents are only visible to the admin/registrar and the student.</td></tr>`;
    }
    const key = `${item.id}:${req.id}`;
    if (req.file) {
      const f = req.file;
      fileCell = `<span class="file-name">${escapeHtml(f.original_name)}</span> <small>(${fileSize(f.size_bytes)})</small><br>
        <a href="${fileUrl(f.id, true)}" target="_blank" rel="noopener">Preview</a> ·
        <a href="${fileUrl(f.id, false)}">Download</a>`;
      aiCell = aiHtml(f, isRegistrar);
      reviewCell = `<span class="review-pill review-${escapeHtml(f.review_status.toLowerCase())}">${escapeHtml(REVIEW_LABELS[f.review_status])}</span>`;
      if (f.review_remarks) reviewCell += `<small class="review-remark">${["Rejected", "Resubmit"].includes(f.review_status) ? "Declined — " : ""}${escapeHtml(f.review_remarks)}</small>`;
      if (isRegistrar && ["FOR_REVIEW", "NEEDS_CORRECTION"].includes(request.status)) {
        reviewCell += `<div class="review-controls" data-file-id="${f.id}">
          <input type="text" class="review-remarks" maxlength="500" placeholder="Reason (required to decline)" aria-label="Remark for ${escapeHtml(req.name)}">
          <button type="button" class="mini-btn ok" data-review="Verified">Approve</button>
          <button type="button" class="mini-btn bad" data-review="Rejected">Decline</button>
          <button type="button" class="mini-btn" data-run-ai="${f.id}">Verify again (AI)</button></div>`;
      }
    } else {
      fileCell = '<span class="missing-file">Required document missing</span>';
    }
    if (studentFixes) {
      const inputId = `fix-${item.id}-${req.id}`;
      const f = fixes.get(key);
      fileCell += `<input type="file" class="request-required-file fix-file" id="${inputId}" data-key="${key}" data-item-id="${item.id}" data-req-id="${req.id}"
          data-max-mb="${req.max_size_mb}" data-types="${escapeHtml(req.allowed_types)}" accept=".${req.allowed_types.split(",").join(",.")}"
          aria-label="New file for ${escapeHtml(req.name)}">
        <div class="upload-box" data-fix-key="${key}" data-input-id="${inputId}" data-types="${escapeHtml(req.allowed_types)}" data-max-mb="${req.max_size_mb}" aria-live="polite">${uploadBoxHtml(f, f && f.file, inputId, req.allowed_types.toUpperCase().split(",").join(", "), req.max_size_mb, key)}</div>`;
    }
    // Students see the file and its status only. The automatic check column is for the admin/registrar.
    return `<tr class="${studentFixes ? "needs-fix" : ""}"><td><strong>${escapeHtml(req.name)}</strong><br><small>${escapeHtml(req.description || "")}</small></td>
      <td>${fileCell}</td>${isRegistrar ? `<td>${aiCell}</td>` : ""}<td>${reviewCell}</td></tr>`;
  }

  function actionBar(request, items) {
    if (user.role === "student") {
      return request.status === "NEEDS_CORRECTION" && request.needs_fix
        ? '<div class="detail-actions"><button type="button" class="request-next-btn" data-action="send-corrections">Send corrected documents</button></div>' : "";
    }
    if (!isStaff(user.role)) return "";

    const s = request.status;
    const remarks = '<textarea class="detail-remarks" rows="2" maxlength="500" placeholder="Message to the student (required for correction or rejection)"></textarea>';
    const button = (status, label, css = "") => `<button type="button" class="request-next-btn ${css}" data-set-status="${status}">${label}</button>`;
    const paymentPending = Number(request.total_amount) > 0 && request.payment_status !== "Paid";
    let buttons = "";
    if (s === "FOR_REVIEW") {
      buttons = `<button type="button" class="request-back-btn" data-action="run-ai-all">Run document pre-check on all files</button>`
        + (paymentPending
          ? '<button type="button" class="request-next-btn" disabled title="Waiting for the cashier to confirm payment.">Start processing · Waiting for payment</button>'
          : button("PROCESSING", "Start processing"))
        + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "PROCESSING") {
      buttons = (paymentPending
        ? '<button type="button" class="request-next-btn" disabled title="Waiting for the cashier to confirm payment.">Mark ready · Waiting for payment</button>'
        : button("READY", "Mark ready for release"))
        + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "READY") {
      buttons = button("COMPLETED", "Complete (released)");
    } else if (s === "NEEDS_CORRECTION") {
      buttons = button("REJECTED", "Reject request", "bad");
    } else {
      return "";
    }
    return `<div class="detail-actions">${s === "READY" ? "" : remarks}<div class="detail-buttons">${buttons}</div></div>`;
  }

  function detailHtml(data) {
    const r = data.request;
    const note = (r.registrar_remarks && ["NEEDS_CORRECTION", "REJECTED"].includes(r.status))
      ? `<div class="notice notice-warning"><strong>Message from the school:</strong> ${escapeHtml(r.registrar_remarks)}</div>` : "";
    const paymentNotice = isStaff(user.role) && ["FOR_REVIEW", "PROCESSING"].includes(r.status) && Number(r.total_amount) > 0 && r.payment_status !== "Paid"
      ? `<div class="notice notice-warning"><strong>Waiting for cashier payment.</strong> The cashier must record the full payment before ${r.status === "PROCESSING" ? "the request can be marked ready for release" : "processing can begin"}.</div>` : "";
    const items = data.items.map(item => `
      <section class="detail-item">
        <h3>${escapeHtml(item.name)} <small>× ${item.quantity} · ${Portal.formatMoney(item.unit_price * item.quantity)}</small></h3>
        <div class="requests-table-wrap"><table class="detail-table">
          <thead><tr><th>Requirement</th><th>Uploaded file</th>${isStaff(user.role) ? "<th>Automatic check</th>" : ""}<th>Status</th></tr></thead>
          <tbody>${item.requirements.map(req => requirementRow(r, item, req)).join("")}</tbody>
        </table></div>
        ${window.softcopyBlockHtml ? window.softcopyBlockHtml(r, item) : ""}
      </section>`).join("");
    const history = data.history.map(h => `
      <li><strong>${escapeHtml(STATUS_LABELS[h.new_status] || h.new_status)}</strong>
        <span>${escapeHtml(Portal.formatDate(h.created_at, true))}${h.by_name ? " · " + escapeHtml(h.by_name) : " · System"}</span>
        ${h.remarks ? `<em>${escapeHtml(h.remarks)}</em>` : ""}</li>`).join("");

    return `
      <h2>Request ${escapeHtml(r.tracking_no)} ${statusPill(r.status)}</h2>
      <dl class="detail-grid">
        <div><dt>Requester</dt><dd>${escapeHtml(r.full_name)}${r.student_no ? " (" + escapeHtml(r.student_no) + ")" : ""}</dd></div>
        <div><dt>Purpose</dt><dd>${escapeHtml(r.purpose)}</dd></div>
        <div><dt>Document format</dt><dd>${escapeHtml(formatLabel(r.release_method))} · ${escapeHtml(releaseDateLabel(r))}</dd></div>
        <div><dt>Payment</dt><dd>${Portal.formatMoney(Number(r.total_amount))} · ${escapeHtml(r.payment_method)} · ${escapeHtml(r.payment_status)}</dd></div>
      </dl>
      ${note}${paymentNotice}
      ${items}
      ${user.role === "student" ? `<h3>QR code</h3><div class="qr-box" id="detailQr"></div>` : ""}
      ${actionBar(r, data.items)}
      <h3>History</h3><ol class="history-list">${history}</ol>`;
  }

  // ---------- Buttons inside the details window ----------
  document.addEventListener("click", async event => {
    const modalBody = event.target.closest("#modalBackdrop .modal-body");
    if (!modalBody) return;
    const requestId = Number(modalBody.dataset.requestId);
    const target = event.target;
    const refresh = async () => { await openRequestDetail(requestId); window.reloadRequests(); };

    const reviewButton = target.closest("[data-review]");
    if (reviewButton) {
      const box = reviewButton.closest(".review-controls");
      await Portal.busy(reviewButton, "Saving...", async () => {
        await Api.postJson("requests.php", {
          file_id: Number(box.dataset.fileId), decision: reviewButton.dataset.review,
          remarks: box.querySelector(".review-remarks").value
        }, { action: "review_file" });
        await refresh();
      });
      return;
    }

    const aiButton = target.closest("[data-run-ai], [data-action='run-ai-all']");
    if (aiButton) {
      await Portal.busy(aiButton, "Running AI...", async () => {
        const body = { id: requestId };
        if (aiButton.dataset.runAi) body.file_id = Number(aiButton.dataset.runAi);
        const result = await Api.postJson("requests.php", body, { action: "run_ai" });
        Portal.toast(result.summary, result.counts.UNAVAILABLE ? "warning" : "success");
        await refresh();
      });
      return;
    }

    const statusButton = target.closest("[data-set-status]");
    if (statusButton) {
      const remarks = modalBody.querySelector(".detail-remarks")?.value || "";
      await Portal.busy(statusButton, "Saving...", async () => {
        await Api.postJson("requests.php", { id: requestId, status: statusButton.dataset.setStatus, remarks }, { action: "set_status" });
        Portal.toast("Request updated.", "success");
        await refresh();
      });
      return;
    }

    const actionButton = target.closest("[data-action]");
    if (actionButton?.dataset.action === "send-corrections") {
      const inputs = [...modalBody.querySelectorAll(".fix-file")];
      if (fixesRunning > 0) { Portal.toast("Please wait while your documents are being verified.", "warning"); return; }
      const checkIds = {};
      for (const input of inputs) {
        const f = fixes.get(input.dataset.key);
        if (f && f.state !== "verified" && f.state !== "checking") { Portal.toast("A document is not verified. Please fix it (Upload Again / Try Again) first.", "error", "Not verified"); return; }
        if (!f || !isVerifiedState(f)) { Portal.toast("Please upload all documents that need correction.", "error", "Required document missing"); return; }
        checkIds[input.dataset.key] = f.checkId;
      }
      await Portal.busy(actionButton, "Sending...", async () => {
        const form = new FormData();
        form.append("payload", JSON.stringify({ checks: checkIds }));
        const result = await Api.postForm("requests.php", form, { action: "resubmit", id: requestId });
        fixes.forEach(f => { f.checkId = null; });          // the files now belong to the request
        fixes.clear();
        Portal.toast(result.message, "success", "Documents sent");
        await refresh();
      });
    }
  });

  // Closing the window without sending: the verified files that were not sent are removed from the server.
  const closeModalBase = Portal.closeModal;
  Portal.closeModal = function () { discardFixes(); closeModalBase(); };

  // ---------- Student: choosing a new file for a declined document ----------
  document.addEventListener("change", async event => {
    const input = event.target.closest("#modalBackdrop .fix-file");
    if (!input) return;
    const key = input.dataset.key;
    const file = input.files[0];
    if (!file) { fixes.delete(key); redrawFixBox(key); return; }
    const problem = await fileProblem(file, input);
    if (problem) {
      fixes.delete(key);
      input.value = "";
      redrawFixBox(key);
      Portal.toast(problem.text, "error", problem.title);
      return;
    }
    verifyFix(key, input, file);
  });
  document.addEventListener("click", event => {
    const box = event.target.closest("#modalBackdrop .upload-box[data-fix-key]");
    if (!box) return;
    const remove = event.target.closest("[data-remove]");
    const again = event.target.closest("[data-again]");
    const retry = event.target.closest("[data-retry]");
    const key = box.dataset.fixKey;
    if (retry) { retryFix(key, document.getElementById(box.dataset.inputId)); return; }
    if (!remove && !again) return;
    const input = document.getElementById(box.dataset.inputId);
    const old = fixes.get(key);
    if (old && old.checkId) Api.postJson("verify.php", { id: old.checkId }, { action: "discard" }).catch(() => {});
    fixes.delete(key);
    input.value = "";
    redrawFixBox(key);
    if (again) input.click();
  });

  // ---------- Table buttons ----------
  requestsBody.addEventListener("click", async event => {
    const view = event.target.closest("[data-view]");
    if (view) { openRequestDetail(Number(view.dataset.view)); return; }
  });

  // Fill the lists of the filters, then reload the table whenever a filter changes (all filters work together).
  if (statusFilter) {
    statusFilter.innerHTML += Object.entries(STATUS_LABELS).map(([value, label]) => `<option value="${value}">${label}</option>`).join("");
  }
  async function fillDocumentFilter() {
    if (!documentFilter || documentFilter.options.length > 1) return;
    try {
      (await Api.get("documents.php")).documents.forEach(doc => documentFilter.add(new Option(doc.name, doc.id)));
    } catch (error) { /* the filter simply stays on "All documents" */ }
  }
  const filterBoxes = [statusFilter, paymentFilter, documentFilter, dateFrom, dateTo].filter(Boolean);
  filterBoxes.forEach(box => box.addEventListener("change", () => {
    if (dateFrom && dateTo && dateFrom.value && dateTo.value && dateFrom.value > dateTo.value) {
      Portal.toast("The \"From\" date is after the \"To\" date.", "warning", "Check the dates");
      return;
    }
    page = 1;
    loadRequests();
  }));
  document.getElementById("filtersReset")?.addEventListener("click", () => {
    filterBoxes.forEach(box => { box.value = ""; });
    document.getElementById("searchInput").value = "";
    applySearch();
    page = 1;
    loadRequests();
  });

  // The search box also searches on the server, so older requests can be found too.
  document.getElementById("searchInput").addEventListener("input", Portal.debounce(() => {
    if (!["My Requests", "All Requests"].includes(Portal.currentPage)) return;
    page = 1;
    loadRequests();
  }));

  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (["My Requests", "All Requests"].includes(event.detail)) { fillDocumentFilter(); loadRequests(); }
    if (["Dashboard", "Overview & KPIs"].includes(event.detail)) loadStats();
  });

  document.addEventListener("portal:ready", event => {
    user = event.detail;
    if (user.role === "cashier") return;          // the cashier only uses api/payments.php
    loadStats();
    loadRequests();
    setInterval(() => { if (!document.hidden) loadStats(); }, 60000);
  });
})();
