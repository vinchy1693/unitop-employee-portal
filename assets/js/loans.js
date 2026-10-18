// =====================================================================
// employee_portal/assets/js/loans.js
// =====================================================================

const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const kpiGrid     = document.getElementById("kpi-grid");
const payRowsEl   = document.getElementById("payment-rows");
const payCountEl  = document.getElementById("payment-count");

function formatMoney(n) {
    return "PHP " + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

function statusBadge(status) {
    const map = {
        "Active":    { bg: "#dff5e1", color: "#1e7d32" },
        "Ongoing":   { bg: "#dff5e1", color: "#1e7d32" },
        "Approved":  { bg: "#dff5e1", color: "#1e7d32" },
        "Paid":      { bg: "#e8eefc", color: "#0038A8" },
        "Completed": { bg: "#e8eefc", color: "#0038A8" },
        "Settled":   { bg: "#e8eefc", color: "#0038A8" },
        "Cancelled": { bg: "#fde8e8", color: "#b3261e" },
        "Rejected":  { bg: "#fde8e8", color: "#b3261e" },
    };
    const s = map[status] || { bg: "#e5e7eb", color: "#6b7280" };
    return `<span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;background:${s.bg};color:${s.color};">${escapeHtml(status || "Unknown")}</span>`;
}

function kpiTile(label, value, note, colour) {
    return `
        <div class="kpi-tile${colour ? " " + colour : ""}">
            <div class="kpi-label">${escapeHtml(label)}</div>
            <div class="kpi-value">${escapeHtml(value)}</div>
            <div class="kpi-note">${escapeHtml(note)}</div>
        </div>
    `;
}

async function loadLoans() {
    kpiGrid.innerHTML = `<div class="kpi-tile"><div class="kpi-label">Loading...</div></div>`;
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="7">Loading...</td></tr>`;
    payRowsEl.innerHTML = `<tr class="loading-row"><td colspan="4">Loading...</td></tr>`;

    try {
        const { data } = await api.get("me_loans.php");
        renderLoans(Array.isArray(data.data) ? data.data : []);
    } catch (err) {
        const message = escapeHtml(apiErrorMessage(err));
        kpiGrid.innerHTML = `
            <div class="kpi-tile" style="border-left-color:#CE1126;grid-column:1/-1;">
                <div class="kpi-label">Failed</div>
                <div class="kpi-value" style="font-size:14px;color:#CE1126;">${message}</div>
            </div>
        `;
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">Failed: ${message}</td></tr>`;
        payRowsEl.innerHTML = `<tr class="empty-row"><td colspan="4">Failed: ${message}</td></tr>`;
        payCountEl.textContent = "0 record(s)";
    }
}

function renderLoans(loans) {
    countEl.textContent = `${loans.length} record(s)`;

    const totalOutstanding = loans.reduce((sum, l) => sum + Number(l.outstanding_balance || 0), 0);
    const openLoans = loans.filter((l) => Number(l.outstanding_balance || 0) > 0);
    const monthly = openLoans.reduce((sum, l) => sum + Number(l.monthly_amortization || 0), 0);

    kpiGrid.innerHTML =
        kpiTile("Total outstanding", formatMoney(totalOutstanding), `Across ${loans.length} loan(s) on record`, "kpi-blue") +
        kpiTile("Monthly amortization", formatMoney(monthly), `${openLoans.length} loan(s) still being paid`, "kpi-yellow");

    if (loans.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="7">You have no loans on record.</td></tr>`;
        payRowsEl.innerHTML = `<tr class="empty-row"><td colspan="4">No loan payments recorded yet.</td></tr>`;
        payCountEl.textContent = "0 record(s)";
        return;
    }

    rowsEl.innerHTML = loans.map((l) => `
        <tr>
            <td>#${escapeHtml(l.loan_id)}</td>
            <td>${escapeHtml(l.loan_type || "-")}</td>
            <td style="text-align:right;">${formatMoney(l.principal_amount)}</td>
            <td style="text-align:right;">${formatMoney(l.amount_paid)}</td>
            <td style="text-align:right;font-weight:700;">${formatMoney(l.outstanding_balance)}</td>
            <td style="text-align:right;">${formatMoney(l.monthly_amortization)}</td>
            <td class="col-center">${statusBadge(l.status)}</td>
        </tr>
    `).join("");

    // Flatten each loan's payment history into one newest-first list.
    const payments = [];
    loans.forEach((l) => {
        (Array.isArray(l.payments) ? l.payments : []).forEach((p) => {
            payments.push({
                payment_date: p.payment_date,
                loan_id: l.loan_id,
                loan_type: l.loan_type,
                payslip_id: p.payslip_id,
                amount_paid: p.amount_paid,
                payment_id: p.payment_id,
            });
        });
    });
    payments.sort((a, b) => {
        const byDate = String(b.payment_date || "").localeCompare(String(a.payment_date || ""));
        return byDate !== 0 ? byDate : Number(b.payment_id || 0) - Number(a.payment_id || 0);
    });

    payCountEl.textContent = `${payments.length} record(s)`;

    if (payments.length === 0) {
        payRowsEl.innerHTML = `<tr class="empty-row"><td colspan="4">No loan payments recorded yet.</td></tr>`;
        return;
    }

    payRowsEl.innerHTML = payments.map((p) => `
        <tr>
            <td>${escapeHtml(p.payment_date || "-")}</td>
            <td>${escapeHtml((p.loan_type || "Loan") + " #" + p.loan_id)}</td>
            <td class="col-center">${escapeHtml(p.payslip_id)}</td>
            <td style="text-align:right;">${formatMoney(p.amount_paid)}</td>
        </tr>
    `).join("");
}

document.addEventListener("DOMContentLoaded", loadLoans);
