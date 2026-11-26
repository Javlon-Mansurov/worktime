<?php
require 'db.php';

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

function formatDurationCsv($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf("%d соат %02d дақ", $hours, $minutes);
}

// Ҳисоботи ҷамъбастии моҳона
$sql_summary = "SELECT 
                    e.id,
                    e.full_name,
                    e.position,
                    SUM(CASE WHEN a.id IS NOT NULL AND a.status NOT LIKE 'Сабаби узрнок%' THEN 1 ELSE 0 END) AS work_days,
                    SUM(CASE WHEN a.status = 'Дер омад' THEN 1 ELSE 0 END) AS late_count,
                    SUM(CASE WHEN a.status LIKE 'Сабаби узрнок%' THEN 1 ELSE 0 END) AS excused_count,
                    COALESCE(SUM(
                        CASE 
                            WHEN a.check_in IS NOT NULL AND a.check_out IS NOT NULL 
                            THEN TIMESTAMPDIFF(SECOND, CONCAT(a.work_date, ' ', a.check_in), CONCAT(a.work_date, ' ', a.check_out))
                            ELSE 0 
                        END
                    ), 0) AS total_seconds
                FROM employees e
                LEFT JOIN attendance a 
                    ON a.employee_id = e.id 
                    AND DATE_FORMAT(a.work_date, '%Y-%m') = ?
                GROUP BY e.id, e.full_name, e.position
                ORDER BY e.full_name ASC";
$stmt_summary = $pdo->prepare($sql_summary);
$stmt_summary->execute([$selected_month]);
$summary_rows = $stmt_summary->fetchAll(PDO::FETCH_ASSOC);

// Рӯйхати муфассали рӯз ба рӯз
$sql_att = "SELECT a.*, e.full_name, e.position 
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.id 
            WHERE DATE_FORMAT(a.work_date, '%Y-%m') = ?
            ORDER BY a.work_date ASC, a.check_in ASC";
$stmt_att = $pdo->prepare($sql_att);
$stmt_att->execute([$selected_month]);
$records = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

$filename = "hisobot_vaqti_kori_" . $selected_month . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM барои дуруст кушода шудани ҳарфҳои тоҷикӣ дар Excel
fwrite($out, "\xEF\xBB\xBF");

// Қисми 1: Ҳисоботи ҷамъбастӣ
fputcsv($out, ['ҲИСОБОТИ ҶАМЪБАСТИИ МОҲОНА', $selected_month], ';');
fputcsv($out, ['Ному насаб', 'Вазифа', 'Рӯзҳои корӣ', 'Дер омаданҳо', 'Сабабҳои узрнок', 'Соатҳои корӣ'], ';');

foreach ($summary_rows as $s) {
    fputcsv($out, [
        $s['full_name'],
        $s['position'],
        (int)$s['work_days'],
        (int)$s['late_count'],
        (int)$s['excused_count'],
        formatDurationCsv($s['total_seconds'])
    ], ';');
}

// Сатри холӣ ва Қисми 2: Рӯйхати рӯз ба рӯз
fputcsv($out, [], ';');
fputcsv($out, ['РӮЙХАТИ РӮЗ БА РӮЗИ ОМАДУРАФТ', $selected_month], ';');
fputcsv($out, ['Ному насаб', 'Вазифа', 'Сана', 'Омад', 'Рафт', 'Давомнокӣ', 'Статус'], ';');

foreach ($records as $row) {
    $is_excused = (strpos($row['status'], 'Сабаби узрнок') === 0);
    $dur = '-';
    if ($row['check_in'] && $row['check_out']) {
        $t1 = strtotime($row['work_date'] . ' ' . $row['check_in']);
        $t2 = strtotime($row['work_date'] . ' ' . $row['check_out']);
        $dur = formatDurationCsv(max(0, $t2 - $t1));
    }

    fputcsv($out, [
        $row['full_name'],
        $row['position'],
        date('d.m.Y', strtotime($row['work_date'])),
        $row['check_in'] ? date('H:i', strtotime($row['check_in'])) : '-',
        $is_excused ? '-' : ($row['check_out'] ? date('H:i', strtotime($row['check_out'])) : 'Дар кор'),
        $dur,
        $row['status']
    ], ';');
}

fclose($out);
exit();