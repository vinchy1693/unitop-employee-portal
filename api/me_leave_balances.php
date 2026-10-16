<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$year = (int) ($_GET["year"] ?? date("Y"));

$stmt = $pdo->prepare(
    "SELECT lt.leave_type_id, lt.name, lt.days_per_year,
            COALESCE(lb.remaining_days, lt.days_per_year) AS remaining_days
     FROM leave_types lt
     LEFT JOIN leave_balances lb
       ON lb.leave_type_id = lt.leave_type_id
      AND lb.employee_id = ?
      AND lb.year = ?
     WHERE lt.is_active = 1
     ORDER BY lt.name"
);
$stmt->execute([$empId, $year]);
respond(true, "", [
    "year"     => $year,
    "balances" => $stmt->fetchAll(),
]);