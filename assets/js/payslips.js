// =====================================================================
// employee_portal/assets/js/payslips.js
// =====================================================================

const rowsEl      = document.getElementById("rows");
const countEl     = document.getElementById("record-count");
const detailModal = document.getElementById("detail-modal");
const detailBody  = document.getElementById("detail-body");

function formatMoney(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

async function loadRows() {
    rowsEl.innerHTML = `<tr class="loading-row"><td colspan="6">Loading…</td></tr>`;
    try {
        const { data } = await api.get("me_payslips.php");
        renderRows(data.data);
    } catch (err) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">Failed: ${escapeHtml(apiErrorMessage(err))}</td></tr>`;
    }
}

function renderRows(rows) {
    countEl.textContent = `${rows.length} record(s)`;

    if (rows.length === 0) {
        rowsEl.innerHTML = `<tr class="empty-row"><td colspan="6">You haven't received any payslips yet.</td></tr>`;
        return;
    }

    rowsEl.innerHTML = rows.map((r) => `
        <tr style="cursor:pointer;" data-id="${r.payslip_id}">
            <td>${escapeHtml(r.start_date)} → ${escapeHtml(r.end_date)}</td>
            <td>${escapeHtml(r.period_type)}</td>
            <td style="text-align:right;">${formatMoney(r.gross_pay)}</td>
            <td style="text-align:right;">${formatMoney(r.total_deductions)}</td>
            <td style="text-align:right;font-weight:700;">${formatMoney(r.net_pay)}</td>
            <td class="col-center"><span style="color:#0038A8;font-size:12px;">View →</span></td>
        </tr>
    `).join("");

    rowsEl.querySelectorAll("[data-id]").forEach((tr) => {
        tr.addEventListener("click", () => openPayslip(Number(tr.dataset.id)));
    });
}

async function openPayslip(id) {
    detailModal.classList.remove("hidden");
    detailBody.innerHTML = `<div style="text-align:center;padding:40px;color:#888;">Loading…</div>`;

    try {
        const { data } = await api.get("me_payslips.php", { params: { id } });
        renderPayslip(data.data);
    } catch (err) {
        detailBody.innerHTML = `<div style="padding:20px;color:#CE1126;">Failed: ${escapeHtml(apiErrorMessage(err))}</div>`;
    }
}

function renderPayslip(p) {
    const allowances = Array.isArray(p.allowances) ? p.allowances : [];
    const deductions = Array.isArray(p.deductions) ? p.deductions : [];

    const allowRows = allowances.map((a) => `
        <div class="line"><span>${escapeHtml(a.allowance_type)}</span><span>${formatMoney(a.amount)}</span></div>
    `).join("");

    const dedRows = deductions.map((d) => `
        <div class="line"><span>${escapeHtml(d.deduction_type)}</span><span>${formatMoney(d.amount)}</span></div>
    `).join("");

    detailBody.innerHTML = `
        <div class="payslip-doc">
            <div class="report-header">
                <div>
                    <h2 style="margin:0;font-size:22px;color:#0038A8;">UNITOP</h2>
                    <div style="font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:1px;">Payslip</div>
                </div>
                <div class="report-meta">
                    <div><strong>Period:</strong> ${escapeHtml(p.start_date)} → ${escapeHtml(p.end_date)}</div>
                    <div><strong>Type:</strong> ${escapeHtml(p.period_type)}</div>
                    <div><strong>Payslip #:</strong> ${escapeHtml(p.payslip_id)}</div>
                </div>
            </div>

            <div style="background:#f5f6fa;padding:12px 16px;border-radius:8px;margin-bottom:20px;display:grid;grid-template-columns:2fr 1fr 1.5fr 1.5fr;gap:12px;">
                <div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Employee</div><div style="font-weight:600;">${escapeHtml(p.employee_name)}</div></div>
                <div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Emp No.</div><div style="font-weight:600;">${escapeHtml(p.employee_number)}</div></div>
                <div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Department</div><div style="font-weight:600;">${escapeHtml(p.department_name ?? "—")}</div></div>
                <div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">Position</div><div style="font-weight:600;">${escapeHtml(p.position_title ?? "—")}</div></div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:20px;">
                <div>
                    <h4 style="border-bottom:1px solid #e5e7eb;padding-bottom:6px;font-size:12.5px;color:#0038A8;text-transform:uppercase;">Earnings</h4>
                    <div class="line"><span>Basic Pay</span><span>${formatMoney(p.basic_pay)}</span></div>
                    <div class="line"><span>Overtime Pay</span><span>${formatMoney(p.overtime_pay)}</span></div>
                    <div class="line"><span>Premium Pay (Rest Day / Holiday)</span><span>${formatMoney(p.premium_pay)}</span></div>
                    ${allowRows}
                    <div class="line" style="border-top:1px solid #cbd5e1;margin-top:6px;padding-top:8px;font-weight:700;"><span>Gross Pay</span><span>${formatMoney(p.gross_pay)}</span></div>
                </div>

                <div>
                    <h4 style="border-bottom:1px solid #e5e7eb;padding-bottom:6px;font-size:12.5px;color:#0038A8;text-transform:uppercase;">Deductions</h4>
                    <div class="line"><span>SSS</span><span>${formatMoney(p.sss_ee)}</span></div>
                    <div class="line"><span>PhilHealth</span><span>${formatMoney(p.philhealth_ee)}</span></div>
                    <div class="line"><span>Pag-IBIG</span><span>${formatMoney(p.pagibig_ee)}</span></div>
                    <div class="line"><span>Withholding Tax</span><span>${formatMoney(p.withholding_tax)}</span></div>
                    ${dedRows}
                    <div class="line" style="border-top:1px solid #cbd5e1;margin-top:6px;padding-top:8px;font-weight:700;"><span>Total Deductions</span><span>${formatMoney(p.total_deductions)}</span></div>
                </div>
            </div>

            <div style="background:#0038A8;color:#fff;padding:16px 22px;border-radius:8px;display:flex;justify-content:space-between;align-items:center;font-weight:700;letter-spacing:1px;">
                <span>NET PAY</span>
                <span style="font-size:22px;color:#FCD116;">${formatMoney(p.net_pay)}</span>
            </div>
        </div>
    `;
}

document.getElementById("detail-close").addEventListener("click", () => detailModal.classList.add("hidden"));
document.getElementById("detail-done").addEventListener("click", () => detailModal.classList.add("hidden"));
document.getElementById("detail-print").addEventListener("click", () => window.print());
detailModal.addEventListener("click", (e) => { if (e.target === detailModal) detailModal.classList.add("hidden"); });

document.addEventListener("DOMContentLoaded", loadRows);