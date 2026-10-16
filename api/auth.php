<?php
define("AUTH_ENDPOINT", true);
require __DIR__ . "/_bootstrap.php";

$method = $_SERVER["REQUEST_METHOD"];
$action = $_GET["action"] ?? "";

// ---- POST ?action=login ----
if ($method === "POST" && $action === "login") {
    $in       = json_input();
    $username = trim($in["username"] ?? "");
    $password = $in["password"] ?? "";

    if ($username === "" || $password === "") {
        respond(false, "Username and password are required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.username, u.role, u.password_hash, u.is_active, u.employee_id,
                CONCAT(e.first_name, ' ', e.last_name) AS employee_name
         FROM users u
         LEFT JOIN employees e ON e.employee_id = u.employee_id
         WHERE u.username = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        respond(false, "Invalid username or password.", null, 401);
    }
    if ((int) $user["is_active"] !== 1) {
        respond(false, "This account has been deactivated.", null, 403);
    }
    if ($user["role"] !== "Employee" || !$user["employee_id"]) {
        respond(false, "This portal is for employees only. Please use the admin login.", null, 403);
    }
    if (!$user["password_hash"] || !password_verify($password, $user["password_hash"])) {
        respond(false, "Invalid username or password.", null, 401);
    }

    session_regenerate_id(true);

    $_SESSION["user_id"]     = (int) $user["user_id"];
    $_SESSION["username"]    = $user["username"];
    $_SESSION["role"]        = $user["role"];
    $_SESSION["employee_id"] = (int) $user["employee_id"];

    respond(true, "Welcome, " . $user["employee_name"] . ".", [
        "user_id"       => (int) $user["user_id"],
        "username"      => $user["username"],
        "role"          => $user["role"],
        "employee_id"   => (int) $user["employee_id"],
        "employee_name" => $user["employee_name"],
    ]);
}

// ---- POST ?action=logout ----
if ($method === "POST" && $action === "logout") {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), "", time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]);
    }
    session_destroy();
    respond(true, "Logged out.");
}

// ---- GET ?action=me ----
if ($method === "GET" && $action === "me") {
    $user = currentUser();
    if (!$user || $user["role"] !== "Employee" || !$user["employee_id"]) {
        respond(false, "Not authenticated.", null, 401);
    }

    $stmt = $pdo->prepare(
        "SELECT e.employee_number, e.first_name, e.last_name,
                d.department_name, p.title AS position_title
         FROM employees e
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         WHERE e.employee_id = ?"
    );
    $stmt->execute([$user["employee_id"]]);
    $emp = $stmt->fetch() ?: [];

    respond(true, "", [
        "user_id"         => $user["user_id"],
        "username"        => $user["username"],
        "employee_id"     => $user["employee_id"],
        "employee_number" => $emp["employee_number"] ?? "",
        "employee_name"   => trim(($emp["first_name"] ?? "") . " " . ($emp["last_name"] ?? "")),
        "department_name" => $emp["department_name"] ?? "",
        "position_title"  => $emp["position_title"] ?? "",
    ]);
}

respond(false, "Method or action not allowed.", null, 405);