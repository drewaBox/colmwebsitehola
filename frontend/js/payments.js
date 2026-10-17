// Cashier pages: Payment Dashboard, Pending Payments (Record Payment) and Payment History (receipts).
// Everything here talks to api/payments.php - the only file the cashier is allowed to use.
(function () {
  let user = null;
  const money = amount => Portal.formatMoney(Number(amount));
  const levelText = s => [s.school_level ? schoolYearLabel(s.school_level, s.year_level) : "", s.course || "", s.section || ""].filter(Boolean).join(" / ");

  // Filters of the two payment lists
  const pendingMethod = document.getElementById("pendingMethodFilter");
  const historyMethod = document.getElementById("historyMethodFilter");
  const historyFrom = document.getElementById("historyFrom");
  const historyTo = document.getElementById("historyTo");
  let historyPage = 1;
  const historyPager = Portal.makePager(document.getElementById("payHistoryBody").closest(".requests-table-wrap"), number => { historyPage = number; loadHistory(); });

  // ---------- Dashboard ----------
  async function loadDashboard() {
    try {
      const summary = (await Api.get("payments.php", { action: "summary" })).summary;
      document.getElementById("payUnpaidCount").textContent = summary.unpaid_count;
      document.getElementById("payUnpaidTotal").textContent = money(summary.unpaid_total);
      document.getElementById("payTodayTotal").textContent = money(summary.today_total);
      document.getElementById("payAllTotal").textContent = money(summary.all_total);
      const latest = (await Api.get("payments.php", { action: "history" })).payments.slice(0, 8);
      document.getElementById("payLatestBody").innerHTML = latest.length ? latest.map(p => `
        <tr><td>${escapeHtml(p.receipt_no)}</td><td>${escapeHtml(p.full_name)}</td><td>${money(p.amount)}</td><td>${escapeHtml(p.method)}</td><td>${escapeHtml(Portal.formatDate(p.paid_at, true))}</td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">No payments recorded yet.</td></tr>';
    } catch (error) {
      document.getElementById("payLatestBody").innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // ---------- Pending payments ----------
  let pending = [];
  async function loadPending() {
    const body = document.getElementById("payPendingBody");
    const params = { action: "pending" };
    if (pendingMethod.value) params.method = pendingMethod.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    try {
      pending = (await Api.get("payments.php", params)).payments;
      body.innerHTML = pending.length ? pending.map(p => `
        <tr><td>${escapeHtml(p.tracking_no)}</td>
          <td><button class="link-btn" type="button" data-student-info="${p.id}">${escapeHtml(p.full_name)}</button><br><small>${escapeHtml(p.student_no || "")}</small></td>
          <td>${escapeHtml(levelText(p))}</td><td>${money(p.total_amount)}</td><td>${escapeHtml(p.payment_method)}</td>
          <td><button class="request-table-action" type="button" data-record="${p.id}">Record Payment</button></td></tr>`).join("")
        : '<tr><td colspan="6" class="requests-empty">No pending payments match.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="6" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  function recordWindow(p) {
    const modal = Portal.openModal(`
      <h2>Record Payment</h2>
      <dl class="detail-grid">
        <div><dt>Student</dt><dd>${escapeHtml(p.full_name)} (${escapeHtml(p.student_no || "")})</dd></div>
        <div><dt>Course / Year / Section</dt><dd>${escapeHtml(levelText(p)) || "-"}</dd></div>
        <div><dt>Request</dt><dd>${escapeHtml(p.tracking_no)}</dd></div>
        <div><dt>Amount to pay</dt><dd>${money(p.total_amount)}</dd></div>
      </dl>
      <form class="student-form" novalidate>
        <label>Payment method
          <select class="request-text-input" name="method"><option ${p.payment_method === "Cash" ? "selected" : ""}>Cash</option><option ${p.payment_method === "GCash" ? "selected" : ""}>GCash</option></select></label>
        <label>Amount received (PHP)
          <input class="request-text-input" name="amount" type="number" min="0" step="0.01" value="${p.total_amount}" required></label>
        <p class="form-message error" role="alert"></p>
        <div class="detail-buttons"><button class="request-next-btn" type="submit">Save payment</button>
          <button class="request-back-btn" type="button" data-close-modal>Cancel</button></div>
      </form>`);
    const form = modal.querySelector("form");
    form.addEventListener("submit", async event => {
      event.preventDefault();
      const message = form.querySelector(".form-message");
      message.textContent = "";
      await Portal.busy(form.querySelector("button[type=submit]"), "Saving...", async () => {
        try {
          const result = await Api.postJson("payments.php", { request_id: p.id, amount: form.elements.amount.value, method: form.elements.method.value }, { action: "record" });
          Portal.toast(`Payment recorded. Receipt no. ${result.receipt_no}.`, "success");
          refreshAll();
          showReceipt(result.payment_id);
        } catch (error) { message.textContent = error.message; }
      });
    });
  }

  // ---------- Student payment information ----------
  async function studentInfo(requestId) {
    try {
      const data = await Api.get("payments.php", { action: "student", id: requestId });
      const s = data.student;
      Portal.openModal(`
        <h2>Student Payment Information</h2>
        <dl class="detail-grid">
          <div><dt>Name</dt><dd>${escapeHtml(s.full_name)}</dd></div>
          <div><dt>Student ID</dt><dd>${escapeHtml(s.student_no || "")}</dd></div>
          <div><dt>Course / Year / Section</dt><dd>${escapeHtml(levelText(s)) || "-"}</dd></div>
        </dl>
        <div class="requests-table-wrap"><table class="detail-table">
          <thead><tr><th>Request</th><th>Amount</th><th>Payment</th><th>Receipt</th></tr></thead>
          <tbody>${data.requests.map(r => `<tr><td>${escapeHtml(r.tracking_no)}<br><small>${escapeHtml(r.status_label)}</small></td><td>${money(r.total_amount)}</td>
            <td>${escapeHtml(r.payment_status)} (${escapeHtml(r.payment_method)})</td><td>${r.receipt_no ? escapeHtml(r.receipt_no) + "<br><small>" + escapeHtml(Portal.formatDate(r.paid_at, true)) + "</small>" : "-"}</td></tr>`).join("")}</tbody>
        </table></div>`, true);
    } catch (error) { Portal.toast(error.message, "error"); }
  }

  // ---------- History and receipts ----------
  async function loadHistory() {
    const body = document.getElementById("payHistoryBody");
    const params = { action: "history", page: historyPage };
    if (historyMethod.value) params.method = historyMethod.value;
    if (historyFrom.value) params.from = historyFrom.value;
    if (historyTo.value) params.to = historyTo.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    try {
      const data = await Api.get("payments.php", params);
      const lastPage = Math.max(1, Math.ceil(data.total / data.per_page));
      if (historyPage > lastPage) { historyPage = lastPage; loadHistory(); return; }
      historyPager.render(historyPage, data.total, data.per_page);
      const payments = data.payments;
      body.innerHTML = payments.length ? payments.map(p => `
        <tr><td>${escapeHtml(p.receipt_no)}</td><td>${escapeHtml(p.tracking_no)}</td><td>${escapeHtml(p.full_name)}<br><small>${escapeHtml(p.student_no || "")}</small></td>
          <td>${money(p.amount)}</td><td>${escapeHtml(p.method)}</td><td>${escapeHtml(Portal.formatDate(p.paid_at, true))}</td><td>${escapeHtml(p.cashier_name || "")}</td>
          <td><button class="request-table-action" type="button" data-receipt="${p.id}">View Receipt</button></td></tr>`).join("")
        : '<tr><td colspan="8" class="requests-empty">No payments match the filters.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="8" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  async function showReceipt(paymentId) {
    try {
      const r = (await Api.get("payments.php", { action: "receipt", id: paymentId })).receipt;
      const html = `
        <h2>Official Receipt</h2>
        <p><strong>Receipt no.:</strong> ${escapeHtml(r.receipt_no)}<br><strong>Date:</strong> ${escapeHtml(Portal.formatDate(r.paid_at, true))}</p>
        <p><strong>Student:</strong> ${escapeHtml(r.full_name)} (${escapeHtml(r.student_no || "")})<br><strong>Request:</strong> ${escapeHtml(r.tracking_no)}</p>
        <table class="detail-table"><thead><tr><th>Document</th><th>Copies</th><th>Price</th></tr></thead><tbody>
          ${r.items.map(i => `<tr><td>${escapeHtml(i.name)}</td><td>${i.quantity}</td><td>${money(i.unit_price * i.quantity)}</td></tr>`).join("")}</tbody></table>
        <p><strong>Total paid:</strong> ${money(r.amount)} (${escapeHtml(r.method)})<br><strong>Received by:</strong> ${escapeHtml(r.cashier_name || "")}</p>`;
      const modal = Portal.openModal(html + '<div class="detail-buttons"><button class="request-next-btn" type="button" id="printReceipt">Print</button><button class="request-back-btn" type="button" data-close-modal>Close</button></div>');
      modal.querySelector("#printReceipt").addEventListener("click", () => {
        const w = window.open("", "_blank", "width=480,height=640");
        if (!w) { Portal.toast("Please allow pop-ups to print the receipt.", "warning"); return; }
        w.document.write("<!DOCTYPE html><title>Receipt</title><body style='font-family:Arial,sans-serif;padding:20px'>" + html + "</body>");
        w.document.close();
        w.print();
      });
    } catch (error) { Portal.toast(error.message, "error"); }
  }

  function refreshAll() {
    loadDashboard(); loadPending(); loadHistory();
  }

  // ---------- Events ----------
  document.getElementById("payPendingBody").addEventListener("click", event => {
    const record = event.target.closest("[data-record]");
    if (record) recordWindow(pending.find(p => p.id === Number(record.dataset.record)));
    const info = event.target.closest("[data-student-info]");
    if (info) studentInfo(Number(info.dataset.studentInfo));
  });
  document.getElementById("payHistoryBody").addEventListener("click", event => {
    const receipt = event.target.closest("[data-receipt]");
    if (receipt) showReceipt(Number(receipt.dataset.receipt));
  });

  // Filters: every change reloads the list from page 1. "Reset filters" puts everything back.
  pendingMethod.addEventListener("change", loadPending);
  document.getElementById("pendingFiltersReset").addEventListener("click", () => {
    pendingMethod.value = "";
    document.getElementById("searchInput").value = "";
    loadPending();
  });
  [historyMethod, historyFrom, historyTo].forEach(box => box.addEventListener("change", () => {
    if (historyFrom.value && historyTo.value && historyFrom.value > historyTo.value) {
      Portal.toast("The \"From\" date is after the \"To\" date.", "warning", "Check the dates");
      return;
    }
    historyPage = 1;
    loadHistory();
  }));
  document.getElementById("historyFiltersReset").addEventListener("click", () => {
    historyMethod.value = ""; historyFrom.value = ""; historyTo.value = "";
    document.getElementById("searchInput").value = "";
    historyPage = 1;
    loadHistory();
  });
  document.getElementById("searchInput").addEventListener("input", Portal.debounce(() => {
    if (!user || user.role !== "cashier") return;
    if (Portal.currentPage === "Pending Payments") loadPending();
    if (Portal.currentPage === "Payment History") { historyPage = 1; loadHistory(); }
  }));
  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (user.role !== "cashier") return;
    if (event.detail === "Payment Dashboard") loadDashboard();
    if (event.detail === "Pending Payments") loadPending();
    if (event.detail === "Payment History") loadHistory();
  });
  document.addEventListener("portal:ready", event => { user = event.detail; });
})();
