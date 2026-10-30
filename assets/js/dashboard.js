// =====================================================================
// employee_portal/assets/js/dashboard.js
// =====================================================================

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

async function loadDashboard() {
    const grid = document.getElementById("kpi-grid");
    try {
        const { data: body } = await api.get("me_dashboard.php");
        if (!body.success) throw new Error(body.message);

        const { latest_payslip, attendance, leave_total, pending_leaves } = body.data;

        grid.innerHTML = `
            <a class="kpi-tile kpi-blue" href="payslips.html">
                <div class="kpi-label">Latest Net Pay</div>
                <div class="kpi-value">${latest_payslip ? formatMoney(latest_payslip.net_pay) : "—"}</div>
                <div class="kpi-note">${latest_payslip
                    ? `${latest_payslip.start_date} → ${latest_payslip.end_date}`
                    : "No payslips yet"}</div>
            </a>

            <a class="kpi-tile kpi-green" href="attendance.html">
                <div class="kpi-label">Days Present (This Month)</div>
                <div class="kpi-value">${attendance.days_present}</div>
                <div class="kpi-note">${attendance.hours_worked.toFixed(2)} hours worked</div>
            </a>

            <a class="kpi-tile kpi-yellow" href="attendance.html">
                <div class="kpi-label">Late Minutes (This Month)</div>
                <div class="kpi-value">${attendance.late_minutes}</div>
                <div class="kpi-note">${attendance.late_minutes > 0 ? "Affects payroll" : "Perfect attendance!"}</div>
            </a>

            <a class="kpi-tile kpi-blue" href="leave.html">
                <div class="kpi-label">Leave Balance</div>
                <div class="kpi-value">${leave_total.toFixed(1)}</div>
                <div class="kpi-note">${pending_leaves} pending request${pending_leaves === 1 ? "" : "s"}</div>
            </a>
        `;
    } catch (err) {
        grid.innerHTML = `<div class="kpi-tile" style="border-left-color:#CE1126; grid-column: 1 / -1;">
            <div class="kpi-label">Failed to load</div>
            <div class="kpi-value" style="font-size:14px;color:#CE1126;font-weight:500;">${escapeHtml(apiErrorMessage(err))}</div>
        </div>`;
    }
}

document.addEventListener("DOMContentLoaded", loadDashboard);
async function loadNotifications() {
    const list = document.getElementById("notification-list");
    const count = document.getElementById("notification-count");
    if (!list) return;
    try {
        const { data } = await api.get("me_notifications.php");
        const payload = data.data || { count: 0, items: [] };
        count.textContent = String(payload.count || 0);
        if (!payload.items.length) {
            list.innerHTML = `<div class="notification-empty">You're all caught up.</div>`;
            return;
        }
        list.innerHTML = payload.items.map((item) => `
            <a class="notification-item" href="${escapeHtml(item.url)}">
                <div><strong>${escapeHtml(item.title)}</strong><span>${escapeHtml(item.detail)}</span></div>
                <span class="notification-badge">${Number(item.count || 0)}</span>
            </a>
        `).join("");
    } catch (err) {
        list.innerHTML = `<div class="notification-empty">Notifications unavailable.</div>`;
    }
}
document.addEventListener("DOMContentLoaded", loadNotifications);
