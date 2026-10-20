// =====================================================================
// employee_portal/assets/js/attendance.js
// =====================================================================

const form        = document.getElementById("filter-form");
const kpiGrid     = document.getElementById("kpi-grid");
const rowsEl      = document.getElementById("rows");
const qrDisplay   = document.getElementById("qr-display");
const qrDemoDateEl = document.getElementById("qr_demo_date");
const qrDemoClear  = document.getElementById("qr_demo_clear");
const qrDemoNote   = document.getElementById("qr_demo_note");
const QR_DEMO_KEY  = "unitop_qr_demo_date";

function getQrDemoDate() {
    const value = localStorage.getItem(QR_DEMO_KEY) || "";
    return /^\d{4}-\d{2}-\d{2}$/.test(value) ? value : "";
}

function updateQrDemoNote() {
    if (!qrDemoNote) return;
    const value = getQrDemoDate();
    qrDemoNote.textContent = value ? `Using demo date: ${value}` : "Using today's date";
}

function initQrDemoDate() {
    if (!qrDemoDateEl) return;
    qrDemoDateEl.value = getQrDemoDate();
    updateQrDemoNote();

    qrDemoDateEl.addEventListener("change", () => {
        if (qrDemoDateEl.value) localStorage.setItem(QR_DEMO_KEY, qrDemoDateEl.value);
        else localStorage.removeItem(QR_DEMO_KEY);
        updateQrDemoNote();
        loadMyQR();
    });

    if (qrDemoClear) {
        qrDemoClear.addEventListener("click", () => {
            localStorage.removeItem(QR_DEMO_KEY);
            qrDemoDateEl.value = "";
            updateQrDemoNote();
            loadMyQR();
        });
    }
}

function statusBadge(status, late) {
    if (status === "Clocked In") return `<span class="badge" style="background:#e0f2fe;color:#0369a1;">Clocked In</span>`;
    if (late > 0) return `<span class="badge" style="background:#fef3c7;color:#92400e;">Late</span>`;
    if (status === "Present") return `<span class="badge" style="background:#dff5e1;color:#1e7d32;">Present</span>`;
    return `<span class="badge" style="background:#fde8e8;color:#b3261e;">${escapeHtml(status ?? "—")}</span>`;
}

// =====================================================================
// QR CODE
// =====================================================================
let qrRefreshTimer = null;

async function loadMyQR() {
    if (qrRefreshTimer) { clearTimeout(qrRefreshTimer); qrRefreshTimer = null; }
    try {
        const demoDate = getQrDemoDate();
        const { data } = await api.get("me_qr.php", demoDate ? { params: { demo_date: demoDate } } : undefined);
        const qr = data.data;

        if (!qr.token || qr.purpose === "complete") {
            qrDisplay.innerHTML = `
                <div style="text-align:center;padding:30px 20px;color:#6b7280;">
                    <div style="font-size:42px;margin-bottom:10px;">✓</div>
                    <div style="font-size:14px;font-weight:600;color:#1a1f2e;margin-bottom:6px;">
                        Attendance complete for today
                    </div>
                    <div style="font-size:12.5px;">
                        Use the overtime request below if you still need to file OT.
                    </div>
                </div>
            `;
            return;
        }

        qrDisplay.innerHTML = `
            <div style="text-align:center;">
                <div id="qr-holder" style="display:inline-block;padding:14px;background:#fff;border:3px solid #0038A8;border-radius:12px;-webkit-user-select:none;user-select:none;-webkit-touch-callout:none;"></div>
                <div style="margin-top:14px;font-size:13px;color:#6b7280;">
                    <div style="font-weight:600;color:#1a1f2e;">Expires: ${escapeHtml(qr.expires_at)}</div>
                    <div style="margin-top:4px;">${qr.purpose === "time_in" ? "Clock in" : qr.purpose === "time_out" ? "Clock out" : "Overtime"} · show this at the on-site scanner</div>
                    <div style="margin-top:4px;font-size:11.5px;">This code refreshes automatically. A screenshot stops working after it expires.</div>
                </div>
            </div>
        `;

        // Render the QR
        const holder = document.getElementById("qr-holder");
        new QRCode(holder, {
            text: qr.token,
            width: 240,
            height: 240,
            colorDark: "#0038A8",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H,
        });

        // Refresh before the token expires, even while the employee is in line.
        const refreshIn = Math.max(15, Number(qr.ttl || 120) - 30) * 1000;
        qrRefreshTimer = setTimeout(loadMyQR, refreshIn);

    } catch (err) {
        const code = err?.response?.status;
        const msg = apiErrorMessage(err);
        qrDisplay.innerHTML = `
            <div style="text-align:center;padding:30px 20px;color:#6b7280;">
                <div style="font-size:42px;margin-bottom:10px;">${code === 403 ? "🔒" : "📷"}</div>
                <div style="font-size:14px;font-weight:600;color:#1a1f2e;margin-bottom:6px;">
                    ${code === 403 ? "QR code unavailable" : "No QR code on file"}
                </div>
                <div style="font-size:12.5px;">${escapeHtml(msg)}</div>
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

/** Local YYYY-MM-DD (never toISOString: that is UTC and shifts the date). */
function ymd(d) {
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** The correction date window: a year either side. */
function initCorrectionDates() {

    const today = new Date();
    correctionDateEl.max = ymd(new Date(today.getTime() + 365 * 24 * 60 * 60 * 1000));
    correctionDateEl.min = ymd(new Date(today.getTime() - 365 * 24 * 60 * 60 * 1000));
    if (!correctionDateEl.value) correctionDateEl.value = ymd(today);
    syncCorrectionInputs();
}

// =====================================================================
// OVERTIME REQUESTS
//
// The employee files a request in the portal, HR approves it, and the actual
// overtime is clocked in/out at the kiosk. The server derives the day type and
// rate from the date; the client only supplies the window and the reason.
// =====================================================================
const otForm    = document.getElementById("overtime-form");
const otRowsEl  = document.getElementById("ot-rows");
const otCount   = document.getElementById("ot-count");
const otDateEl  = document.getElementById("ot_date");
const otStartEl = document.getElementById("ot_start");
const otEndEl   = document.getElementById("ot_end");
const otReasonEl = document.getElementById("ot_reason");
const otDemoEl  = document.getElementById("ot_demo_mode");

function otBadge(status) {
    const map = {
        Pending:   { bg: "#fef3c7", color: "#92400e" },
        Approved:  { bg: "#e0f2fe", color: "#0369a1" },
        Rejected:  { bg: "#fde8e8", color: "#b3261e" },
        Completed: { bg: "#dff5e1", color: "#1e7d32" },
    };
    const s = map[status] || { bg: "#e5e7eb", color: "#6b7280" };
    return `<span class="badge" style="background:${s.bg};color:${s.color};">${escapeHtml(status)}</span>`;
}

function renderOvertime(rows) {
    if (otCount) otCount.textContent = `${rows.length} record(s)`;

    if (!rows.length) {
        otRowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">You haven't filed an overtime request yet.</td></tr>`;
        return;
    }

    otRowsEl.innerHTML = rows.map((r) => {
        const reason = r.reason
            ? `<span style="color:#6b7280;font-size:12px;">${escapeHtml(r.reason)}</span>`
            : `<span style="color:#9ca3af;">&mdash;</span>`;
        const hours = r.hours !== null && r.hours !== undefined
            ? Number(r.hours).toFixed(2)
            : "&mdash;";
        const actual = r.time_in
            ? `${correctionTime(r.time_in)}${r.time_out ? " &ndash; " + correctionTime(r.time_out) : " &ndash; running"}`
            : "&mdash;";
        return `
            <tr>
                <td>${escapeHtml(r.ot_date)}</td>
                <td class="col-center">${correctionTime(r.requested_start)} &ndash; ${correctionTime(r.requested_end)}</td>
                <td class="col-center">${actual}</td>
                <td class="col-center">${escapeHtml(r.day_type || r.ot_type)}</td>
                <td class="col-center">${Number(r.rate_multiplier).toFixed(2)}x</td>
                <td style="text-align:right;">${hours}</td>
                <td class="col-center">${otBadge(r.status)}</td>
                <td>${reason}</td>
            </tr>
        `;
    }).join("");
}

async function loadOvertime() {
    otRowsEl.innerHTML = `<tr class="loading-row"><td colspan="8">Loading&hellip;</td></tr>`;
    try {
        const { data } = await api.get("me_overtime_requests.php");
        renderOvertime(data.data.rows || []);
    } catch (err) {
        otRowsEl.innerHTML = `<tr class="empty-row"><td colspan="8">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

if (otForm) {
    otForm.addEventListener("submit", async (e) => {
        e.preventDefault();

        const otDate = otDateEl.value;
        const start  = otStartEl.value;
        const end    = otEndEl.value;
        const reason = (otReasonEl.value || "").trim();

        if (!otDate || !start || !end) {
            await alertDialog("Pick the overtime date, start and end.", "warning");
            return;
        }
        if (end <= start) {
            await alertDialog("The requested end must be after the requested start.", "warning");
            return;
        }
        if (reason.length < 10) {
            await alertDialog("Please describe the reason in at least 10 characters.", "warning");
            return;
        }

        if (!await confirmDialog(
            `File overtime for ${otDate}, ${start} to ${end}? HR must approve it before you can clock it in.`,
            { title: "Confirm Overtime Request", icon: "question", confirmText: "File Request" }
        )) return;

        try {
            const { data } = await api.post("me_overtime_requests.php", {
                ot_date: otDate,
                requested_start: start,
                requested_end: end,
                reason: reason,
                demo_mode: !!(otDemoEl && otDemoEl.checked),
            });
            showToast(data.message, "success");
            otForm.reset();
            if (otDemoEl) otDemoEl.checked = false;
            otDateEl.value = ymd(new Date());
            otStartEl.value = "18:00";
            otEndEl.value = "20:00";
            await loadOvertime();
        } catch (err) {
            showToast(apiErrorMessage(err), "error");
        }
    });
}

form.addEventListener("submit", (e) => { e.preventDefault(); loadData(); });

document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("from_date").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("to_date").value = `${y}-${m}-${String(last).padStart(2, "0")}`;

    initQrDemoDate();
    loadMyQR();
    loadData();
    initCorrectionDates();
    loadCorrections();

    if (otDateEl) otDateEl.value = ymd(new Date());
    if (otStartEl) otStartEl.value = "18:00";
    if (otEndEl) otEndEl.value = "20:00";
    loadOvertime();
});