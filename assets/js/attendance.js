// =====================================================================
// employee_portal/assets/js/attendance.js
// =====================================================================

const form     = document.getElementById("filter-form");
const kpiGrid  = document.getElementById("kpi-grid");
const rowsEl   = document.getElementById("rows");

function formatDateLong(s) { return s; }

function statusBadge(status, late) {
    if (status === "Clocked In") return `<span class="badge" style="background:#e0f2fe;color:#0369a1;">Clocked In</span>`;
    if (late > 0) return `<span class="badge" style="background:#fef3c7;color:#92400e;">Late</span>`;
    if (status === "Present") return `<span class="badge" style="background:#dff5e1;color:#1e7d32;">Present</span>`;
    return `<span class="badge" style="background:#fde8e8;color:#b3261e;">${escapeHtml(status ?? "—")}</span>`;
}

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

form.addEventListener("submit", (e) => { e.preventDefault(); loadData(); });

document.addEventListener("DOMContentLoaded", () => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    document.getElementById("from_date").value = `${y}-${m}-01`;
    const last = new Date(y, now.getMonth() + 1, 0).getDate();
    document.getElementById("to_date").value = `${y}-${m}-${String(last).padStart(2, "0")}`;
    loadData();
});