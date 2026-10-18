<?php
// Pin the application timezone. Without this PHP falls back to the php.ini
// default (here Europe/Berlin) while MySQL runs on the machine's local time
// (Asia/Manila), so work_date and the scan times drifted by six hours - a scan
// at 14:00 local was recorded as 08:00, which silently made every late
// calculation wrong and could stamp attendance on the wrong day.
date_default_timezone_set("Asia/Manila");
// =====================================================================
// employee_portal/api/_bootstrap.php
// =====================================================================

if (session_status() === PHP_SESSION_NONE) {
    // Own session cookie name, kept distinct from the admin site's "admin_sid"
    // so a login on one application cannot be replayed against the other.
    session_name("portal_sid");
    session_start();
}

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

try {
    require __DIR__ . "/../config.php";
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database connection failed: " . $e->getMessage(),
        "data"    => null,
    ]);
    exit;
}

function json_input(): array {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST ?: [];
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
    requireEmployee();
}