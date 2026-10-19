<?php
// =====================================================================
// employee_portal/api/me_overtime_requests.php
//
// The employee's own overtime requests (item 6):
//   GET  -> my requests, newest first
//   POST -> file a request for MY OWN overtime
//
// The employee is always the session employee; an employee_id in the body is
// ignored. The day type and the multiplier are derived on the server from the
// date and the `holidays` table, never trusted from the client.
//
// Approval still lives on the admin side (api/overtime_records.php). This
// endpoint only files the request. The employee clocks the actual overtime in
// and out at the kiosk after it is approved.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

const OT_MAX_REASON = 500;
const OT_MIN_REASON = 10;
const OT_MAX_AHEAD_DAYS = 30;

function ot_strlen(string $value): int {
    return function_exists("mb_strlen") ? mb_strlen($value, "UTF-8") : strlen($value);
}

/** HH:MM or HH:MM:SS -> HH:MM:SS, or null when invalid. */
function ot_normalize_time($value): ?string {
    $value = trim((string) ($value ?? ""));
    if ($value === "") return null;
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $m)) {
        return null;
    }
    return sprintf("%s:%s:%s", $m[1], $m[2], $m[3] ?? "00");
}

/**
 * Day type and statutory overtime multiplier for a date.
 *
 * This is the portal's copy of the admin's dayTypeForDate() /
 * overtimeMultiplierForDayType() in api/_payroll_helpers.php. The portal cannot
 * include the admin helper because the two apps are separate deployments, so
 * the rule is repeated here. If the rates change, both must change.
 */
function ot_day_type(PDO $pdo, string $date): array {
    $stmt = $pdo->prepare(
        "SELECT holiday_type FROM holidays WHERE holiday_date = ?
         ORDER BY holiday_type = 'Regular' DESC LIMIT 1"
    );
    $stmt->execute([$date]);
    $holiday = $stmt->fetchColumn();

    if ($holiday === "Regular") {
        return ["type" => "Regular Holiday", "multiplier" => 2.60];
    }
    if ($holiday === "Special") {
        return ["type" => "Special Holiday", "multiplier" => 1.69];
    }
    if ((int) (new DateTime($date))->format("N") === 7) {
        return ["type" => "Rest Day", "multiplier" => 1.69];
    }
    return ["type" => "Ordinary Day", "multiplier" => 1.25];
}

// ---- GET: my requests ----
if ($method === "GET") {
    $stmt = $pdo->prepare(
        "SELECT ot_id, ot_date, ot_type, rate_multiplier, reason,
                requested_start, requested_end, day_type, status, hours,
                time_in, time_out, requested_at
         FROM overtime_records
         WHERE employee_id = ?
         ORDER BY ot_date DESC, ot_id DESC
         LIMIT 100"
    );
    $stmt->execute([$empId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row["ot_id"]          = (int) $row["ot_id"];
        $row["rate_multiplier"] = (float) $row["rate_multiplier"];
        $row["hours"]          = $row["hours"] !== null ? (float) $row["hours"] : null;

        // Backfill the display for rows created before migration 013.
        if (empty($row["day_type"])) {
            $row["day_type"] = ot_day_type($pdo, (string) $row["ot_date"])["type"];
        }
        $row["rate_multiplier"] = max(
            $row["rate_multiplier"],
            ot_day_type($pdo, (string) $row["ot_date"])["multiplier"]
        );
    }
    unset($row);

    respond(true, "", ["rows" => $rows, "total" => count($rows)]);
}

if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

// ---- POST: file my own overtime request ----
$in       = json_input();
$otDate   = trim((string) ($in["ot_date"] ?? ""));
$reason   = trim((string) ($in["reason"] ?? ""));
$reqStart = ot_normalize_time($in["requested_start"] ?? null);
$reqEnd   = ot_normalize_time($in["requested_end"] ?? null);

$today = date("Y-m-d");
$maxAhead = date("Y-m-d", strtotime("+" . OT_MAX_AHEAD_DAYS . " days"));

$dateCheck = DateTime::createFromFormat("!Y-m-d", $otDate);
if (!$dateCheck || $dateCheck->format("Y-m-d") !== $otDate) {
    respond(false, "A valid overtime date (YYYY-MM-DD) is required.", null, 422);
}
if ($otDate < $today) {
    respond(false, "Overtime must be requested for today or a future date.", null, 422);
}
if ($otDate > $maxAhead) {
    respond(false, "Overtime can only be requested up to " . OT_MAX_AHEAD_DAYS . " days ahead.", null, 422);
}
if (ot_strlen($reason) < OT_MIN_REASON) {
    respond(false, "Please describe the reason in at least " . OT_MIN_REASON . " characters.", null, 422);
}
if (ot_strlen($reason) > OT_MAX_REASON) {
    respond(false, "The reason must be at most " . OT_MAX_REASON . " characters.", null, 422);
}
if ($reqStart === null || $reqEnd === null) {
    respond(false, "A valid requested start and end time are required (HH:MM).", null, 422);
}
if (strcmp($reqEnd, $reqStart) <= 0) {
    respond(false, "The requested end must be after the requested start.", null, 422);
}
if ($otDate === $today && strtotime($reqEnd) !== false && strtotime($reqEnd) < time()) {
    respond(false, "That overtime window has already passed. Pick a later window, or ask HR to file it.", null, 422);
}

// One open request per employee per date, matching the admin rule.
$stmt = $pdo->prepare(
    "SELECT ot_id FROM overtime_records
     WHERE employee_id = ? AND ot_date = ? AND status IN ('Pending','Approved')
     LIMIT 1"
);
$stmt->execute([$empId, $otDate]);
if ($stmt->fetchColumn()) {
    respond(false,
        "You already have an open overtime request for $otDate. Wait for HR to decide it first.",
        null, 409);
}

$day = ot_day_type($pdo, $otDate);

$stmt = $pdo->prepare(
    "INSERT INTO overtime_records
     (employee_id, ot_date, hours, ot_type, rate_multiplier, reason,
      requested_start, requested_end, day_type, status, time_in, time_out,
      requested_at, approved_by, approved_at)
     VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 'Pending', NULL, NULL, NOW(), NULL, NULL)"
);
$stmt->execute([
    $empId, $otDate, $day["type"], $day["multiplier"],
    $reason, $reqStart, $reqEnd, $day["type"],
]);
$otId = (int) $pdo->lastInsertId();

$message = "Overtime request filed. HR will review it.";

// The portal has no central audit hook, so this endpoint writes its own row,
// like me_attendance_corrections.php does.
try {
    $details = json_encode([
        "employee_id"     => $empId,
        "ot_date"         => $otDate,
        "requested_start" => $reqStart,
        "requested_end"   => $reqEnd,
        "day_type"        => $day["type"],
        "rate_multiplier" => $day["multiplier"],
        "reason"          => $reason,
        "status"          => "Pending",
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

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
        "me_overtime_requests.php",
        $method,
        200,
        1,
        $message,
        "ot_id=" . $otId,
        is_string($details) ? $details : null,
        isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : null,
    ]);
} catch (Throwable $e) {
    error_log("portal audit_log write failed: " . $e->getMessage());
}

respond(true, $message, [
    "ot_id"           => $otId,
    "status"          => "Pending",
    "ot_date"         => $otDate,
    "requested_start" => $reqStart,
    "requested_end"   => $reqEnd,
    "day_type"        => $day["type"],
    "rate_multiplier" => $day["multiplier"],
]);
