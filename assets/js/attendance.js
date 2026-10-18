// =====================================================================
// employee_portal/assets/js/attendance.js
// =====================================================================

const form        = document.getElementById("filter-form");
const kpiGrid     = document.getElementById("kpi-grid");
const rowsEl      = document.getElementById("rows");
const qrDisplay   = document.getElementById("qr-display");

function statusBadge(status, late) {
    if (status === "Clocked In") return `<span class="badge" style="background:#e0f2fe;color:#0369a1;">Clocked In</span>`;
    if (late > 0) return `<span class="badge" style="background:#fef3c7;color:#92400e;">Late</span>`;
    if (status === "Present") return `<span class="badge" style="background:#dff5e1;color:#1e7d32;">Present</span>`;
    return `<span class="badge" style="background:#fde8e8;color:#b3261e;">${escapeHtml(status ?? "—")}</span>`;
}

// =====================================================================
// QR CODE
// =====================================================================
async function loadMyQR() {
    try {
        const { data } = await api.get("me_qr.php");
        const qr = data.data;

        qrDisplay.innerHTML = `
            <div style="text-align:center;">
                <div id="qr-holder" style="display:inline-block;padding:14px;background:#fff;border:3px solid #0038A8;border-radius:12px;"></div>
                <div style="margin-top:14px;font-size:13px;color:#6b7280;">
                    <div style="font-weight:600;color:#1a1f2e;">Issued: ${escapeHtml(qr.issued_date)}</div>
                    <div style="margin-top:4px;">Show this code to the office scanner</div>
                    <div style="margin-top:8px;font-family:monospace;font-size:11.5px;word-break:break-all;">${escapeHtml(qr.code_value)}</div>
                </div>
            </div>
        `;

        // Prefill the manual fallback box with this employee's own code.
        const manualEl = document.getElementById("manual-code");
        if (manualEl) manualEl.value = qr.code_value;

        // Render the QR
        const holder = document.getElementById("qr-holder");
        new QRCode(holder, {
            text: qr.code_value,
            width: 240,
            height: 240,
            colorDark: "#0038A8",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H,
        });

    } catch (err) {
        // No QR yet, or error
        qrDisplay.innerHTML = `
            <div style="text-align:center;padding:30px 20px;color:#6b7280;">
                <div style="font-size:42px;margin-bottom:10px;">📷</div>
                <div style="font-size:14px;font-weight:600;color:#1a1f2e;margin-bottom:6px;">
                    No QR code on file
                </div>
                <div style="font-size:12.5px;">
                    Please ask HR to generate a QR code for you.
                </div>
            </div>
        `;
    }
}

// =====================================================================
// ATTENDANCE HISTORY
// =====================================================================
async function loadData() {
    const from = document.getElementById("from_date").value;
    const to   = document.getElementById("to_date").value;
    if (!from || !to) return;

    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    kpiGrid.innerHTML = `<div class="kpi-tile"><div class="kpi-label">Loading…</div></div>`;

    try {
        const { data } = await api.get("me_attendance.php", { params: { from, to } });
        const payload = data.data;
        renderKPI(payload);
        renderRows(payload.records);
    } catch (err) {
        const msg = escapeHtml(apiErrorMessage(err));
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed: ${msg}</td></tr>`;
        kpiGrid.innerHTML = `<div class="kpi-tile" style="border-left-color:#CE1126;grid-column:1/-1;">
            <div class="kpi-label">Failed</div>
            <div class="kpi-value" style="font-size:14px;color:#CE1126;">${msg}</div>
        </div>`;
    }
}

function renderKPI(p) {
    kpiGrid.innerHTML = `
        <div class="kpi-tile kpi-green">
            <div class="kpi-label">Days Present</div>
            <div class="kpi-value">${p.days_present}</div>
            <div class="kpi-note">${p.from} → ${p.to}</div>
        </div>
        <div class="kpi-tile kpi-blue">
            <div class="kpi-label">Total Hours Worked</div>
            <div class="kpi-value">${Number(p.total_hours).toFixed(2)}</div>
            <div class="kpi-note">Across all days</div>
        </div>
        <div class="kpi-tile kpi-yellow">
            <div class="kpi-label">Total Late Minutes</div>
            <div class="kpi-value">${p.total_late}</div>
            <div class="kpi-note">${p.total_late > 0 ? "Affects payroll" : "Perfect attendance!"}</div>
        </div>
    `;
}

function renderRows(rows) {
    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">No attendance records in this range.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr>
            <td>${escapeHtml(r.work_date)}</td>
            <td class="col-center">${r.time_in ? escapeHtml(r.time_in) : "—"}</td>
            <td class="col-center">${r.time_out ? escapeHtml(r.time_out) : `<span style="color:#b3261e;font-style:italic;">Pending</span>`}</td>
            <td style="text-align:right;">${r.hours_worked !== null ? Number(r.hours_worked).toFixed(2) : "—"}</td>
            <td style="text-align:right;">${r.minutes_late ?? 0}</td>
            <td class="col-center">${statusBadge(r.status, Number(r.minutes_late || 0))}</td>
        </tr>
    `).join("");
}

// =====================================================================
// MANUAL FALLBACK — record attendance by submitting the QR value
// =====================================================================
const manualBtn    = document.getElementById("manual-submit");
const manualInput  = document.getElementById("manual-code");
const manualResult = document.getElementById("manual-result");

function showManualResult(kind, text) {
    if (!manualResult) return;
    manualResult.classList.remove("hidden");
    manualResult.style.display    = "block";
    manualResult.style.padding    = "10px 12px";
    manualResult.style.borderRadius = "6px";
    manualResult.style.fontSize   = "13px";
    manualResult.style.background = kind === "error" ? "#fde8e8" : "#dff5e1";
    manualResult.style.color      = kind === "error" ? "#b3261e" : "#1e7d32";
    manualResult.textContent      = text;
}

if (manualBtn && manualInput) {
    const submitManual = async () => {
        const code = (manualInput.value || "").trim();
        if (!code) { showManualResult("error", "Enter your QR code value first."); return; }
        manualBtn.disabled = true;
        try {
            const { data } = await api.post("me_scan_attendance.php", { code_value: code });
            showManualResult("success", data.message || "Attendance recorded.");
            loadData();
        } catch (err) {
            showManualResult("error", apiErrorMessage(err));
        } finally {
            manualBtn.disabled = false;
        }
    };
    manualBtn.addEventListener("click", submitManual);
    manualInput.addEventListener("keydown", (e) => {
        if (e.key === "Enter") { e.preventDefault(); submitManual(); }
    });
}

// =====================================================================
// ATTENDANCE CORRECTIONS - file a request, track HR's decision
//
// The employee can only file for their own attendance: the API scopes every
// request to the session employee and never reads an employee_id from here.
// =====================================================================
const correctionForm   = document.getElementById("correction-form");
const correctionRowsEl = document.getElementById("correction-rows");
const correctionCount  = document.getElementById("correction-count");
const correctionTypeEl = document.getElementById("correction_type");
const correctionDateEl = document.getElementById("correction_date");
const correctionInEl   = document.getElementById("requested_time_in");
const correctionOutEl  = document.getElementById("requested_time_out");
const correctionWhyEl  = document.getElementById("correction_reason");

const CORRECTION_TYPE_LABELS = {
    clock_in:  "Clock-in time",
    clock_out: "Clock-out time",
    both:      "Both times",
};

function correctionTime(value) {
    if (!value) return "&mdash;";
    const parts = String(value).split(":");
    let hour = parseInt(parts[0], 10);
    if (isNaN(hour)) return escapeHtml(value);
    const minute = parts[1] || "00";
    const suffix = hour >= 12 ? "PM" : "AM";
    hour = hour % 12;
    if (hour === 0) hour = 12;
    return `${hour}:${minute} ${suffix}`;
}

function correctionBadge(status) {
    const map = {
        Pending:  { bg: "#fef3c7", color: "#92400e" },
        Approved: { bg: "#dff5e1", color: "#1e7d32" },
        Rejected: { bg: "#fde8e8", color: "#b3261e" },
    };
    const s = map[status] || { bg: "#e5e7eb", color: "#6b7280" };
    return `<span class="badge" style="background:${s.bg};color:${s.color};">${escapeHtml(status)}</span>`;
}

function correctionNowCell(r) {
    if (!r.has_attendance) {
        return `<span style="color:#b3261e;font-size:12px;">no record</span>`;
    }
    return `${correctionTime(r.current_time_in)}<br>`
        + `<span style="color:#6b7280;font-size:11px;">${correctionTime(r.current_time_out)}</span>`;
}

function correctionRequestedCell(r) {
    if (r.correction_type === "clock_in")  return `<strong>${correctionTime(r.requested_time_in)}</strong>`;
    if (r.correction_type === "clock_out") return `<strong>${correctionTime(r.requested_time_out)}</strong>`;
    return `<strong>${correctionTime(r.requested_time_in)}</strong><br>`
        + `<strong>${correctionTime(r.requested_time_out)}</strong>`;
}

function renderCorrections(rows) {
    correctionCount.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        correctionRowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">You haven't filed any corrections yet.</td></tr>`;
        return;
    }

    correctionRowsEl.innerHTML = rows.map((r) => {
        const reason = r.reason
            ? `<div style="color:#6b7280;font-size:11px;margin-top:2px;">${escapeHtml(r.reason)}</div>`
            : "";
        const remarks = r.review_remarks
            ? escapeHtml(r.review_remarks)
            : `<span style="color:#9ca3af;">&mdash;</span>`;

        return `
            <tr>
                <td>${escapeHtml(r.work_date)}${reason}</td>
                <td class="col-center">${escapeHtml(CORRECTION_TYPE_LABELS[r.correction_type] || r.correction_type)}</td>
                <td class="col-center">${correctionNowCell(r)}</td>
                <td class="col-center">${correctionRequestedCell(r)}</td>
                <td class="col-center">${correctionBadge(r.status)}</td>
                <td>${remarks}</td>
            </tr>
        `;
    }).join("");
}

async function loadCorrections() {
    correctionRowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading&hellip;</td></tr>`;
    try {
        const { data } = await api.get("me_attendance_corrections.php");
        renderCorrections(data.data.rows || []);
    } catch (err) {
        correctionRowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

/** Enable only the time fields the chosen type actually needs. */
function syncCorrectionInputs() {
    const type    = correctionTypeEl.value;
    const needIn  = type === "clock_in"  || type === "both";
    const needOut = type === "clock_out" || type === "both";

    correctionInEl.disabled  = type !== "" && !needIn;
    correctionOutEl.disabled = type !== "" && !needOut;
    correctionInEl.required  = needIn;
    correctionOutEl.required = needOut;
}

correctionTypeEl.addEventListener("change", syncCorrectionInputs);

correctionForm.addEventListener("submit", async (e) => {
    e.preventDefault();

    const workDate = correctionDateEl.value;
    const type     = correctionTypeEl.value;
    const reason   = (correctionWhyEl.value || "").trim();

    if (!workDate || !type) {
        await alertDialog("Pick a work date and what needs fixing.", "warning");
        return;
    }
    if (reason.length < 10) {
        await alertDialog("Please describe the reason in at least 10 characters.", "warning");
        return;
    }

    const payload = {
        work_date: workDate,
        correction_type: type,
        reason: reason,
        requested_time_in: correctionInEl.disabled ? null : (correctionInEl.value || null),
        requested_time_out: correctionOutEl.disabled ? null : (correctionOutEl.value || null),
    };

    if (!await confirmDialog(
        `File a correction for ${workDate}? HR will review it before anything changes.`,
        { title: "Confirm Correction Request", icon: "question", confirmText: "File Request" }
    )) return;

    try {
        const { data } = await api.post("me_attendance_corrections.php", payload);
        showToast(data.message, "success");
        correctionForm.reset();
        correctionDateEl.value = ymd(new Date());
        syncCorrectionInputs();
        await loadCorrections();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
});

/** Today is the newest date a correction may target; 90 days back the oldest. */
function initCorrectionDates() {
    const pad = (n) => String(n).padStart(2, "0");
    const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

    const today = new Date();
    correctionDateEl.max = ymd(new Date(today.getTime() + 365 * 24 * 60 * 60 * 1000));
    correctionDateEl.min = ymd(new Date(today.getTime() - 365 * 24 * 60 * 60 * 1000));
    if (!correctionDateEl.value) correctionDateEl.value = ymd(today);
    syncCorrectionInputs();
}

form.addEventListener("submit", (e) => { e.preventDefault(); loadData(); });

document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("from_date").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("to_date").value = `${y}-${m}-${String(last).padStart(2, "0")}`;

    loadMyQR();
    loadData();
    initCorrectionDates();
    loadCorrections();
});