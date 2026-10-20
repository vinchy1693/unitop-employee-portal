<?php
// =====================================================================
// employee_portal/api/me_overtime_requests.php
//
// The employee's own overtime history (read-only):
//   GET  -> my requests, newest first
//   POST -> 403: overtime is filed by the employee's Supervisor
//
// The employee is always the session employee. Only a Supervisor can create
// an overtime request, from the admin Overtime page; the Approver decides it.
// After approval, the employee clocks the actual overtime in and out at the
// kiosk.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "POST") {
    respond(
        false,
        "Overtime requests are filed by your Supervisor, not from the employee portal.",
        null,
        403
    );
}
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

/**
 * Day type for a date. Kept for rows created before migration 013, which may
 * not have day_type stored yet.
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

$stmt = $pdo->prepare(
    "SELECT ot.ot_id, ot.ot_date, ot.ot_type, ot.rate_multiplier, ot.reason,
            ot.requested_start, ot.requested_end, ot.day_type, ot.status,
            ot.hours, ot.time_in, ot.time_out, ot.requested_at,
            rb.username AS requested_by_name
     FROM overtime_records ot
     LEFT JOIN users rb ON rb.user_id = ot.requested_by
     WHERE ot.employee_id = ?
     ORDER BY ot.ot_date DESC, ot.ot_id DESC
     LIMIT 100"
);
$stmt->execute([$empId]);

$rows = $stmt->fetchAll();
foreach ($rows as &$row) {
    $row["ot_id"]           = (int) $row["ot_id"];
    $row["rate_multiplier"] = (float) $row["rate_multiplier"];
    $row["hours"]           = $row["hours"] !== null ? (float) $row["hours"] : null;

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
