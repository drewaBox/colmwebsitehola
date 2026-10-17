// Login page and the login check for every portal page.
const roleNames = { student: "Student", cashier: "Cashier", registrar: "Registrar", admin: "Admin" };

function setupLoginPage() {
  const form = document.getElementById("loginForm");
  const errorBox = document.getElementById("loginError");
  const notice = document.getElementById("loginNotice");
  const usernameInput = document.getElementById("loginUsername");
  const passwordInput = document.getElementById("loginPassword");
  const rememberMe = document.getElementById("rememberMe");
  const submitButton = form.querySelector(".login-submit");

  const showLoading = async () => {
    document.getElementById("loginLoading").hidden = false;
    await new Promise(resolve => window.setTimeout(resolve, 5000));
  };

  // "Remember me" only remembers the username (never the password).
  const savedUsername = localStorage.getItem("colmRememberedUser");
  if (savedUsername) {
    usernameInput.value = savedUsername;
    rememberMe.checked = true;
  }

  // The session ended while the person was working (timeout, logged out elsewhere).
  try {
    if (sessionStorage.getItem("colmSessionExpired")) {
      sessionStorage.removeItem("colmSessionExpired");
      notice.textContent = "Your session has ended. Please log in again.";
    }
  } catch (error) { /* storage may be off */ }

  // Always show the login form when the login page is opened.
  // Do not auto-redirect to the dashboard just because an old session exists.

  document.getElementById("passwordVisibility").addEventListener("click", event => {
    const show = passwordInput.type === "password";
    passwordInput.type = show ? "text" : "password";
    event.currentTarget.textContent = show ? "visibility_off" : "visibility";
    event.currentTarget.setAttribute("aria-label", show ? "Hide password" : "Show password");
  });

  document.getElementById("forgotPassword").addEventListener("click", () => {
    errorBox.textContent = "";
    notice.textContent = "To reset your password, please visit the Registrar's Office or contact your administrator.";
  });

  form.addEventListener("submit", async event => {
    event.preventDefault();
    errorBox.textContent = "";
    notice.textContent = "";
    submitButton.disabled = true;
    submitButton.textContent = "Signing in...";
    try {
      const data = await Api.postJson("login.php", {
        username: usernameInput.value.trim(),
        password: passwordInput.value
      });
      if (rememberMe.checked) localStorage.setItem("colmRememberedUser", usernameInput.value.trim());
      else localStorage.removeItem("colmRememberedUser");
      await showLoading();
      window.location.replace(data.user.home);
    } catch (error) {
      errorBox.textContent = error.message;
      passwordInput.value = "";
      submitButton.disabled = false;
      submitButton.textContent = "Login";
    }
  });
}

function confirmPortalLogout() {
  const dialog = document.createElement("dialog");
  dialog.className = "logout-confirm-dialog";
  dialog.setAttribute("aria-labelledby", "logoutConfirmTitle");
  dialog.innerHTML = `
    <div class="logout-confirm-icon" aria-hidden="true"><span class="material-symbol">logout</span></div>
    <h2 id="logoutConfirmTitle">Log out?</h2>
    <p>Are you sure you want to log out of your COLM portal?</p>
    <div class="logout-confirm-actions">
      <button class="request-back-btn" type="button" data-logout-cancel>Stay signed in</button>
      <button class="request-next-btn" type="button" data-logout-confirm>Log out</button>
    </div>`;
  document.body.append(dialog);
  dialog.showModal();

  return new Promise(resolve => {
    const finish = confirmed => {
      dialog.close();
      dialog.remove();
      resolve(confirmed);
    };
    dialog.querySelector("[data-logout-cancel]").addEventListener("click", () => finish(false), { once: true });
    dialog.querySelector("[data-logout-confirm]").addEventListener("click", () => finish(true), { once: true });
    dialog.addEventListener("cancel", event => {
      event.preventDefault();
      finish(false);
    }, { once: true });
    dialog.addEventListener("click", event => {
      if (event.target === dialog) finish(false);
    });
    dialog.querySelector("[data-logout-cancel]").focus();
  });
}

function showLogoutTransition() {
  const overlay = document.createElement("div");
  overlay.className = "login-loading logout-loading";
  overlay.setAttribute("role", "status");
  overlay.setAttribute("aria-live", "assertive");
  overlay.setAttribute("aria-label", "Logging out of COLM portal");
  overlay.innerHTML = `
    <div class="login-loading-card">
      <div class="login-loading-seal"><img src="assets/images/colm_registrar_logo.png" alt=""></div>
      <div class="login-loading-word" aria-hidden="true"><span>C</span><span>O</span><span>L</span><span>M</span></div>
      <div class="login-loading-rule" aria-hidden="true"><span></span></div>
    </div>`;
  document.body.append(overlay);
  return overlay;
}

async function setupPortalPage() {
  let user;
  try {
    user = (await Api.get("me.php")).user;
  } catch (error) {
    window.location.replace("login.html");
    return;
  }

  // Students use student.html, everyone else uses manage.html.
  const pageIsForStudents = document.body.dataset.portalRole === "Student";
  if ((user.role === "student") !== pageIsForStudents) {
    window.location.replace(user.home);
    return;
  }

  // Remove menu items this role is not allowed to use (the server checks the role again on every request).
  document.querySelectorAll("[data-roles]").forEach(element => {
    if (!element.dataset.roles.split(",").includes(user.role)) element.remove();
  });

  const roleName = roleNames[user.role];
  const setText = (id, text) => { const element = document.getElementById(id); if (element) element.textContent = text; };
  setText("studentName", user.full_name);
  setText("studentRole", roleName);
  setText("profileName", user.full_name);
  setText("profileRole", roleName);
  setText("profileId", user.student_no || user.username);
  setText("welcomeName", user.full_name.split(" ")[0]);
  setText("portalBrandTitle", user.role === "student" ? "STUDENT PORTAL" : user.role === "cashier" ? "CASHIER PORTAL" : "STAFF PORTAL");
  setText("portalEyebrow", roleName.toUpperCase() + " PORTAL");
  document.title = `${roleName} Portal | COLM Registrar`;

  document.getElementById("logoutBtn").addEventListener("click", async event => {
    event.preventDefault();
    const confirmed = await confirmPortalLogout();
    if (!confirmed) return;

    const overlay = showLogoutTransition();
    const transitionStarted = Date.now();
    try {
      await Api.postJson("logout.php");
    } catch (error) {
      overlay.remove();
      Portal.toast(error.message, "error", "Logout failed");
      return;
    }

    const transitionRemaining = Math.max(0, 5000 - (Date.now() - transitionStarted));
    await new Promise(resolve => window.setTimeout(resolve, transitionRemaining));
    window.location.replace("login.html");
  });

  // Wait until all scripts at the end of the page have run (they register their "portal:ready" listeners).
  if (document.readyState === "loading") {
    await new Promise(resolve => document.addEventListener("DOMContentLoaded", resolve, { once: true }));
  }
  window.portalUser = user;
  document.getElementById("portalApp").hidden = false;
  document.dispatchEvent(new CustomEvent("portal:ready", { detail: user }));
}

if (document.getElementById("loginForm")) setupLoginPage();
else if (document.getElementById("portalApp")) setupPortalPage();
