<?php
require 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $today = date('Y-m-d');
    $current_time = date('H:i:s');
    $work_start_time = '09:00:00';
    $redirectMonth = $_POST['redirect_month'] ?? '';
    $baseRedirect = preg_match('/^\d{4}-\d{2}$/', $redirectMonth) ? "index.php?month=" . urlencode($redirectMonth) : "index.php";

    // 1. Сабти ба кор омадан
    if ($action === 'check_in') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);

        if ($employee_id > 0) {
            // Санҷиш: оё корманд имрӯз дар таътил ё ҷавоб аст?
            $leaveCheck = $pdo->prepare("SELECT leave_type FROM leaves WHERE employee_id = ? AND ? BETWEEN start_date AND end_date");
            $leaveCheck->execute([$employee_id, $today]);
            $activeLeave = $leaveCheck->fetch(PDO::FETCH_ASSOC);

            if ($activeLeave) {
                $sep = strpos($baseRedirect, '?') !== false ? '&' : '?';
                header("Location: " . $baseRedirect . $sep . "msg=" . urlencode("Ин корманд имрӯз дар " . $activeLeave['leave_type'] . " қарор дорад!"));
                exit();
            }

            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?");
            $stmt->execute([$employee_id, $today]);

            if ($stmt->rowCount() == 0) {
                $hol = $pdo->prepare("SELECT title FROM holidays WHERE holiday_date = ?");
                $hol->execute([$today]);

                if ($hol->rowCount() > 0) {
                    $status = 'Рӯзи ид (Кор кард)';
                } else {
                    $status = ($current_time > $work_start_time) ? 'Дер омад' : 'Сари вақт омад';
                }

                $insert = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, check_in, status) VALUES (?, ?, ?, ?)");
                $insert->execute([$employee_id, $today, $current_time, $status]);
            }
        }
    }

    // 2. Сабти аз кор рафтан
    elseif ($action === 'check_out') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        if ($employee_id > 0) {
            $update = $pdo->prepare("UPDATE attendance SET check_out = ? WHERE employee_id = ? AND work_date = ? AND check_out IS NULL");
            $update->execute([$current_time, $employee_id, $today]);
        }
    }

    // Амалҳои Админ
    elseif (isAdmin()) {
        // Иваз кардани пароли админ
        if ($action === 'change_password') {
            $new_pass = $_POST['new_password'] ?? '';
            if (strlen($new_pass) >= 5) {
                $hash = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hash, $_SESSION['user_id']]);
                $sep = strpos($baseRedirect, '?') !== false ? '&' : '?';
                header("Location: " . $baseRedirect . $sep . "msg=" . urlencode("Парол бо муваффақият иваз карда шуд!"));
                exit();
            }
        }

        elseif ($action === 'excused_absence') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $work_date = !empty($_POST['work_date']) ? $_POST['work_date'] : $today;
            $reason = trim($_POST['reason'] ?? '');
            $status = $reason !== '' ? 'Сабаби узрнок: ' . $reason : 'Сабаби узрнок';

            if ($employee_id > 0) {
                $check = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND work_date = ?");
                $check->execute([$employee_id, $work_date]);
                $existing = $check->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $upd = $pdo->prepare("UPDATE attendance SET check_in = NULL, check_out = NULL, status = ? WHERE id = ?");
                    $upd->execute([$status, $existing['id']]);
                } else {
                    $ins = $pdo->prepare("INSERT INTO attendance (employee_id, work_date, check_in, check_out, status) VALUES (?, ?, NULL, NULL, ?)");
                    $ins->execute([$employee_id, $work_date, $status]);
                }
            }
        }

        elseif ($action === 'edit_attendance') {
            $id = (int)($_POST['id'] ?? 0);
            $work_date = $_POST['work_date'] ?? '';
            $check_in = !empty($_POST['check_in']) ? $_POST['check_in'] : null;
            $check_out = !empty($_POST['check_out']) ? $_POST['check_out'] : null;
            $status = trim($_POST['status'] ?? '');

            if ($id > 0 && !empty($work_date) && $status !== '') {
                $upd = $pdo->prepare("UPDATE attendance SET work_date = ?, check_in = ?, check_out = ?, status = ? WHERE id = ?");
                $upd->execute([$work_date, $check_in, $check_out, $status, $id]);
            }
        }

        elseif ($action === 'delete_attendance') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$id]);
            }
        }

        elseif ($action === 'add_employee') {
            $full_name = trim($_POST['full_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            if ($full_name !== '' && $position !== '') {
                $pdo->prepare("INSERT INTO employees (full_name, position) VALUES (?, ?)")->execute([$full_name, $position]);
            }
        }

        elseif ($action === 'edit_employee') {
            $id = (int)($_POST['id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            if ($id > 0 && $full_name !== '' && $position !== '') {
                $pdo->prepare("UPDATE employees SET full_name = ?, position = ? WHERE id = ?")->execute([$full_name, $position, $id]);
            }
        }

        elseif ($action === 'delete_employee') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
            }
        }

        elseif ($action === 'add_holiday') {
            $holiday_date = $_POST['holiday_date'] ?? '';
            $title = trim($_POST['title'] ?? '');
            if ($holiday_date !== '' && $title !== '') {
                $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date, title) VALUES (?, ?)")->execute([$holiday_date, $title]);
            }
        }

        elseif ($action === 'edit_holiday') {
            $id = (int)($_POST['id'] ?? 0);
            $holiday_date = $_POST['holiday_date'] ?? '';
            $title = trim($_POST['title'] ?? '');
            if ($id > 0 && $holiday_date !== '' && $title !== '') {
                $pdo->prepare("UPDATE holidays SET holiday_date = ?, title = ? WHERE id = ?")->execute([$holiday_date, $title, $id]);
            }
        }

        elseif ($action === 'delete_holiday') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([$id]);
            }
        }

        elseif ($action === 'add_leave') {
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $leave_type = $_POST['leave_type'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $reason = trim($_POST['reason'] ?? '');

            if ($employee_id > 0 && $leave_type !== '' && $start_date !== '' && $end_date !== '') {
                $pdo->prepare("INSERT INTO leaves (employee_id, leave_type, start_date, end_date, reason) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$employee_id, $leave_type, $start_date, $end_date, $reason]);
            }
        }

        elseif ($action === 'edit_leave') {
            $id = (int)($_POST['id'] ?? 0);
            $employee_id = (int)($_POST['employee_id'] ?? 0);
            $leave_type = $_POST['leave_type'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';
            $reason = trim($_POST['reason'] ?? '');

            if ($id > 0 && $employee_id > 0 && $leave_type !== '' && $start_date !== '' && $end_date !== '') {
                $pdo->prepare("UPDATE leaves SET employee_id = ?, leave_type = ?, start_date = ?, end_date = ?, reason = ? WHERE id = ?")
                    ->execute([$employee_id, $leave_type, $start_date, $end_date, $reason, $id]);
            }
        }

        elseif ($action === 'delete_leave') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare("DELETE FROM leaves WHERE id = ?")->execute([$id]);
            }
        }
    }
}

header("Location: " . ($baseRedirect ?? "index.php"));
exit();
?>