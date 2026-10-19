// =====================================================================
// employee_portal/assets/js/my_qr.js
//
// QR-only display module. The server issues a 120-second, one-time token
// bound to the next attendance action. This page:
//   * refreshes the token before it expires,
//   * hides the QR when the page is backgrounded,
//   * suppresses the context menu, long-press selection and printing.
//
// Browser pages cannot block the OS screenshot key; the expiry and one-time
// use are the security controls. A native kiosk wrapper can add FLAG_SECURE.
// =====================================================================

const displayEl = document.getElementById("qr-display");
let refreshTimer = null;
let countdownTimer = null;
let secondsLeft = 0;
let currentToken = "";

function clearTimers() {
    if (refreshTimer) { clearTimeout(refreshTimer); refreshTimer = null; }
    if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
}

function renderState(title, detail, kind) {
    const icon = kind === "error" ? "!" : kind === "paused" ? "⏸" : "✓";
    displayEl.innerHTML = `
        <div class="qr-state">
            <div style="font-size:34px;margin-bottom:8px;">${icon}</div>
            <div style="font-weight:700;color:#1a1f2e;margin-bottom:4px;">${escapeHtml(title)}</div>
            <div>${escapeHtml(detail)}</div>
        </div>`;
}

function tick() {
    if (secondsLeft <= 0) {
        clearTimers();
        currentToken = "";
        displayEl.innerHTML = `<div class="qr-state">QR expired. Refreshing…</div>`;
        loadQr();
        return;
    }
    secondsLeft--;
    const countEl = document.getElementById("qr-count");
    if (countEl) {
        const m = Math.floor(secondsLeft / 60);
        const s = String(secondsLeft % 60).padStart(2, "0");
        countEl.textContent = `${m}:${s}`;
        countEl.classList.toggle("expiring", secondsLeft <= 20);
    }
}

async function loadQr() {
    clearTimers();
    if (document.hidden) {
        renderState("QR paused", "Return to this page to show a fresh code.", "paused");
        return;
    }

    try {
        const { data } = await api.get("me_qr.php");
        const qr = data.data;

        if (!qr.token || qr.purpose === "complete") {
            currentToken = "";
            renderState("Attendance complete", "You have already clocked in and out today. Use the attendance page for overtime.", "ok");
            return;
        }

        currentToken = qr.token;
        secondsLeft = Math.max(1, Number(qr.ttl || 120));
        const actionLabel = qr.purpose === "time_in" ? "Clock in"
            : qr.purpose === "time_out" ? "Clock out"
            : "Overtime";

        displayEl.innerHTML = `
            <div id="qr-holder"></div>
            <div class="qr-count" id="qr-count"></div>
            <div class="qr-purpose">${actionLabel}</div>
            <div style="margin-top:6px;font-size:11.5px;color:#6b7280;">
                Expires at ${escapeHtml(qr.expires_at)}
            </div>`;

        new QRCode(document.getElementById("qr-holder"), {
            text: qr.token,
            width: 240,
            height: 240,
            colorDark: "#0038A8",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H,
        });

        // Refresh 30 seconds before expiry, while the employee is still in line.
        const refreshIn = Math.max(15, secondsLeft - 30) * 1000;
        refreshTimer = setTimeout(loadQr, refreshIn);
        countdownTimer = setInterval(tick, 1000);
        tick();

    } catch (err) {
        currentToken = "";
        const code = err?.response?.status;
        renderState(
            code === 403 ? "QR unavailable" : "Could not load QR",
            apiErrorMessage(err),
            "error"
        );
    }
}

// A screenshot taken while the page is hidden is less useful because the QR is
// removed from the DOM; the token also expires.
document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
        clearTimers();
        displayEl.innerHTML = `<div class="qr-state">QR paused. Return to this page.</div>`;
    } else {
        loadQr();
    }
});

// Deterrents only. They do not stop the OS screenshot tool or a second camera.
document.addEventListener("contextmenu", (e) => e.preventDefault());
document.addEventListener("dragstart", (e) => e.preventDefault());
document.addEventListener("selectstart", (e) => e.preventDefault());
window.addEventListener("beforeprint", () => {
    displayEl.innerHTML = `<div class="qr-state">QR codes are not printable. Use the on-site scanner.</div>`;
});

document.addEventListener("DOMContentLoaded", loadQr);
