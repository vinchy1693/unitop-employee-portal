// =====================================================================
// employee_portal/assets/js/api.js
// =====================================================================

const api = axios.create({
    baseURL: "api/",
    headers: { "Content-Type": "application/json" },
});

// CSRF: echo the readable portal_csrf cookie in X-CSRF-Token on every request.
function readCookie(name) {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const match = document.cookie.match(new RegExp("(?:^|; )" + escaped + "=([^;]*)"));
    return match ? decodeURIComponent(match[1]) : "";
}

api.interceptors.request.use((config) => {
    const token = readCookie("portal_csrf");
    if (token) {
        config.headers = config.headers || {};
        config.headers["X-CSRF-Token"] = token;
    }
    return config;
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
            <a href="api/auth.php?action=logout" class="nav-logout" id="nav-logout-btn"
               onclick="return confirm('Log out?');">Log out</a>
        `;
        nav.appendChild(wrap);
    } catch (err) {}
}

document.addEventListener("DOMContentLoaded", () => {
    highlightNav();
    injectNavUser();
});