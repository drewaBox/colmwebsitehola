// The chatbot: a floating icon at the bottom-right that opens a small chat window.
// Flow: type a message -> POST api/chat.php -> PHP asks Ollama (free, local) -> the answer comes back -> shown in the window.
(function () {
  const fab = document.getElementById("chatFab");
  const windowBox = document.getElementById("chatWindow");
  const messagesBox = document.getElementById("chatMessages");
  const form = document.getElementById("chatForm");
  const input = document.getElementById("chatInput");
  const sendButton = document.getElementById("chatSend");
  const ERROR_TEXT = "Sorry, the chatbot is temporarily unavailable. Please try again.";
  let historyLoaded = false;
  let waiting = false;                    // true while an answer is on its way: stops double sending

  // textContent (not innerHTML), so nothing the chatbot writes can run as HTML or JavaScript.
  function addMessage(sender, text, extraClass = "") {
    const bubble = document.createElement("div");
    bubble.className = `chat-bubble chat-${sender} ${extraClass}`.trim();
    bubble.textContent = text;
    messagesBox.append(bubble);
    messagesBox.scrollTop = messagesBox.scrollHeight;
    return bubble;
  }

  async function loadHistory() {
    if (historyLoaded) return;
    historyLoaded = true;
    messagesBox.replaceChildren();
    addMessage("assistant", `Hello ${window.portalUser.full_name.split(" ")[0]}! Ask me about documents, requirements, your request status or the process.`);
    try {
      const data = await Api.get("chat.php");
      data.messages.forEach(m => addMessage(m.sender, m.message));
    } catch (error) {
      /* the greeting is enough; the history is only a bonus */
    }
  }

  function openChat() {
    windowBox.hidden = false;
    fab.hidden = true;
    fab.setAttribute("aria-expanded", "true");
    loadHistory();
    input.focus();
  }
  function closeChat() {
    windowBox.hidden = true;
    fab.hidden = false;
    fab.setAttribute("aria-expanded", "false");
  }
  window.openChat = openChat;

  async function send(text) {
    text = text.trim();
    if (text === "" || waiting) return;               // no empty messages, no double sending
    waiting = true;
    sendButton.disabled = true;
    addMessage("user", text);
    input.value = "";
    const typing = addMessage("assistant", "Typing...", "chat-loading");
    try {
      const data = await Api.postJson("chat.php", { message: text }, {}, 120000);
      typing.remove();
      addMessage("assistant", data.answer);
    } catch (error) {
      typing.remove();
      // The server already wrote a friendly sentence for 4xx/503 errors; for anything else use our own.
      const known = [422, 429, 503].includes(error.status);
      addMessage("assistant", known ? error.message : ERROR_TEXT, "chat-error");
    } finally {
      waiting = false;
      sendButton.disabled = false;
      input.focus();
    }
  }

  form.addEventListener("submit", event => { event.preventDefault(); send(input.value); });
  fab.addEventListener("click", openChat);
  document.getElementById("chatClose").addEventListener("click", closeChat);
  document.getElementById("chatClear").addEventListener("click", async () => {
    if (!window.confirm("Delete this conversation?")) return;
    try {
      await Api.postJson("chat.php", {}, { action: "clear" });
      historyLoaded = false;
      loadHistory();
    } catch (error) { addMessage("assistant", ERROR_TEXT, "chat-error"); }
  });
  document.addEventListener("keydown", event => { if (event.key === "Escape" && !windowBox.hidden) closeChat(); });

  // The chatbot is for students, registrar and admin. The cashier only has payments, so no icon for the cashier.
  document.addEventListener("portal:ready", event => {
    if (event.detail.role !== "cashier") fab.hidden = false;
  });
})();
