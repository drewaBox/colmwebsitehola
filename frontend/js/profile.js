// Student profile: used by the student ("My Profile" page + dashboard documents) and by the admin (profile window).
(function () {
  const VERIFY_CLASS = { Pending: "review-pending", Verified: "review-verified", Declined: "review-rejected" };

  const verifyPill = label => `<span class="review-pill ${VERIFY_CLASS[label] || ""}">${escapeHtml(label)}</span>`;
  const initials = name => name.split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0].toUpperCase()).join("");

  function photoHtml(student, canEdit) {
    const picture = student.has_photo
      ? `<img class="profile-photo" src="${API_URL}photo.php?id=${student.id}&v=${Date.now()}" alt="Photo of ${escapeHtml(student.full_name)}" data-saved-src="${API_URL}photo.php?id=${student.id}&v=${Date.now()}">`
      : `<div class="profile-photo profile-photo-empty" aria-label="No photo">${escapeHtml(initials(student.full_name))}</div>`;
    const controls = canEdit ? `
      <div class="photo-normal">
        <label class="mini-btn photo-btn">${student.has_photo ? "Change Photo" : "Upload Photo"}
          <input type="file" id="photoInput" accept=".jpg,.jpeg,.png,.webp" hidden></label>
        ${student.has_photo ? '<button type="button" class="mini-btn bad" id="photoRemove">Remove</button>' : ""}
      </div>
      <div class="photo-pending" hidden>
        <span class="photo-note">Preview only - not saved yet</span>
        <div class="photo-pending-buttons">
          <button type="button" class="request-next-btn" id="photoSave">Save</button>
          <button type="button" class="request-back-btn" id="photoCancel">Cancel</button>
        </div>
      </div>
      <p class="photo-message" id="photoMessage" role="status" aria-live="polite"></p>
      <small>JPG, PNG or WEBP, up to 2 MB, at least 200 x 200 pixels. Use a formal photo (plain background).</small>` : "";
    return `<div class="profile-photo-box"><span class="photo-title">Profile Picture</span><div class="photo-frame">${picture}</div><div class="photo-actions">${controls}</div></div>`;
  }

  function documentRows(documents, withLink) {
    if (!documents.length) return '<tr><td colspan="5" class="requests-empty">No documents submitted yet.</td></tr>';
    return documents.map(doc => `
      <tr class="student-data-row"><td data-label="Document"><strong>${escapeHtml(doc.document_name)}</strong><br><small>${escapeHtml(doc.tracking_no)}</small></td>
        <td data-label="Requirement">${escapeHtml(doc.requirement_name)}</td>
        <td data-label="File">${withLink ? `<a href="${API_URL}file.php?id=${doc.file_id}&inline=1" target="_blank" rel="noopener">${escapeHtml(doc.original_name)}</a>` : escapeHtml(doc.original_name)}</td>
        <td data-label="Status">${verifyPill(doc.verification)}</td>
        <td data-label="Reason">${doc.verification === "Declined" && doc.review_remarks ? "Declined — " + escapeHtml(doc.review_remarks)
            : doc.verification === "Verified" ? "Approved" : "Waiting for review"}</td></tr>`).join("");
  }

  function field(label, value) {
    return `<div><dt>${label}</dt><dd>${value ? escapeHtml(value) : '<span class="muted">Not set</span>'}</dd></div>`;
  }

  // The full profile card. canEdit = the person may change the photo.
  function profileHtml(data, canEdit, forAdmin) {
    const s = data.student, sum = data.summary;
    const overall = data.verification_status;
    const overallPill = ["Pending", "Verified", "Declined"].includes(overall) ? verifyPill(overall) : `<span class="review-pill">${escapeHtml(overall)}</span>`;
    return `
      <article class="profile-card">
        <header class="profile-head">
          ${photoHtml(s, canEdit)}
          <div class="profile-title">
            <h2>${escapeHtml(s.full_name)}</h2>
            <p>${escapeHtml(s.course || "Course not set")}${s.year_label ? " · " + escapeHtml(s.year_label) : ""}${s.section ? " · " + escapeHtml(s.section) : ""}</p>
            <p class="profile-id">Student ID: <strong>${escapeHtml(s.student_no || s.username)}</strong></p>
            <p>Verification status: ${overallPill}</p>
          </div>
        </header>
        <div class="profile-grid">
          <section>
            <h3>Student information</h3>
            <dl class="detail-grid">
              ${field("Full name", s.full_name)}${field("Student ID number", s.student_no || s.username)}
              ${field("School level", s.school_level)}${field("Course", s.course)}${field("Year level", s.year_label)}
              ${field("Section", s.section)}${field("Email", s.email)}${field("Contact number", s.contact_no)}
            </dl>
          </section>
          <section>
            <h3>Account information</h3>
            <dl class="detail-grid">
              ${field("Username", s.username)}${field("Account type", "Student")}
              ${field("Account status", s.is_active ? "Active" : "Disabled")}${field("Created", Portal.formatDate(s.created_at))}
              ${field("Document requests", String(data.request_count))}
            </dl>
          </section>
        </div>
        <section>
          <h3>Submitted documents <span class="doc-summary">${sum.verified} verified · ${sum.pending} pending · ${sum.declined} declined</span></h3>
          <div class="requests-table-wrap"><table class="requests-table">
            <thead><tr><th>Document</th><th>Requirement</th><th>File</th><th>Status</th><th>Reason</th></tr></thead>
            <tbody>${documentRows(data.documents, true)}</tbody>
          </table></div>
        </section>
      </article>`;
  }

  // ----- profile photo: choose -> preview -> Save or Cancel (works in the page and in the admin window) -----
  // Nothing is sent to the server until the person presses Save. Cancel never talks to the server.
  const PHOTO_TYPES = ["image/jpeg", "image/png", "image/webp"];
  let pendingPhoto = null;            // { file, url } while a preview is shown

  // Checks the picture in the browser (the server checks it again). Returns an error text or "".
  function photoProblem(file) {
    return new Promise(resolve => {
      if (!PHOTO_TYPES.includes(file.type)) { resolve("Invalid File. Please choose a JPG, PNG or WEBP picture."); return; }
      if (file.size > 2 * 1048576) { resolve("The photo is too large. Please choose a picture of 2 MB or less."); return; }
      const url = URL.createObjectURL(file);
      const probe = new Image();
      probe.onload = () => {
        URL.revokeObjectURL(url);
        if (probe.naturalWidth < 200 || probe.naturalHeight < 200) resolve("The photo is too small. Use a picture of at least 200 x 200 pixels.");
        else if (probe.naturalWidth > 8000 || probe.naturalHeight > 8000) resolve("The photo is too large. Use a picture of at most 8000 x 8000 pixels.");
        else resolve("");
      };
      probe.onerror = () => { URL.revokeObjectURL(url); resolve("This picture is corrupted or could not be read."); };
      probe.src = url;
    });
  }

  function showPhotoMessage(container, text, ok) {
    const box = container.querySelector("#photoMessage");
    if (!box) return;
    box.textContent = text;
    box.className = "photo-message" + (text ? (ok ? " success" : " error") : "");
  }

  function setPending(container, on) {
    container.querySelector(".photo-normal").hidden = on;
    container.querySelector(".photo-pending").hidden = !on;
  }

  // Shows the chosen picture in the photo frame. url = null puts the original picture (or the initials) back.
  function showInFrame(container, url, student) {
    const frame = container.querySelector(".photo-frame");
    let img = frame.querySelector("img.profile-photo");
    if (url) {
      if (!img) {
        frame.replaceChildren();
        img = document.createElement("img");
        img.className = "profile-photo";
        img.alt = "New profile picture (preview)";
        frame.append(img);
      }
      if (!img.dataset.savedSrc && img.getAttribute("src")) img.dataset.savedSrc = img.getAttribute("src");
      img.src = url;
    } else if (img && img.dataset.savedSrc) {
      img.src = img.dataset.savedSrc;
      img.alt = "Photo of " + student.full_name;
    } else {
      frame.innerHTML = `<div class="profile-photo profile-photo-empty" aria-label="No photo">${escapeHtml(initials(student.full_name))}</div>`;
    }
  }

  function dropPending() {
    if (pendingPhoto) URL.revokeObjectURL(pendingPhoto.url);
    pendingPhoto = null;
  }

  function handlePhotoEvents(container, student, isAdmin, reload) {
    container.addEventListener("change", async event => {
      if (event.target.id !== "photoInput") return;
      const file = event.target.files[0];
      event.target.value = "";                         // so choosing the same file again still triggers a change
      if (!file) return;
      const problem = await photoProblem(file);
      if (problem) {
        showPhotoMessage(container, problem, false);
        Portal.toast(problem, "error", "Invalid File");
        return;
      }
      dropPending();                                    // choosing another picture only replaces the preview
      pendingPhoto = { file, url: URL.createObjectURL(file) };
      showInFrame(container, pendingPhoto.url, student);
      setPending(container, true);
      showPhotoMessage(container, "", true);
    });

    container.addEventListener("click", async event => {
      const target = event.target;
      if (target.id === "photoCancel") {
        dropPending();
        showInFrame(container, null, student);
        setPending(container, false);
        showPhotoMessage(container, "", true);
        return;
      }
      if (target.id === "photoSave") {
        if (!pendingPhoto) return;
        const form = new FormData();
        form.append("photo", pendingPhoto.file);
        if (isAdmin) form.append("user_id", student.id);
        target.disabled = true;
        target.textContent = "Saving...";
        try {
          await Api.postForm("photo.php", form);
          dropPending();
          Portal.toast("Profile picture updated successfully.", "success", "✓ Saved");
          await reload(true);                           // redraws only the photo, not the whole page
          const fresh = container.querySelector("#photoMessage");
          if (fresh) { fresh.textContent = "✓ Profile picture updated successfully."; fresh.className = "photo-message success"; }
        } catch (error) {
          target.disabled = false;
          target.textContent = "Save";
          showPhotoMessage(container, "Unable to update profile picture. Please try again.", false);
          Portal.toast(error.status === 422 || error.status === 413 ? error.message : "Unable to update profile picture. Please try again.", "error");
        }
        return;
      }
      if (target.id === "photoRemove") {
        if (!window.confirm("Remove the profile picture?")) return;
        try {
          await Api.postJson("photo.php", { user_id: student.id }, { action: "remove" });
          Portal.toast("Profile picture removed.", "success");
          await reload(true);
        } catch (error) { Portal.toast("Unable to remove the profile picture. Please try again.", "error"); }
      }
    });
  }

  // ----- student: My Profile page and the dashboard -----
  async function loadOwnProfile(photoOnly = false) {
    const box = document.getElementById("profileContent");
    if (pendingPhoto && !photoOnly) { refreshDashboardDocsOnly(); return; }   // do not throw away a preview the student is looking at
    try {
      const data = await Api.get("students.php", { action: "profile" });
      if (photoOnly) {
        const frame = box.querySelector(".profile-photo-box");
        if (frame) frame.outerHTML = photoHtml(data.student, true);
      } else {
        box.innerHTML = profileHtml(data, true, false);
        ownStudent = data.student;
      }
      ownStudent = data.student;
      fillDashboardDocs(data);
    } catch (error) {
      if (!photoOnly) box.innerHTML = `<p class="requests-empty">${escapeHtml(error.message)}</p>`;
    }
  }
  let ownStudent = { id: 0, full_name: "" };

  async function refreshDashboardDocsOnly() {
    try { fillDashboardDocs(await Api.get("students.php", { action: "profile" })); } catch (error) { /* keep the old list */ }
  }

  function fillDashboardDocs(data) {
    const body = document.getElementById("dashboardDocsBody");
    if (!body) return;
    const sum = data.summary;
    document.getElementById("docSummary").innerHTML = sum.total
      ? `${verifyPill("Verified")} ${sum.verified} ${verifyPill("Pending")} ${sum.pending} ${verifyPill("Declined")} ${sum.declined}` : "";
    body.innerHTML = documentRows(data.documents.slice(0, 8), false);
  }

  // ----- admin: open any student's profile in a window -----
  window.openStudentProfile = async function (studentId) {
    try {
      const data = await Api.get("students.php", { action: "profile", id: studentId });
      const modal = Portal.openModal("", true);
      const body = modal.querySelector(".modal-body");
      body.innerHTML = profileHtml(data, true, true);
      const redrawPhoto = async () => {
        const fresh = await Api.get("students.php", { action: "profile", id: studentId });
        const frame = body.querySelector(".profile-photo-box");
        if (frame) frame.outerHTML = photoHtml(fresh.student, true);
      };
      handlePhotoEvents(body, data.student, true, redrawPhoto);
      dropPending();
    } catch (error) {
      Portal.toast(error.message, "error");
    }
  };

  document.addEventListener("portal:ready", event => {
    if (event.detail.role !== "student") return;
    const box = document.getElementById("profileContent");
    if (!box) return;
    handlePhotoEvents(box, { id: event.detail.id, full_name: event.detail.full_name }, false, loadOwnProfile);
    loadOwnProfile();
  });
  document.addEventListener("page:change", event => {
    if (window.portalUser?.role === "student" && ["My Profile", "Dashboard"].includes(event.detail)) loadOwnProfile();
  });
  // Leaving the profile page with an unsaved preview = Cancel.
  document.addEventListener("page:change", event => {
    if (pendingPhoto && event.detail !== "My Profile") {
      dropPending();
      const box = document.getElementById("profileContent");
      if (box && box.querySelector(".photo-pending")) { showInFrame(box, null, ownStudent); setPending(box, false); }
    }
  });
  // After a request changed (for example the student sent new files) the document list changes too.
  document.addEventListener("requests:changed", () => { if (window.portalUser?.role === "student") loadOwnProfile(); });
})();
