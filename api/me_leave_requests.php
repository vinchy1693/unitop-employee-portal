<?php
require __DIR__ . "/_bootstrap.php";

$user = requireEmployee();
$empId = $user["employee_id"];

$method = $_SERVER["REQUEST_METHOD"];

function countBusinessDays(string $start, string $end): int {
    if ($start > $end) return 0;
    $cursor = new DateTime($start);
    $endEx  = (new DateTime($end))->modify('+1 day');
    $days = 0;
    while ($cursor < $endEx) {
        $dow = (int) $cursor->format('N');
        // Match the payroll work week (Mon-Sat), not Mon-Fri: otherwise a leave
        // that spans a Saturday charges one day fewer than payroll counts.
        if ($dow <= 6) $days++;
        $cursor->modify('+1 day');
    }
    return $days;
}

// ---- GET: list own leave requests ----
if ($method === "GET") {
    $stmt = $pdo->prepare(
        "SELECT lr.leave_id, lr.leave_type_id, lr.start_date, lr.end_date, lr.status,
                lt.name AS leave_type_name
         FROM leave_requests lr
         JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ?
         ORDER BY lr.leave_id DESC"
    );
    $stmt->execute([$empId]);
    respond(true, "", $stmt->fetchAll());
}

// ---- POST: file own leave request ----
if ($method === "POST") {
    $in     = json_input();
    $typeId = (int) ($in["leave_type_id"] ?? 0);
    $start  = trim($in["start_date"] ?? "");
    $end    = trim($in["end_date"]   ?? "");

    if ($typeId === 0 || $start === "" || $end === "") {
        respond(false, "Leave type, start date, and end date are required.", null, 422);
    }
    if ($start > $end) {
        respond(false, "End date must be on or after start date.", null, 422);
    }
    // A single-day request is always allowed, even when that day is not a
    // working day: Birthday Leave is one day, and a birthday that falls on a
    // Saturday or Sunday could otherwise never be filed. Multi-day ranges still
    // need at least one working day.
    if ($start !== $end && countBusinessDays($start, $end) === 0) {
        respond(false, "The selected range has no working days.", null, 422);
    }

    // Name the conflicting leave, so "you already have a leave" is not mistaken
    // for a leave of the type being filed.
    $stmt = $pdo->prepare(
        "SELECT lr.leave_id, lr.start_date, lr.end_date, lr.status,
                COALESCE(lt.name, 'Leave') AS type_name
         FROM leave_requests lr
         LEFT JOIN leave_types lt ON lt.leave_type_id = lr.leave_type_id
         WHERE lr.employee_id = ? AND lr.status IN ('Pending','Approved')
           AND NOT (lr.end_date < ? OR lr.start_date > ?)
         ORDER BY lr.start_date
         LIMIT 1"
    );
    $stmt->execute([$empId, $start, $end]);
    $conflict = $stmt->fetch();
    if ($conflict) {
        respond(false,
            "You already have an overlapping leave: {$conflict['type_name']} "
            . "({$conflict['status']}) from {$conflict['start_date']} to {$conflict['end_date']}. "
            . "Cancel it, or choose dates outside that range.",
            ["conflicts_with" => $conflict], 409);
    }

    $stmt = $pdo->prepare(
        "INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, status)
         VALUES (?, ?, ?, ?, 'Pending')"
    );
    $stmt->execute([$empId, $typeId, $start, $end]);

    respond(true, "Leave request filed. Waiting for HR approval.", ["leave_id" => (int) $pdo->lastInsertId()]);
}

// ---- PATCH: cancel own pending ----
if ($method === "PATCH") {
    $in     = json_input();
    $id     = (int)  ($in["leave_id"] ?? 0);
    $status = trim($in["status"] ?? "");

    if ($id === 0 || $status !== "Cancelled") {
        respond(false, "Valid leave_id and status 'Cancelled' required.", null, 422);
    }

    $stmt = $pdo->prepare(
        "SELECT status, employee_id FROM leave_requests WHERE leave_id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) respond(false, "Leave request not found.", null, 404);
    if ((int) $row["employee_id"] !== $empId) {
        respond(false, "You can only cancel your own leave requests.", null, 403);
    }
    if ($row["status"] !== "Pending") {
        respond(false, "Only Pending requests can be cancelled.", null, 409);
    }

    $pdo->prepare("UPDATE leave_requests SET status = 'Cancelled' WHERE leave_id = ?")->execute([$id]);
    respond(true, "Leave request cancelled.");
}

respond(false, "Method not allowed.", null, 405);