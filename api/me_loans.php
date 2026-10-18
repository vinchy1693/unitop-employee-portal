<?php
require __DIR__ . "/_bootstrap.php";

// The logged-in employee is resolved from the session only. A client-supplied
// employee_id is never read, so one employee can never ask for another's loans.
$user  = requireEmployee();
$empId = (int) $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

// ---- GET: list own loans with their payment history ----
if ($method === "GET") {
    // The live `loans` table has no start/end date columns; those keys are
    // returned as null rather than inventing data. Amount paid is summed from
    // `loan_payments`, and the outstanding balance is principal minus that sum.
    $stmt = $pdo->prepare(
        "SELECT l.loan_id, l.loan_type, l.principal_amount, l.monthly_amortization,
                l.status,
                COALESCE(p.paid, 0) AS amount_paid
         FROM loans l
         LEFT JOIN (
             SELECT loan_id, SUM(amount_paid) AS paid
             FROM loan_payments
             GROUP BY loan_id
         ) p ON p.loan_id = l.loan_id
         WHERE l.employee_id = ?
         ORDER BY l.loan_id DESC"
    );
    $stmt->execute([$empId]);
    $loans = $stmt->fetchAll();

    if (!$loans) respond(true, "", []);

    // One extra query for all payments, then group them per loan in PHP.
    $ids = array_map("intval", array_column($loans, "loan_id"));
    $in  = implode(",", array_fill(0, count($ids), "?"));
    $pay = $pdo->prepare(
        "SELECT payment_id, loan_id, payslip_id, amount_paid, payment_date
         FROM loan_payments
         WHERE loan_id IN ($in)
         ORDER BY payment_date, payment_id"
    );
    $pay->execute($ids);

    $byLoan = [];
    foreach ($pay->fetchAll() as $p) {
        $byLoan[(int) $p["loan_id"]][] = [
            "payment_id"   => (int) $p["payment_id"],
            "payslip_id"   => (int) $p["payslip_id"],
            "amount_paid"  => (float) $p["amount_paid"],
            "payment_date" => $p["payment_date"],
        ];
    }

    $out = [];
    foreach ($loans as $l) {
        $principal = (float) $l["principal_amount"];
        $paid      = (float) $l["amount_paid"];

        $out[] = [
            "loan_id"              => (int) $l["loan_id"],
            "loan_type"            => $l["loan_type"],
            "principal_amount"     => $principal,
            "amount_paid"          => round($paid, 2),
            "outstanding_balance"  => round($principal - $paid, 2),
            "monthly_amortization" => (float) $l["monthly_amortization"],
            "start_date"           => null,   // not present in the loans table
            "end_date"             => null,   // not present in the loans table
            "status"               => $l["status"],
            "payments"             => $byLoan[(int) $l["loan_id"]] ?? [],
        ];
    }

    respond(true, "", $out);
}

respond(false, "Method not allowed.", null, 405);
