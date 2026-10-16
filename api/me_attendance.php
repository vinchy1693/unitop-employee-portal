<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$from = trim($_GET["from"] ?? "");
$to   = trim($_GET["to"]   ?? "");

if ($from === "" || $to === "") {
    $to   = date("Y-m-d");
    $from = date("Y-m-d", strtotime("-29 days"));
}

$stmt = $pdo->prepare(
    "SELECT attendance_id, work_date, time_in, time_out,
            hours_worked, minutes_late, status
     FROM attendance
     WHERE employee_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date DESC"
);
$stmt->execute([$empId, $from, $to]);
$rows = $stmt->fetchAll();

$totalHours = 0;
$totalLate  = 0;
$present    = 0;
foreach ($rows as $r) {
    $present++;
    $totalHours += (float) ($r["hours_worked"] ?? 0);
    $totalLate  += (int)   ($r["minutes_late"] ?? 0);
}

respond(true, "", [
    "from"         => $from,
    "to"           => $to,
    "records"      => $rows,
    "days_present" => $present,
    "total_hours"  => round($totalHours, 2),
    "total_late"   => $totalLate,
]);