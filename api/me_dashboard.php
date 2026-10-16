<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];
if ($method !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

// ---- Latest payslip ----
$stmt = $pdo->prepare(
    "SELECT ps.payslip_id, ps.net_pay, ps.gross_pay, pp.start_date, pp.end_date, pp.period_type
     FROM payslips ps
     JOIN payroll_runs pr ON pr.run_id = ps.run_id
     JOIN payroll_periods pp ON pp.period_id = pr.period_id
     WHERE ps.employee_id = ?
     ORDER BY pp.end_date DESC, ps.payslip_id DESC LIMIT 1"
);
$stmt->execute([$empId]);
$latestPayslip = $stmt->fetch() ?: null;

// ---- This month's attendance ----
$monthStart = date("Y-m-01");
$monthEnd   = date("Y-m-t");

$stmt = $pdo->prepare(
    "SELECT COUNT(*) AS days_present,
            COALESCE(SUM(minutes_late), 0) AS late_minutes,
            COALESCE(SUM(hours_worked), 0) AS hours_worked
     FROM attendance
     WHERE employee_id = ? AND work_date BETWEEN ? AND ?"
);
$stmt->execute([$empId, $monthStart, $monthEnd]);
$attStats = $stmt->fetch();

// ---- Total leave balance ----
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(COALESCE(lb.remaining_days, lt.days_per_year)), 0) AS total
     FROM leave_types lt
     LEFT JOIN leave_balances lb
       ON lb.leave_type_id = lt.leave_type_id
      AND lb.employee_id = ?
      AND lb.year = ?
     WHERE lt.is_active = 1"
);
$stmt->execute([$empId, date("Y")]);
$leaveTotal = (float) $stmt->fetchColumn();

// ---- Pending leave requests ----
$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM leave_requests
     WHERE employee_id = ? AND status = 'Pending'"
);
$stmt->execute([$empId]);
$pendingLeaves = (int) $stmt->fetchColumn();

respond(true, "", [
    "latest_payslip" => $latestPayslip,
    "attendance"     => [
        "days_present" => (int)   ($attStats["days_present"] ?? 0),
        "late_minutes" => (int)   ($attStats["late_minutes"] ?? 0),
        "hours_worked" => round((float) ($attStats["hours_worked"] ?? 0), 2),
    ],
    "leave_total"    => round($leaveTotal, 2),
    "pending_leaves" => $pendingLeaves,
]);