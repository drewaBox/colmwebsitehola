// Talks to the PHP backend. Every page loads this file first.
// If the frontend and the "api" folder are in different places, change API_URL.
const API_URL = "../api/";

class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.status = status;       // 0 = the server could not be reached
    this.data = data;           // the JSON answer of the server (may contain "title", "code", "missing" ...)
  }
}

const Api = (() => {
  let csrfToken = "";
  const DEFAULT_TIMEOUT_MS = 60000;

  async function send(file, options = {}, params = {}, timeoutMs = DEFAULT_TIMEOUT_MS) {
    const query = new URLSearchParams(params).toString();
    const url = API_URL + file + (query ? "?" + query : "");
    const headers = { ...(options.headers || {}) };
    if (options.method === "POST") headers["X-CSRF-Token"] = csrfToken;

    // Never wait forever: after the timeout the request is cancelled and the person sees a message.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    let response;
    try {
      response = await fetch(url, { credentials: "same-origin", ...options, headers, signal: controller.signal });
    } catch (error) {
      if (error.name === "AbortError") throw new ApiError("This is taking too long. Please try again.", 0);
      throw new ApiError("Cannot reach the server. Check your connection and try again.", 0);
    } finally {
      clearTimeout(timer);
    }

    let data = null;
    try { data = await response.json(); } catch (error) { /* the answer was not JSON */ }

    if (!response.ok || !data || data.ok === false) {
      const onLoginPage = !!document.getElementById("loginForm");
      if (response.status === 401 && !onLoginPage && file !== "me.php") {
        // The session ended (logged out elsewhere or timed out): go to the login page and say why.
        try { sessionStorage.setItem("colmSessionExpired", "1"); } catch (e) { /* storage may be off */ }
        window.location.replace("login.html");
      }
      throw new ApiError((data && data.error) || (response.status >= 500 ? "Something went wrong on the server. Please try again." : `Request failed (${response.status}).`), response.status, data);
    }
    if (data.csrf) csrfToken = data.csrf;
    return data;
  }

  // POST that answers with a FILE (the secure PDF download). Returns { blob, filename }. Errors come back as normal ApiError messages.
  async function postForFile(file, body, params) {
    const query = new URLSearchParams(params || {}).toString();
    let response;
    try {
      response = await fetch(API_URL + file + (query ? "?" + query : ""), {
        method: "POST", credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken }, body: JSON.stringify(body || {})
      });
    } catch (error) {
      throw new ApiError("Cannot reach the server. Check your connection and try again.", 0);
    }
    if (!response.ok || !(response.headers.get("Content-Type") || "").includes("application/pdf")) {
      let data = null;
      try { data = await response.json(); } catch (error) { /* not JSON */ }
      if (response.status === 401) { try { sessionStorage.setItem("colmSessionExpired", "1"); } catch (e) { /* ignore */ } window.location.replace("login.html"); }
      throw new ApiError((data && data.error) || "The download failed. Please try again.", response.status, data);
    }
    const match = /filename="([^"]+)"/.exec(response.headers.get("Content-Disposition") || "");
    return { blob: await response.blob(), filename: match ? match[1] : "document.pdf" };
  }

  return {
    postForFile,
    get: (file, params, timeoutMs) => send(file, { method: "GET" }, params, timeoutMs),
    postJson: (file, body, params, timeoutMs) => send(file, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {})
    }, params, timeoutMs),
    postForm: (file, formData, params, timeoutMs) => send(file, { method: "POST", body: formData }, params, timeoutMs)
  };
})();

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, character => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[character]);
}

// Roles that use the management pages with full student/document functions (the cashier only sees payments).
const STAFF_ROLES = ["admin", "registrar"];
const isStaff = role => STAFF_ROLES.includes(role);

// "First Year" for college, "Grade 11" for senior high school and high school.
function schoolYearLabel(level, year) {
  if (!year) return "";
  if (level === "COLLEGE") return ["", "First Year", "Second Year", "Third Year", "Fourth Year"][year] || "Year " + year;
  return "Grade " + year;
}

// The three document formats: the value stored in the database -> the words shown to people.
const FORMAT_LABELS = { Hardcopy: "Physical / Hard Copy", Softcopy: "Soft Copy / PDF", Both: "Both (Hard Copy and Soft Copy)" };
const formatLabel = value => FORMAT_LABELS[value] || value;
// Short words for the narrow table columns.
const FORMAT_SHORT = { Hardcopy: "Hard Copy", Softcopy: "Soft Copy", Both: "Both" };
const formatShort = value => FORMAT_SHORT[value] || value;
