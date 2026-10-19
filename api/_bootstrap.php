<?php
// Pin the application timezone. Without this PHP falls back to the php.ini
// default (here Europe/Berlin) while MySQL runs on the machine's local time
// (Asia/Manila), so work_date and the scan times drifted by six hours - a scan
// at 14:00 local was recorded as 08:00, which silently made every late
// calculation wrong and could stamp attendance on the wrong day.
date_default_timezone_set("Asia/Manila");

// Never render PHP errors into a JSON response; log them instead.
ini_set("display_errors", "0");
ini_set("log_errors", "1");
// =====================================================================
// employee_portal/api/_bootstrap.php
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Own session cookie name, kept distinct from the admin site's "admin_sid"
    // so a login on one application cannot be replayed against the other.
    session_name("portal_sid");
    $cookieSecure = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off");
    session_set_cookie_params([
        "lifetime" => 0,
        "path"     => "/",
        "domain"   => "",
        "secure"   => $cookieSecure,
        "httponly" => true,
        "samesite" => "Lax",
    ]);
    session_start();
}

// =====================================================================
// CSRF: the same double-submit pattern as the admin app. The token lives in
// a readable cookie so the page script can echo it in X-CSRF-Token; a
// cross-site form can send the cookie but cannot read it or set the header.
// =====================================================================
const PORTAL_CSRF_COOKIE = "portal_csrf";

function portal_csrf_is_https(): bool {
    return !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off";
}

function portal_csrf_token(): string {
    if (empty($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION["csrf_token"];
}

function portal_csrf_cookie_options(int $expires = 0): array {
    return [
        "expires"  => $expires,
        "path"     => "/",
        "secure"   => portal_csrf_is_https(),
        "httponly" => false,   // the page script must read it
        "samesite" => "Lax",
    ];
}

function portal_csrf_publish(): void {
    if (!headers_sent()) {
        setcookie(PORTAL_CSRF_COOKIE, portal_csrf_token(), portal_csrf_cookie_options());
    }
}

function portal_csrf_rotate(): string {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    portal_csrf_publish();
    return (string) $_SESSION["csrf_token"];
}

function portal_csrf_clear(): void {
    if (!headers_sent()) {
        setcookie(PORTAL_CSRF_COOKIE, "", portal_csrf_cookie_options(time() - 3600));
    }
    unset($_SESSION["csrf_token"]);
}

portal_csrf_publish();

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . "/../config.php";
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed. Please contact the administrator.",
        "data"    => null,
    ]);
    exit;
}

function json_input(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    // JSON only: the old $_POST fallback let a cross-site form through.
    return is_array($data) ? $data : [];
}

function respond(bool $success, string $message = "", $data = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode([
        "success" => $success,
        "message" => $message,
        "data"    => $data,
    ]);
    exit;
}

// =====================================================================
// CSRF guard
// =====================================================================

/** The login form has no session token yet; JSON-only + SameSite cover it. */
function portal_csrf_request_is_exempt(): bool {
    $script = basename((string) parse_url((string) ($_SERVER["SCRIPT_NAME"] ?? ""), PHP_URL_PATH));
    $action = strtolower(trim((string) ($_GET["action"] ?? "")));
    return $script === "auth.php" && $action === "login";
}

function assertPortalCsrf(): void {
    $method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
    if (in_array($method, ["GET", "HEAD", "OPTIONS"], true)) return;
    if (portal_csrf_request_is_exempt()) return;

    $expected = (string) ($_SESSION["csrf_token"] ?? "");
    $sent     = (string) ($_SERVER["HTTP_X_CSRF_TOKEN"] ?? "");
    if ($expected === "" || $sent === "" || !hash_equals($expected, $sent)) {
        respond(
            false,
            "Your session token is missing or has expired. Refresh the page and try again.",
            ["code" => "csrf_failed"],
            403
        );
    }

    if ($method !== "DELETE") {
        $ctype = strtolower((string) ($_SERVER["CONTENT_TYPE"] ?? ""));
        if (!str_contains($ctype, "application/json")) {
            respond(false, "This endpoint accepts JSON requests only.", null, 415);
        }
    }
}

function currentUser(): ?array {
    if (empty($_SESSION["user_id"])) return null;
    return [
        "user_id"     => (int) $_SESSION["user_id"],
        "username"    => $_SESSION["username"] ?? "",
        "role"        => $_SESSION["role"] ?? "",
        "employee_id" => isset($_SESSION["employee_id"]) && $_SESSION["employee_id"] !== null
            ? (int) $_SESSION["employee_id"]
            : null,
    ];
}

function requireEmployee(): array {
    $user = currentUser();
    if (!$user) {
        respond(false, "Not authenticated. Please log in.", null, 401);
    }
    if ($user["role"] !== "Employee" || !$user["employee_id"]) {
        respond(false, "This portal is for employees only.", null, 403);
    }
    return $user;
}

if (!defined("AUTH_ENDPOINT")) {
    // Authenticate first so an anonymous POST still gets 401, not a CSRF 403.
    requireEmployee();
}
assertPortalCsrf();

set_exception_handler(function (Throwable $e): void {
    error_log(
        "Unhandled " . get_class($e) . ": " . $e->getMessage()
        . " in " . $e->getFile() . ":" . $e->getLine()
    );
    if (!headers_sent()) {
        http_response_code(500);
        header("Content-Type: application/json; charset=utf-8");
    }
    echo json_encode([
        "success" => false,
        "message" => "Internal server error. Please try again or contact the administrator.",
        "data"    => null,
    ]);
});