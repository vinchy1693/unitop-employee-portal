<?php
require __DIR__ . "/_bootstrap.php";

// Self-service profile. The employee is always the session's employee; any
// employee_id in the request body is ignored on purpose.
$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

// =====================================================================
// The ONLY columns an employee may change, mapped to their DB width.
// The PATCH handler walks this list - never the request body's keys - so
// basic_salary, the government numbers, position_id, department_id,
// employee_number, is_active, date_hired and employee_id are unreachable.
// =====================================================================
const PROFILE_CONTACT_FIELDS = [
    "mobile_number"            => 30,
    "email"                    => 150,
    "address"                  => 255,
    "emergency_contact_name"   => 120,
    "emergency_contact_number" => 30,
];

function profile_strlen(string $value): int {
    return function_exists("mb_strlen") ? mb_strlen($value, "UTF-8") : strlen($value);
}

function profile_label(string $column): string {
    $labels = [
        "mobile_number"            => "Mobile number",
        "email"                    => "Email",
        "address"                  => "Address",
        "emergency_contact_name"   => "Emergency contact name",
        "emergency_contact_number" => "Emergency contact number",
    ];
    return $labels[$column] ?? $column;
}

/** Load the caller's own row (joins are LEFT: position/department may be unset). */
function profile_row(int $empId): ?array {
    global $pdo;
    $stmt = $pdo->prepare(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
                e.date_hired, e.employment_status,
                e.mobile_number, e.email, e.address,
                e.emergency_contact_name, e.emergency_contact_number,
                e.tin_number, e.sss_number, e.philhealth_number, e.pagibig_number,
                e.position_id, e.department_id,
                p.title AS position_title,
                d.department_name
         FROM employees e
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         WHERE e.employee_id = ?"
    );
    $stmt->execute([$empId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Shape the row exactly as the portal consumes it. */
function profile_payload(array $row): array {
    $first = (string) ($row["first_name"] ?? "");
    $last  = (string) ($row["last_name"] ?? "");

    return [
        "employee_id"              => (int) $row["employee_id"],
        "employee_number"          => $row["employee_number"],
        "first_name"               => $row["first_name"],
        "last_name"                => $row["last_name"],
        "employee_name"            => trim($last . ", " . $first, ", "),
        "position_id"              => $row["position_id"] !== null ? (int) $row["position_id"] : null,
        "position_title"           => $row["position_title"],
        "department_id"            => $row["department_id"] !== null ? (int) $row["department_id"] : null,
        "department_name"          => $row["department_name"],
        "date_hired"               => $row["date_hired"],
        "employment_status"        => $row["employment_status"],
        "mobile_number"            => $row["mobile_number"],
        "email"                    => $row["email"],
        "address"                  => $row["address"],
        "emergency_contact_name"   => $row["emergency_contact_name"],
        "emergency_contact_number" => $row["emergency_contact_number"],
        // Read-only for reference: HR maintains these.
        "tin_number"               => $row["tin_number"],
        "sss_number"               => $row["sss_number"],
        "philhealth_number"        => $row["philhealth_number"],
        "pagibig_number"           => $row["pagibig_number"],
    ];
}

// ---- GET: own record ----
if ($method === "GET") {
    $row = profile_row($empId);
    if (!$row) respond(false, "Employee record not found.", null, 404);
    respond(true, "", profile_payload($row));
}

// ---- PATCH / PUT: update only the five contact fields ----
if ($method === "PATCH" || $method === "PUT") {
    $in = json_input();
    if (!is_array($in)) $in = [];

    $row = profile_row($empId);
    if (!$row) respond(false, "Employee record not found.", null, 404);

    $updates = [];   // column => new value
    $changes = [];   // column => ["old" => ..., "new" => ...]
    $touched = false;

    foreach (PROFILE_CONTACT_FIELDS as $column => $maxLength) {
        if (!array_key_exists($column, $in)) continue;
        $touched = true;

        $raw = $in[$column];
        if ($raw === null) {
            $new = null;
        } elseif (!is_scalar($raw)) {
            respond(false, profile_label($column) . " must be a text value.", null, 422);
        } else {
            $new = trim((string) $raw);
            if ($new === "") {
                $new = null;                 // blank -> NULL, same as the admin form
            } else {
                if (profile_strlen($new) > $maxLength) {
                    respond(false, profile_label($column)
                        . " must be at most {$maxLength} characters.", null, 422);
                }
                if ($column === "email" && !filter_var($new, FILTER_VALIDATE_EMAIL)) {
                    respond(false, "Please enter a valid email address.", null, 422);
                }
            }
        }

        $old = $row[$column];
        if ((string) ($old ?? "") !== (string) ($new ?? "")) {
            $updates[$column] = $new;
            $changes[$column] = [
                "old" => $old === null ? null : (string) $old,
                "new" => $new,
            ];
        }
    }

    if (!$touched) {
        respond(false,
            "Only your contact details can be updated here: "
            . implode(", ", array_keys(PROFILE_CONTACT_FIELDS)) . ".",
            null, 422);
    }

    // Nothing actually changed: report success without writing a no-op UPDATE.
    if (!$updates) {
        respond(true, "No changes to save.", profile_payload($row));
    }

    $assignments = implode(", ", array_map(fn($c) => "`$c` = ?", array_keys($updates)));
    $stmt = $pdo->prepare("UPDATE employees SET {$assignments} WHERE employee_id = ?");
    $stmt->execute([...array_values($updates), $empId]);

    $message = "Contact details updated.";

    // The portal has no central audit hook (the admin's respond() does), so
    // this endpoint writes its own row. Only the changed fields are stored,
    // never the whole employee row.
    try {
        $details = json_encode(
            $changes,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $audit = $pdo->prepare(
            "INSERT INTO `audit_log`
                (`user_id`, `username`, `role`, `action`, `method`, `http_status`,
                 `success`, `message`, `target`, `details`, `ip`, `created_at`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $audit->execute([
            (int) $user["user_id"],
            $user["username"] !== "" ? $user["username"] : null,
            $user["role"] !== "" ? $user["role"] : null,
            "me_profile.php",
            $method,
            200,
            1,
            $message,
            "employee_id=" . $empId,
            is_string($details) ? $details : null,
            isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : null,
        ]);
    } catch (Throwable $e) {
        // Never let the audit trail break a successful update.
        error_log("portal audit_log write failed: " . $e->getMessage());
    }

    $fresh = profile_row($empId);
    respond(true, $message, profile_payload($fresh ?? $row));
}

respond(false, "Method not allowed.", null, 405);
