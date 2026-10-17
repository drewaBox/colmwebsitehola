// Shared by the request form (newrequest.js) and the "fix my documents" window (requests.js):
// how an upload box looks in each state, and the quick checks done in the browser before a file is sent.
//
// A box state is one of:
//   undefined / no file                      -> "Upload your document" + Choose File
//   { state: "checking" }                    -> "✓ File uploaded: name.pdf" + "Document pre-check: In progress"
//   { state: "verified" }                    -> green box, "Document pre-check passed"
//   { state: "declined", message }           -> red box, the reason and "Upload Again"
//   { state: "needs_correction", message }   -> red box, the reason and "Upload Again"
//   { state: "pending", message }            -> the check could not finish: "Try Again" (the student cannot skip it)

const isVerifiedState = state => !!state && state.state === "verified";

// key = text that identifies the box (used by the Remove button), inputId = id of the hidden <input type="file">.
function uploadBoxHtml(state, file, inputId, typesText, maxMb, key) {
  if (!file || !state) {
    return `<label class="request-requirement-dropzone" for="${inputId}">
        <span class="material-symbol request-dropzone-icon" aria-hidden="true">cloud_upload</span>
        <strong>Upload your document</strong>
        <span class="upload-choose">Choose File</span>
        <span class="request-requirement-formats">Allowed formats: ${typesText} (Max ${maxMb} MB)</span>
        <span class="request-required-file-name">Or drag the file here</span>
      </label>`;
  }
  const name = `<span class="request-required-file-name upload-filename" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>`;
  const uploaded = `<span class="upload-selected"><span class="material-symbol" aria-hidden="true">check_circle</span> ✓ File uploaded:</span>${name}`;
  const remove = `<button type="button" class="upload-remove" data-remove="${escapeHtml(key)}">Remove file</button>`;

  if (state.state === "checking") {
    return `<div class="request-requirement-dropzone has-file is-checking">${uploaded}
      <span class="upload-status"><span class="upload-spinner" aria-hidden="true"></span> Document pre-check: In progress...</span></div>`;
  }
  if (state.state === "verified") {
    return `<div class="request-requirement-dropzone has-file is-accepted">${uploaded}
      <span class="upload-status upload-ok">✓ Document pre-check passed</span>${remove}</div>`;
  }
  if (state.state === "pending") {
    return `<div class="request-requirement-dropzone has-file is-pending">${uploaded}
      <span class="upload-status">Document pre-check incomplete - ${escapeHtml(state.message)}</span>
      <button type="button" class="upload-again" data-retry="${escapeHtml(key)}">Try Again</button>${remove}</div>`;
  }
  const title = state.state === "needs_correction" ? "Needs correction" : "Not accepted";
  return `<div class="request-requirement-dropzone is-declined" role="alert">
      <span class="upload-declined-title"><span class="material-symbol" aria-hidden="true">cancel</span> Document pre-check: ${title}</span>
      ${name}
      <span class="upload-reason">${escapeHtml(state.message)}</span>
      <button type="button" class="upload-again" data-again="${inputId}">Upload Again</button></div>`;
}

// Quick checks in the browser (the server checks everything again). Returns null when fine,
// otherwise { title, text }. The input needs data-max-mb and data-types.
async function fileProblem(file, input) {
  if (!file.size) return { title: "Invalid File", text: "The selected file is empty." };
  const maxMb = Number(input.dataset.maxMb);
  if (file.size > maxMb * 1048576) return { title: "File too large", text: `The selected file must be ${maxMb} MB or smaller.` };
  const ext = file.name.split(".").pop().toLowerCase();
  const allowed = input.dataset.types.split(",").map(t => t.toUpperCase());
  if (!input.dataset.types.split(",").includes(ext)) {
    const list = allowed.length > 1 ? allowed.slice(0, -1).join(", ") + " or " + allowed[allowed.length - 1] : allowed[0];
    return { title: "Invalid File", text: `Please upload a supported ${list} file.` };
  }
  // Look at the first bytes of the file, not only at its name.
  const signatures = { pdf: [0x25, 0x50, 0x44, 0x46, 0x2d], jpg: [0xff, 0xd8, 0xff], jpeg: [0xff, 0xd8, 0xff], png: [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a] };
  const bytes = new Uint8Array(await file.slice(0, 8).arrayBuffer());
  if (!signatures[ext].every((byte, i) => bytes[i] === byte)) {
    return { title: "Invalid File", text: "The file content does not match its extension. The file may be damaged." };
  }
  return null;
}

// Turns the answer of api/verify.php into a box state.
function stateFromCheck(check) {
  const states = { VERIFIED: "verified", DECLINED: "declined", NEEDS_CORRECTION: "needs_correction", PENDING: "pending" };
  return { state: states[check.status] || "pending", checkId: check.id, message: check.message };
}

// Sends one file to api/verify.php. Returns { status, id, message, title }.
// Throws ApiError when the file is not allowed or the server could not be reached.
async function verifyUpload(file, requirementId, replaceCheckId) {
  const form = new FormData();
  form.append("file", file);
  form.append("requirement_id", requirementId);
  if (replaceCheckId) form.append("replace_check_id", replaceCheckId);
  return (await Api.postForm("verify.php", form, {}, 180000)).check;
}

// Asks the server to check a stored file again (after "Verification could not be completed").
async function retryUpload(checkId) {
  return (await Api.postJson("verify.php", { id: checkId }, { action: "retry" }, 180000)).check;
}

// The state to show when the call itself failed (server down, file not allowed ...). It can always be tried again.
function stateFromError(error) {
  const serverProblem = error.status === 0 || error.status >= 500;
  if (serverProblem) return { state: "pending", checkId: null, message: "The verification service could not be reached. Please try again." };
  return { state: "declined", checkId: null, message: error.message };
}
