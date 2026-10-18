<?php
// =====================================================================
// employee_portal/api/me_attendance_corrections.php
//
// An employee's own attendance correction requests (segregation of duties,
// step 5).
//
//   GET  -> my requests, plus the attendance row's CURRENT times so I can see
//           what I am changing
//   POST -> file a request for MY OWN attendance
//
// The employee is always the session's employee: any employee_id in the body
// is ignored on purpose, exactly like me_profile.php and me_attendance.php.
//
// Validation:
//   work_date       today or earlier, and not older than 90 days
//   reason          required, at least 10 characters, at most 500
//   correction_type clock_in | clock_out | both
//   times           required according to the type, time out after time in
//   one Pending request per employee + work_date      -> 409
//   a date inside an Approved/Released payroll run    -> 409
//
// The approval itself lives on the admin side (api/attendance_corrections.php);
// this endpoint only files the request.
// =====================================================================

require __DIR__ . "/_bootstrap.php";

// Self-service: the employee is the session's employee, never the body's.
$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

const CORRECTION_TYPES   = ["clock_in", "clock_out", "both"];
const CORRECTION_MAX_AGE = 90;    // days back a correction may reach
const CORRECTION_MIN_REASON = 10; // characters

function correction_strlen(string $value): int {
    return function_exists("mb_strlen") ? mb_strlen($value, "UTF-8") : strlen($value);
}

/** HH:MM or HH:MM:SS -> HH:MM:SS, or null when it is not a valid time. */
function normalize_time(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === "") return null;
    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $m)) {
        return null;
    }
    return sprintf("%s:%s:%s", $m[1], $m[2], $m[3] ?? "00");
}

function valid_date(string $value): bool {
    $dt = DateTime::createFromFormat("!Y-m-d", $value);
    return $dt !== false && $dt->format("Y-m-d") === $value;
}

/** The newest attendance row for one employee + date, or null. */
function attendance_for(PDO $pdo, int $employeeId, string $workDate): ?array {
    $stmt = $pdo->prepare(
        "SELECT attendance_id, time_in, time_out, hours_worked, minutes_late, status
         FROM attendance
         WHERE employee_id = ? AND work_date = ?
         ORDER BY attendance_id DESC
         LIMIT 1"
    );
    $stmt->execute([$employeeId, $workDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The payroll run that locks this date, or null.
 *
 * This is the portal's copy of the admin rule in
 * api/_payroll_helpers.php: attendanceDateLock() - a date is locked when a run
 * whose period covers it is Approved or Released. The two apps share one
 * database and the portal cannot include the admin's helper file, so the query
 * is repeated here. If the rule ever changes, both must change.
 */
function attendance_date_lock(PDO $pdo, string $workDate): ?array {
    $stmt = $pdo->prepare(
        "SELECT pr.run_id, pr.status, pp.start_date, pp.end_date
         FROM payroll_runs pr
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE ? BETWEEN pp.start_date AND pp.end_date
           AND pr.status IN ('Approved', 'Released')
         ORDER BY pr.run_id DESC
         LIMIT 1"
    );
    $stmt->execute([$workDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// ---- GET: my requests (+ the current attendance times) ----
if ($method === "GET") {
    $stmt = $pdo->prepare(
        "SELECT c.correction_id, c.employee_id, c.attendance_id, c.work_date, c.correction_type,
                c.requested_time_in, c.requested_time_out, c.reason, c.status,
                c.requested_at, c.reviewed_at, c.review_remarks,
                a.attendance_id AS current_attendance_id,
                a.time_in       AS current_time_in,
                a.time_out      AS current_time_out,
                a.hours_worked  AS current_hours_worked,
                a.minutes_late  AS current_minutes_late,
                a.status        AS current_status
         FROM attendance_corrections c
         LEFT JOIN attendance a
                ON a.attendance_id = COALESCE(c.attendance_id,
                     (SELECT a2.attendance_id
                      FROM attendance a2
                      WHERE a2.employee_id = c.employee_id
                        AND a2.work_date   = c.work_date
                      ORDER BY a2.attendance_id DESC
                      LIMIT 1))
         WHERE c.employee_id = ?
         ORDER BY c.work_date DESC, c.correction_id DESC"
    );
    $stmt->execute([$empId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row["correction_id"] = (int) $row["correction_id"];
        $row["employee_id"]   = (int) $row["employee_id"];
        $row["attendance_id"] = $row["attendance_id"] !== null ? (int) $row["attendance_id"] : null;
        $row["has_attendance"] = $row["current_attendance_id"] !== null;
        $row["current_attendance_id"] = $row["current_attendance_id"] !== null
            ? (int) $row["current_attendance_id"] : null;
        $row["current_hours_worked"] = $row["current_hours_worked"] !== null
            ? (float) $row["current_hours_worked"] : null;
        $row["current_minutes_late"] = $row["current_minutes_late"] !== null
            ? (int) $row["current_minutes_late"] : null;
    }
    unset($row);

    respond(true, "", [
        "rows"  => $rows,
        "total" => count($rows),
    ]);
}

if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

// ---- POST: file my own correction ----
$in       = json_input();
$workDate = trim((string) ($in["work_date"] ?? ""));
$type     = strtolower(trim((string) ($in["correction_type"] ?? "")));
$reason   = trim((string) ($in["reason"] ?? ""));
$reqIn    = normalize_time($in["requested_time_in"] ?? null);
$reqOut   = normalize_time($in["requested_time_out"] ?? null);

// ---- date -------------------------------------------------------------
if ($workDate === "" || !valid_date($workDate)) {
    respond(false, "A valid work date (YYYY-MM-DD) is required.", null, 422);
}
$today = date("Y-m-d");
if ($workDate > $today) {
    respond(false, "You cannot file a correction for a future date.", null, 422);
}
$earliest = date("Y-m-d", strtotime("-" . CORRECTION_MAX_AGE . " days"));
if ($workDate < $earliest) {
    respond(false,
        "Corrections can only be filed for the last " . CORRECTION_MAX_AGE
        . " days (on or after $earliest).", null, 422);
}

// ---- reason -----------------------------------------------------------
if ($reason === "") {
    respond(false, "A reason is required.", null, 422);
}
if (correction_strlen($reason) < CORRECTION_MIN_REASON) {
    respond(false, "Please describe the reason in at least " . CORRECTION_MIN_REASON . " characters.", null, 422);
}
if (correction_strlen($reason) > 500) {
    respond(false, "The reason must be at most 500 characters.", null, 422);
}

// ---- type -------------------------------------------------------------
if (!in_array($type, CORRECTION_TYPES, true)) {
    respond(false, "correction_type must be one of: " . implode(", ", CORRECTION_TYPES) . ".", null, 422);
}

// ---- requested times --------------------------------------------------
$requestedIn  = in_array($type, ["clock_in", "both"],  true) ? $reqIn  : null;
$requestedOut = in_array($type, ["clock_out", "both"], true) ? $reqOut : null;

if (in_array($type, ["clock_in", "both"], true) && $requestedIn === null) {
    respond(false, "A valid requested time in is required for this correction type.", null, 422);
}
if (in_array($type, ["clock_out", "both"], true) && $requestedOut === null) {
    respond(false, "A valid requested time out is required for this correction type.", null, 422);
}

// Resolve against the current row so an unapprovable request is refused here
// rather than at review time.
$existing    = attendance_for($pdo, $empId, $workDate);
$resolvedIn  = $requestedIn  !== null ? $requestedIn  : ($existing["time_in"]  ?? null);
$resolvedOut = $requestedOut !== null ? $requestedOut : ($existing["time_out"] ?? null);

if ($type === "clock_out" && $resolvedIn === null) {
    respond(false,
        "There is no clock-in recorded for that date yet, so a clock-out cannot be corrected. "
        . "Choose 'Both times' and give the full shift instead.", null, 422);
}
if ($resolvedIn !== null && $resolvedOut !== null && strcmp((string) $resolvedOut, (string) $resolvedIn) <= 0) {
    respond(false, "The corrected time out must be after the time in.", null, 422);
}

// ---- one Pending request per employee + date --------------------------
$stmt = $pdo->prepare(
    "SELECT correction_id FROM attendance_corrections
     WHERE employee_id = ? AND work_date = ? AND status = 'Pending'
     LIMIT 1"
);
$stmt->execute([$empId, $workDate]);
if ($stmt->fetchColumn()) {
    respond(false,
        "You already have a Pending correction request for $workDate. "
        . "Wait for HR to review it before filing another.", null, 409);
}

// ---- locked payroll run ----------------------------------------------
$lock = attendance_date_lock($pdo, $workDate);
if ($lock !== null) {
    respond(false,
        "$workDate is inside payroll run #{$lock['run_id']} "
        . "({$lock['start_date']} to {$lock['end_date']}), which is already {$lock['status']}. "
        . "Attendance for that date can no longer be corrected here. "
        . "Please ask HR for a formal payroll adjustment.",
        $lock, 409);
}

// ---- file it ----------------------------------------------------------
$attendanceId = $existing !== null ? (int) $existing["attendance_id"] : null;

$stmt = $pdo->prepare(
    "INSERT INTO attendance_corrections
     (employee_id, attendance_id, work_date, correction_type,
      requested_time_in, requested_time_out, reason, status, requested_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
);
$stmt->execute([
    $empId, $attendanceId, $workDate, $type,
    $requestedIn, $requestedOut, $reason,
]);
$correctionId = (int) $pdo->lastInsertId();

$message = "Correction request filed. HR will review it.";

// The portal has no central audit hook (only the admin's respond() has one),
// so this endpoint writes its own row, like me_profile.php does.
try {
    $details = json_encode([
        "employee_id"        => $empId,
        "attendance_id"      => $attendanceId,
        "work_date"          => $workDate,
        "correction_type"    => $type,
        "requested_time_in"  => $requestedIn,
        "requested_time_out" => $requestedOut,
        "reason"             => $reason,
        "status"             => "Pending",
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
        "me_attendance_corrections.php",
        $method,
        200,
        1,
        $message,
        "correction_id=" . $correctionId,
        is_string($details) ? $details : null,
        isset($_SERVER["REMOTE_ADDR"]) ? substr((string) $_SERVER["REMOTE_ADDR"], 0, 45) : null,
    ]);
} catch (Throwable $e) {
    // Never let the audit trail break a successful filing.
    error_log("portal audit_log write failed: " . $e->getMessage());
}

respond(true, $message, [
    "correction_id"     => $correctionId,
    "status"            => "Pending",
    "work_date"         => $workDate,
    "correction_type"   => $type,
    "attendance_id"     => $attendanceId,
    "has_attendance"    => $existing !== null,
]);
