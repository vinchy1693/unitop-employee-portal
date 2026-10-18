// =====================================================================
// employee_portal/assets/js/profile.js
// =====================================================================

const CONTACT_FIELDS = [
    "mobile_number",
    "email",
    "address",
    "emergency_contact_name",
    "emergency_contact_number",
];

const form       = document.getElementById("profile-form");
const saveBtn    = document.getElementById("save-btn");
const msgEl      = document.getElementById("msg");
const detailsRows = document.getElementById("details-rows");

function showMsg(text, type) {
    msgEl.textContent = text;
    msgEl.className = "msg " + type;
    msgEl.classList.remove("hidden");
}

function hideMsg() {
    msgEl.textContent = "";
    msgEl.className = "msg hidden";
}

function valueOrDash(value) {
    if (value === null || value === undefined || String(value).trim() === "") return "-";
    return escapeHtml(value);
}

function fillForm(profile) {
    CONTACT_FIELDS.forEach((field) => {
        const el = document.getElementById(field);
        if (el) el.value = profile[field] ?? "";
    });
}

function renderDetails(profile) {
    const rows = [
        ["Employee number", profile.employee_number],
        ["Name", profile.employee_name],
        ["Position", profile.position_title],
        ["Department", profile.department_name],
        ["Date hired", profile.date_hired],
        ["Employment status", profile.employment_status],
        ["TIN", profile.tin_number],
        ["SSS number", profile.sss_number],
        ["PhilHealth number", profile.philhealth_number],
        ["Pag-IBIG number", profile.pagibig_number],
    ];

    detailsRows.innerHTML = rows.map(([label, value]) => `
        <tr>
            <td>${escapeHtml(label)}</td>
            <td>${valueOrDash(value)}</td>
        </tr>
    `).join("");
}

async function loadProfile() {
    detailsRows.innerHTML = `<tr class="loading-row"><td colspan="2">Loading...</td></tr>`;
    try {
        const { data } = await api.get("me_profile.php");
        fillForm(data.data);
        renderDetails(data.data);
    } catch (err) {
        const message = escapeHtml(apiErrorMessage(err));
        showMsg("Could not load your profile: " + message, "error");
        detailsRows.innerHTML = `<tr class="empty-row"><td colspan="2">Failed to load: ${message}</td></tr>`;
    }
}

form.addEventListener("submit", async (e) => {
    e.preventDefault();
    hideMsg();

    const payload = {};
    CONTACT_FIELDS.forEach((field) => {
        payload[field] = document.getElementById(field).value.trim();
    });

    saveBtn.disabled = true;
    try {
        const { data } = await api.patch("me_profile.php", payload);
        fillForm(data.data);
        renderDetails(data.data);
        showMsg(data.message || "Contact details updated.", "success");
        showToast(data.message || "Contact details updated.", "success");
    } catch (err) {
        const message = apiErrorMessage(err);
        showMsg(message, "error");
        showToast(message, "error");
    } finally {
        saveBtn.disabled = false;
    }
});

document.addEventListener("DOMContentLoaded", loadProfile);
