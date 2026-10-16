<?php
// =====================================================================
// employee_portal/api/me_scan_attendance.php
//
// Manual attendance fallback for the employee portal. The employee types or
// pastes their own QR code value instead of showing it to the office scanner,
// for when the camera is broken or the kiosk is unavailable.
//
// The submitted code must belong to the signed-in employee, so nobody can
// clock a colleague in or out by pasting their code.
//
// POST { "code_value": "UNITOP-..." }
// =====================================================================
require __DIR__ . "/_bootstrap.php";

$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "POST") {
    respond(false, "Method not allowed.", null, 405);
}

$in        = json_input();
$codeValue = trim($in["code_value"] ?? "");

if ($codeValue === "") {
    respond(false, "QR code value is required.", null, 422);
}

// The code must be this employee's own active QR code.
$stmt = $pdo->prepare(
    "SELECT qr_code_id, status FROM qr_codes
     WHERE code_value = ? AND employee_id = ?
     LIMIT 1"
);
$stmt->execute([$codeValue, $empId]);
$qr = $stmt->fetch();

if (!$qr) {
    respond(false, "That code does not match your own QR code.", null, 404);
}
if ($qr["status"] !== "Active") {
    respond(false, "Your QR code has been revoked. Please ask HR for a new one.", null, 403);
}

$today     = date("Y-m-d");
$nowTime   = date("H:i:s");
$workStart = new DateTime("08:00:00");

$stmt = $pdo->prepare(
    "SELECT attendance_id, time_in, time_out
     FROM attendance
     WHERE employee_id = ? AND work_date = ?"
);
$stmt->execute([$empId, $today]);
$existing = $stmt->fetch();

// ---- CLOCK IN ----
if (!$existing) {
    $actualTime  = new DateTime($nowTime);
    $minutesLate = 0;
    if ($actualTime > $workStart) {
        $diff        = $workStart->diff($actualTime);
        $minutesLate = ($diff->h * 60) + $diff->i;
    }
    $status = $minutesLate > 0 ? "Late" : "Clocked In";

    $pdo->prepare(
        "INSERT INTO attendance
         (employee_id, qr_code_id, work_date, time_in, time_out,
          scan_device_id, hours_worked, minutes_late, status)
         VALUES (?, ?, ?, ?, NULL, 'Portal (manual)', NULL, ?, ?)"
    )->execute([$empId, $qr["qr_code_id"], $today, $nowTime, $minutesLate, $status]);

    respond(true, "Clocked in at " . date("h:i A", strtotime($nowTime)) . ".", [
        "action"       => "in",
        "time"         => date("h:i A", strtotime($nowTime)),
        "late_minutes" => $minutesLate,
        "status"       => $status,
    ]);
}

// ---- CLOCK OUT ----
if ($existing["time_out"] === null) {
    $diff         = (new DateTime($existing["time_in"]))->diff(new DateTime($nowTime));
    $totalMinutes = ($diff->h * 60) + $diff->i;

    if ($totalMinutes < 1) {
        respond(false, "You clocked in less than a minute ago. Please try again shortly.", null, 422);
    }

    // Subtract the 60-minute lunch break once more than 5 hours are worked.
    $netMinutes  = $totalMinutes > 300 ? $totalMinutes - 60 : $totalMinutes;
    $hoursWorked = round($netMinutes / 60, 2);

    $lateStmt = $pdo->prepare("SELECT minutes_late FROM attendance WHERE attendance_id = ?");
    $lateStmt->execute([$existing["attendance_id"]]);
    $minutesLate = (int) $lateStmt->fetchColumn();
    $status      = $minutesLate > 0 ? "Late" : "Present";

    $pdo->prepare(
        "UPDATE attendance SET time_out = ?, hours_worked = ?, status = ?
         WHERE attendance_id = ?"
    )->execute([$nowTime, $hoursWorked, $status, $existing["attendance_id"]]);

    respond(true, "Clocked out at " . date("h:i A", strtotime($nowTime)) . " — $hoursWorked hrs worked.", [
        "action"       => "out",
        "time"         => date("h:i A", strtotime($nowTime)),
        "hours_worked" => $hoursWorked,
        "status"       => $status,
    ]);
}

respond(false, "You have already clocked in and out today.", ["action" => "duplicate"], 409);
