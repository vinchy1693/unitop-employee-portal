<?php
// =====================================================================
// employee_portal/api/me_notifications.php
//
// Live employee notifications: released payslip, pending leave, pending
// overtime, pending attendance corrections and active loans.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

$user  = requireEmployee();
$empId = (int) $user["employee_id"];

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$items = [];

function meCount(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$payslipId = $pdo->prepare(
    "SELECT ps.payslip_id, pp.end_date
     FROM payslips ps
     JOIN payroll_runs pr ON pr.run_id = ps.run_id
     JOIN payroll_periods pp ON pp.period_id = pr.period_id
     WHERE ps.employee_id = ? AND pr.status = 'Released'
     ORDER BY pp.end_date DESC, ps.payslip_id DESC LIMIT 1"
);
$payslipId->execute([$empId]);
$latest = $payslipId->fetch();
if ($latest) {
    $items[] = [
        "title"  => "Payslip available",
        "detail" => "Your payslip for the period ending {$latest['end_date']} has been released.",
        "count"  => 1,
        "url"    => "payslips.html",
    ];
}

$n = meCount($pdo, "SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status='Pending'", [$empId]);
if ($n > 0) $items[] = ["title" => "Leave request pending", "detail" => "$n leave request(s) awaiting approval", "count" => $n, "url" => "leave.html"];

$n = meCount($pdo, "SELECT COUNT(*) FROM overtime_records WHERE employee_id=? AND status='Pending'", [$empId]);
if ($n > 0) $items[] = ["title" => "Overtime request pending", "detail" => "$n overtime request(s) awaiting approval", "count" => $n, "url" => "attendance.html"];

$n = meCount($pdo, "SELECT COUNT(*) FROM attendance_corrections WHERE employee_id=? AND status='Pending'", [$empId]);
if ($n > 0) $items[] = ["title" => "Attendance correction pending", "detail" => "$n correction request(s) awaiting review", "count" => $n, "url" => "attendance.html"];

$n = meCount($pdo, "SELECT COUNT(*) FROM loans WHERE employee_id=? AND status='Active'", [$empId]);
if ($n > 0) $items[] = ["title" => "Active loan", "detail" => "$n active loan(s) being deducted from payroll", "count" => $n, "url" => "loans.html"];

respond(true, "", [
    "count" => array_sum(array_column($items, "count")),
    "items" => $items,
]);
