<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list own payslips (only when no specific payslip was requested) ----
if ($method === "GET" && !isset($_GET["id"])) {
    $stmt = $pdo->prepare(
        "SELECT ps.payslip_id, ps.basic_pay, ps.overtime_pay, ps.gross_pay,
                ps.total_deductions, ps.net_pay,
                pp.start_date, pp.end_date, pp.period_type,
                pr.run_id, pr.run_date
         FROM payslips ps
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         WHERE ps.employee_id = ? AND pr.status = 'Released'
         ORDER BY pp.end_date DESC, ps.payslip_id DESC"
    );
    $stmt->execute([$empId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- GET ?id=N : single payslip detail ----
if ($method === "GET" && isset($_GET["id"])) {
    $id = (int) $_GET["id"];
    $stmt = $pdo->prepare(
        "SELECT ps.*, pp.start_date, pp.end_date, pp.period_type,
                e.employee_number,
                CONCAT(e.last_name, ', ', e.first_name) AS employee_name,
                d.department_name, p.title AS position_title,
                gc.sss_ee, gc.sss_er, gc.philhealth_ee, gc.philhealth_er,
                gc.pagibig_ee, gc.pagibig_er, gc.withholding_tax
         FROM payslips ps
         JOIN employees e ON e.employee_id = ps.employee_id
         LEFT JOIN departments d ON d.department_id = e.department_id
         LEFT JOIN positions   p ON p.position_id   = e.position_id
         JOIN payroll_runs pr ON pr.run_id = ps.run_id
         JOIN payroll_periods pp ON pp.period_id = pr.period_id
         LEFT JOIN govt_contributions gc ON gc.payslip_id = ps.payslip_id
         WHERE ps.payslip_id = ? AND ps.employee_id = ? AND pr.status = 'Released'"
    );
    $stmt->execute([$id, $empId]);
    $row = $stmt->fetch();
    if (!$row) respond(false, "Payslip not found.", null, 404);

    // Line items
    $a = $pdo->prepare("SELECT allowance_type, amount FROM allowances WHERE payslip_id = ?");
    $a->execute([$id]);
    $row["allowances"] = $a->fetchAll();

    $d = $pdo->prepare("SELECT deduction_type, amount FROM deductions WHERE payslip_id = ?");
    $d->execute([$id]);
    $row["deductions"] = $d->fetchAll();

    $attendanceDed = 0.0;
    $otherDed = 0.0;
    foreach ($row["deductions"] as &$item) {
        $type = (string) ($item["deduction_type"] ?? "");
        $isAttendance = (bool) preg_match('/^(Absences|Late\/Undertime|Undertime)/', $type);
        if ($isAttendance) $attendanceDed += (float) $item["amount"];
        else $otherDed += (float) $item["amount"];
        $item["is_attendance"] = $isAttendance;
    }
    unset($item);
    $row["attendance_deductions"] = round($attendanceDed, 2);
    $row["other_deductions"] = round($otherDed, 2);

    $holidayStmt = $pdo->prepare(
        "SELECT a.work_date, h.name, h.holiday_type, a.hours_worked
         FROM attendance a
         JOIN holidays h ON h.holiday_date = a.work_date
         WHERE a.employee_id = ?
           AND a.work_date BETWEEN ? AND ?
           AND a.time_in IS NOT NULL
           AND a.time_out IS NOT NULL
           AND a.status IN ('Present', 'Late')
         ORDER BY a.work_date"
    );
    $holidayStmt->execute([(int) $row["employee_id"], $row["start_date"], $row["end_date"]]);
    $row["holiday_premiums"] = array_map(function (array $h): array {
        return [
            "date"         => $h["work_date"],
            "name"         => $h["name"],
            "holiday_type" => $h["holiday_type"],
            "percentage"   => $h["holiday_type"] === "Regular" ? 200 : 130,
            "hours_worked" => (float) $h["hours_worked"],
        ];
    }, $holidayStmt->fetchAll());

    respond(true, "", $row);
}

respond(false, "Method not allowed.", null, 405);