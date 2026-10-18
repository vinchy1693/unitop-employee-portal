<?php
// =====================================================================
// GET me_leave_types.php
//
// The leave types an employee may request. The portal previously asked the
// ADMIN endpoint leave_types.php for this, but that requires PAYROLL_ROLES, so
// an Employee received 403 and the form fell back to a hardcoded list whose
// leave_type_id values do not exist in the database - filing then failed (or a
// type the company does not offer appeared in the dropdown).
//
// Read-only, active types only. No setup data is exposed beyond what the
// employee needs to pick a type.
// =====================================================================
require __DIR__ . "/_bootstrap.php";

requireEmployee();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    respond(false, "Method not allowed.", null, 405);
}

$rows = $pdo->query(
    "SELECT leave_type_id, name, days_per_year, is_paid
     FROM leave_types
     WHERE is_active = 1
     ORDER BY name"
)->fetchAll();

$out = [];
foreach ($rows as $r) {
    $out[] = [
        "leave_type_id" => (int) $r["leave_type_id"],
        "name"          => $r["name"],
        "days_per_year" => (float) $r["days_per_year"],
        "is_paid"       => (int) $r["is_paid"],
    ];
}

respond(true, "", $out);
