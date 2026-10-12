<?php

/**
 * Floating GSO Assistant widget.
 *
 * Rendered on authenticated pages only. Every message is written to the DOM
 * with textContent, never innerHTML, so neither a stored user message nor an
 * AI reply can inject markup or script into the page.
 *
 * The welcome copy and quick prompts come from the server-side preset for the
 * session's role. That is presentation only - the backend re-authorizes every
 * query against the session user, so the chips a user sees never widen what
 * they can actually retrieve.
 */

if (empty($_SESSION["user_id"])) {
    return;
}

require_once dirname(__DIR__, 2) . "/models/role_helper.php";

// The assistant is offered only to roles that are allowed to use it.
if (!gsoUserHasPermission((string) ($_SESSION["role"] ?? ""), "chatbot.use")) {
    return;
}

require_once dirname(__DIR__, 2) . "/models/ai_presentation_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Role is read from the server session, never from the client.
$chatPreset = gsoChatPresentation($_SESSION["role"] ?? null);
$chatPrompts = (array) ($chatPreset["prompts"] ?? []);
?>
<button
    type="button"
    class="gso-chat-launcher"
    id="gsoChatLauncher"
    aria-expanded="false"
    aria-controls="gsoChatPanel"
    aria-label="Ask GSO"
    title="Ask GSO"
>
    <i class="fa-solid fa-robot" aria-hidden="true"></i>
    <span>Ask GSO</span>
</button>

<section
    class="gso-chat-panel"
    id="gsoChatPanel"
    role="dialog"
    aria-label="<?php echo htmlspecialchars((string) $chatPreset["title"], ENT_QUOTES, "UTF-8"); ?>"
    hidden
>
    <header class="gso-chat-header">
        <span class="gso-chat-brand" aria-hidden="true"><i class="fa-solid fa-robot"></i></span>
        <div class="gso-chat-header-title">
            <strong><?php echo htmlspecialchars((string) $chatPreset["title"]); ?></strong>
            <span><?php echo htmlspecialchars((string) $chatPreset["subtitle"]); ?></span>
        </div>
        <button type="button" class="gso-chat-icon-btn" id="gsoChatNew" title="New conversation" aria-label="Start a new conversation">
            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        </button>
        <button type="button" class="gso-chat-icon-btn" id="gsoChatClose" title="Close" aria-label="Close assistant">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
    </header>

    <div class="gso-chat-body">
        <div class="gso-chat-empty" id="gsoChatEmpty">
            <span class="gso-chat-empty-icon" aria-hidden="true"><i class="fa-solid fa-comments"></i></span>
            <h2 class="gso-chat-empty-title"><?php echo htmlspecialchars((string) $chatPreset["welcome_heading"]); ?></h2>
            <p class="gso-chat-empty-text"><?php echo htmlspecialchars((string) $chatPreset["welcome_body"]); ?></p>

            <div class="gso-chat-chips" id="gsoChatChips" role="group" aria-label="Suggested questions">
                <?php foreach ($chatPrompts as $prompt): ?>
                    <?php
                    $mode = in_array((string) ($prompt["mode"] ?? "send"), gsoChatPresentationModes(), true)
                        ? (string) $prompt["mode"]
                        : "send";
                    $label = (string) ($prompt["label"] ?? "");
                    $hint = $mode === "prefill" ? " (fills the message box)" : "";
                    ?>
                    <button
                        type="button"
                        class="gso-chat-chip"
                        data-mode="<?php echo htmlspecialchars($mode, ENT_QUOTES, "UTF-8"); ?>"
                        data-prompt="<?php echo htmlspecialchars((string) ($prompt["prompt"] ?? ""), ENT_QUOTES, "UTF-8"); ?>"
                        aria-label="<?php echo htmlspecialchars($label . $hint, ENT_QUOTES, "UTF-8"); ?>"
                    >
                        <i class="fa-solid <?php echo htmlspecialchars((string) ($prompt["icon"] ?? "fa-comment"), ENT_QUOTES, "UTF-8"); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($label); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="gso-chat-log" id="gsoChatLog" aria-live="polite" aria-atomic="false"></div>
    </div>

    <form class="gso-chat-form" id="gsoChatForm">
        <label class="gso-chat-sr-only" for="gsoChatInput">Message</label>
        <textarea
            id="gsoChatInput"
            name="message"
            rows="1"
            maxlength="1000"
            placeholder="<?php echo htmlspecialchars((string) $chatPreset["placeholder"], ENT_QUOTES, "UTF-8"); ?>"
            autocomplete="off"
        ></textarea>
        <button type="submit" class="gso-chat-send" id="gsoChatSend" aria-label="Send message">
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
        </button>
    </form>
</section>

<script>
(function () {
    const launcher = document.getElementById("gsoChatLauncher");
    const panel = document.getElementById("gsoChatPanel");
    const body = document.querySelector(".gso-chat-body");
    const empty = document.getElementById("gsoChatEmpty");
    const log = document.getElementById("gsoChatLog");
    const chips = document.getElementById("gsoChatChips");
    const form = document.getElementById("gsoChatForm");
    const input = document.getElementById("gsoChatInput");
    const sendBtn = document.getElementById("gsoChatSend");
    const newBtn = document.getElementById("gsoChatNew");
    const closeBtn = document.getElementById("gsoChatClose");

    if (!launcher || !panel || !log || !form || !input) {
        return;
    }

    const csrfToken = <?php echo json_encode($_SESSION["csrf_token"], JSON_UNESCAPED_SLASHES); ?>;
    const sendUrl = "../chatbot/send.php";

    let conversationId = null;
    let busy = false;

    function scrollToLatest() {
        if (body) {
            body.scrollTop = body.scrollHeight;
        }
    }

    function hideEmptyState() {
        if (empty && !empty.hidden) {
            empty.hidden = true;
        }
    }

    function showEmptyState() {
        if (empty) {
            empty.hidden = false;
        }
    }

    function appendMessage(text, variant) {
        const row = document.createElement("div");
        row.className = "gso-chat-row gso-chat-row-" + variant;

        if (variant === "assistant") {
            const avatar = document.createElement("span");
            avatar.className = "gso-chat-avatar";
            avatar.setAttribute("aria-hidden", "true");
            const icon = document.createElement("i");
            icon.className = "fa-solid fa-robot";
            avatar.appendChild(icon);
            row.appendChild(avatar);
        }

        const bubble = document.createElement("div");
        bubble.className = "gso-chat-msg gso-chat-msg-" + variant;
        // textContent, never innerHTML: stored text renders as text.
        bubble.textContent = text;
        row.appendChild(bubble);

        log.appendChild(row);
        scrollToLatest();
        return row;
    }

    function showThinking() {
        const row = document.createElement("div");
        row.className = "gso-chat-row gso-chat-row-assistant";
        row.id = "gsoChatThinking";

        const avatar = document.createElement("span");
        avatar.className = "gso-chat-avatar";
        avatar.setAttribute("aria-hidden", "true");
        const icon = document.createElement("i");
        icon.className = "fa-solid fa-robot";
        avatar.appendChild(icon);
        row.appendChild(avatar);

        const bubble = document.createElement("div");
        bubble.className = "gso-chat-typing";
        const label = document.createElement("span");
        label.className = "gso-chat-typing-label";
        label.textContent = "Checking";
        bubble.appendChild(label);

        const dots = document.createElement("span");
        dots.className = "gso-chat-dots";
        for (let i = 0; i < 3; i++) {
            dots.appendChild(document.createElement("i"));
        }
        bubble.appendChild(dots);

        row.appendChild(bubble);
        log.appendChild(row);
        scrollToLatest();
    }

    function hideThinking() {
        const thinking = document.getElementById("gsoChatThinking");
        if (thinking) {
            thinking.remove();
        }
    }

    function setBusy(state) {
        busy = state;
        if (sendBtn) {
            sendBtn.disabled = state;
        }
        input.disabled = state;
    }

    function resetInputHeight() {
        input.style.height = "auto";
    }

    async function postMessage(message) {
        const payload = new URLSearchParams();
        payload.append("csrf_token", csrfToken);
        payload.append("message", message);
        if (conversationId !== null) {
            payload.append("conversation_id", conversationId);
        }

        const response = await fetch(sendUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: payload.toString(),
            credentials: "same-origin"
        });

        let parsed = null;
        try {
            parsed = await response.json();
        } catch (error) {
            parsed = null;
        }

        return parsed;
    }

    async function sendMessage(text) {
        if (busy) {
            return;
        }

        const message = String(text || "").trim();
        if (message === "") {
            return;
        }

        hideEmptyState();
        appendMessage(message, "user");
        input.value = "";
        resetInputHeight();
        setBusy(true);
        showThinking();

        let payload;
        try {
            payload = await postMessage(message);
        } catch (error) {
            hideThinking();
            setBusy(false);
            appendMessage("Sorry, the assistant is unreachable right now. Please check your connection and try again.", "error");
            return;
        }

        hideThinking();
        setBusy(false);

        if (!payload) {
            appendMessage("Sorry, the assistant returned an unexpected response. Please try again.", "error");
            return;
        }

        if (payload.ok !== true) {
            appendMessage(payload.message || "Sorry, the assistant is temporarily unavailable. Please try again.", "error");
            if (payload.error_code === "conversation_not_found") {
                conversationId = null;
            }
            return;
        }

        conversationId = payload.conversation_id;
        appendMessage(payload.message, "assistant");
        input.focus();
    }

    function openPanel() {
        panel.hidden = false;
        launcher.hidden = true;
        launcher.setAttribute("aria-expanded", "true");
        input.focus();
    }

    function closePanel() {
        panel.hidden = true;
        launcher.hidden = false;
        launcher.setAttribute("aria-expanded", "false");
        launcher.focus();
    }

    launcher.addEventListener("click", openPanel);

    if (closeBtn) {
        closeBtn.addEventListener("click", closePanel);
    }

    if (newBtn) {
        newBtn.addEventListener("click", function () {
            if (busy) {
                return;
            }
            conversationId = null;
            log.textContent = "";
            showEmptyState();
            input.value = "";
            resetInputHeight();
            input.focus();
        });
    }

    if (chips) {
        chips.addEventListener("click", function (event) {
            const chip = event.target.closest(".gso-chat-chip");
            if (!chip || busy) {
                return;
            }

            const prompt = chip.getAttribute("data-prompt") || "";

            // A prefill chip seeds the box and waits: an availability question
            // is incomplete until the user names the resource or facility.
            if (chip.getAttribute("data-mode") === "prefill") {
                input.value = prompt;
                input.focus();
                const end = input.value.length;
                input.setSelectionRange(end, end);
                input.dispatchEvent(new Event("input"));
                return;
            }

            sendMessage(prompt);
        });
    }

    form.addEventListener("submit", function (event) {
        event.preventDefault();
        sendMessage(input.value);
    });

    input.addEventListener("keydown", function (event) {
        // Enter sends; Shift+Enter inserts a newline.
        if (event.key === "Enter" && !event.shiftKey) {
            event.preventDefault();
            sendMessage(input.value);
        }
    });

    input.addEventListener("input", function () {
        resetInputHeight();
        input.style.height = Math.min(input.scrollHeight, 120) + "px";
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && !panel.hidden) {
            closePanel();
        }
    });
})();
</script>
