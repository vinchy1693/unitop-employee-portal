// =====================================================================
// employee_portal/assets/js/leave.js
// =====================================================================

const form        = document.getElementById("leave-form");
const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const balancesGrid = document.getElementById("balances-grid");

function countBusinessDays(start, end) {
    if (!start || !end || start > end) return 0;
    if (start === end) return 1;   // a single-day request always counts as one day
    const s = new Date(start), e = new Date(end);
    let days = 0;
    for (let d = new Date(s); d <= e; d.setDate(d.getDate() + 1)) {
        const dow = d.getDay();
        if (dow !== 0 && dow !== 6) days++;
    }
    return days;
}

function statusBadge(status) {
    const map = {
        "Pending":   { bg: "#fef3c7", color: "#92400e" },
        "Approved":  { bg: "#dff5e1", color: "#1e7d32" },
        "Rejected":  { bg: "#fde8e8", color: "#b3261e" },
        "Cancelled": { bg: "#e5e7eb", color: "#6b7280" },
    };
    const s = map[status] || { bg: "#e5e7eb", color: "#6b7280" };
    return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${s.bg};color:${s.color};">${escapeHtml(status)}</span>`;
}

async function loadBalances() {
    try {
        const { data } = await api.get("me_leave_balances.php");
        const balances = data.data.balances;

        if (balances.length === 0) {
            balancesGrid.innerHTML = `<div class="kpi-tile"><div class="kpi-label">No leave types configured.</div></div>`;
            return;
        }

        balancesGrid.innerHTML = balances.map((b) => `
            <div class="kpi-tile kpi-blue">
                <div class="kpi-label">${escapeHtml(b.name)}</div>
                <div class="kpi-value">${Number(b.remaining_days).toFixed(1)}</div>
                <div class="kpi-note">of ${Number(b.days_per_year).toFixed(1)} days/year</div>
            </div>
        `).join("");
    } catch (err) {
        balancesGrid.innerHTML = `<div class="kpi-tile" style="border-left-color:#CE1126;grid-column:1/-1;">
            <div class="kpi-label">Failed</div>
            <div class="kpi-value" style="font-size:14px;color:#CE1126;">${escapeHtml(apiErrorMessage(err))}</div>
        </div>`;
    }
}

async function loadLeaveTypes() {
    const select = document.getElementById("leave_type_id");
    const cur = select.value;
    try {
        // Reuse the admin endpoint - it's public to logged-in users
        // Since we're in a different folder, hit it via a relative path to the admin site
        const res = await fetch("api/me_leave_types.php");
        const json = await res.json();
        if (!json.success) throw new Error(json.message);

        select.innerHTML = `<option value="">-- Select --</option>` +
            json.data.map((t) => `<option value="${t.leave_type_id}">${escapeHtml(t.name)}</option>`).join("");
        if (cur) select.value = cur;
    } catch (err) {
        // Fallback: hardcoded common types if cross-folder fetch fails
        select.innerHTML = `
            <option value="">-- Select --</option>
            <option value="1">Vacation Leave</option>
            <option value="2">Sick Leave</option>
            <option value="3">Maternity Leave</option>
            <option value="4">Paternity Leave</option>
            <option value="5">Birthday Leave</option>
        `;
    }
}

async function loadRequests() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    try {
        const { data } = await api.get("me_leave_requests.php");
        renderRequests(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRequests(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">You haven't filed any leave requests yet.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => {
        const days = countBusinessDays(r.start_date, r.end_date);
        let actions = "";
        if (r.status === "Pending") {
            actions = `<button class="btn btn-plain" data-cancel="${r.leave_id}">Cancel</button>`;
        } else {
            actions = `<span style="color:#888;font-size:12px;">—</span>`;
        }

        return `
            <tr>
                <td>${escapeHtml(r.leave_type_name)}</td>
                <td>${escapeHtml(r.start_date)}</td>
                <td>${escapeHtml(r.end_date)}</td>
                <td class="col-center">${days}</td>
                <td class="col-center">${statusBadge(r.status)}</td>
                <td class="col-center">${actions}</td>
            </tr>
        `;
    }).join("");

    rowsEl.querySelectorAll("[data-cancel]").forEach((btn) => {
        btn.addEventListener("click", () => cancelRequest(btn.dataset.cancel));
    });
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();

    const typeId = document.getElementById("leave_type_id").value;
    const start  = document.getElementById("start_date").value;
    const end    = document.getElementById("end_date").value;

    if (!typeId || !start || !end) return;

    const days = countBusinessDays(start, end);
    if (days === 0) {
        await alertDialog("The selected range has no working days.", "warning");
        return;
    }

    if (!await confirmDialog(
        `File a ${days}-day leave request from ${start} to ${end}?`,
        { title: "Confirm Leave Request", icon: "question", confirmText: "File Request" }
    )) return;

    try {
        const { data } = await api.post("me_leave_requests.php", {
            leave_type_id: typeId,
            start_date: start,
            end_date: end,
        });
        showToast(data.message, "success");
        form.reset();
        await loadRequests();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
});

async function cancelRequest(id) {
    if (!await confirmDialog(
        "Cancel this leave request? It will be withdrawn from HR review.",
        { title: "Cancel Leave Request", icon: "warning", confirmText: "Cancel Request" }
    )) return;

    try {
        const { data } = await api.patch("me_leave_requests.php", { leave_id: Number(id), status: "Cancelled" });
        showToast(data.message, "success");
        await loadRequests();
    } catch (err) {
        showToast(apiErrorMessage(err), "error");
    }
}

document.addEventListener("DOMContentLoaded", () => {
    loadBalances();
    loadLeaveTypes();
    loadRequests();
});