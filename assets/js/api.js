// =====================================================================
// employee_portal/assets/js/api.js
// =====================================================================

const api = axios.create({
    baseURL: "api/",
    headers: { "Content-Type": "application/json" },
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error?.response?.status === 401) {
            window.location.href = "login.html";
        }
        return Promise.reject(error);
    }
);

function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function apiErrorMessage(err) {
    return err?.response?.data?.message || err.message || "Something went wrong.";
}

function highlightNav() {
    const page = location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll("nav a[data-page]").forEach((a) => {
        if (a.dataset.page === page) a.classList.add("active");
    });
}

async function injectNavUser() {
    const nav = document.querySelector("nav");
    if (!nav) return;

    nav.querySelectorAll(".nav-user").forEach((el) => el.remove());

    try {
        const { data } = await api.get("auth.php?action=me");
        const user = data.data;

        const wrap = document.createElement("div");
        wrap.className = "nav-user";
        wrap.innerHTML = `
            <span class="nav-user-info">
                <span class="nav-user-name">${escapeHtml(user.employee_name)}</span>
                <span class="nav-user-role">${escapeHtml(user.employee_number)}</span>
            </span>
            <a href="#" class="nav-logout" id="nav-logout-btn">Log out</a>
        `;
        nav.appendChild(wrap);

        wrap.querySelector("#nav-logout-btn").addEventListener("click", async (e) => {
            e.preventDefault();
            if (!await confirmDialog("Log out?")) return;
            try {
                await api.post("auth.php?action=logout");
            } catch (_) {}
            window.location.href = "login.html";
        });
    } catch (err) {}
}

document.addEventListener("DOMContentLoaded", () => {
    highlightNav();
    injectNavUser();
});