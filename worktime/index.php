<?php
require 'db.php';

$today = date('Y-m-d');
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$admin = isAdmin();

if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}

// Санҷиши рӯзи ид барои имрӯз
$today_holiday = $pdo->prepare("SELECT title FROM holidays WHERE holiday_date = ?");
$today_holiday->execute([$today]);
$holiday_info = $today_holiday->fetch(PDO::FETCH_ASSOC);

// Рӯйхати кормандон
$employees = $pdo->query("SELECT * FROM employees ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Ҳисоботи ҷамъбастии моҳона барои ҳар корманд
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

// Рӯйхати муфассали омадурафт аз рӯи моҳи интихобшуда
$sql_att = "SELECT a.*, e.full_name, e.position 
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.id 
            WHERE DATE_FORMAT(a.work_date, '%Y-%m') = ?
            ORDER BY a.work_date DESC, a.check_in DESC";
$stmt_att = $pdo->prepare($sql_att);
$stmt_att->execute([$selected_month]);
$records = $stmt_att->fetchAll(PDO::FETCH_ASSOC);

// Рӯйхати таътилҳо ва ҷавобҳо
$sql_leaves = "SELECT l.*, e.full_name, e.position 
               FROM leaves l 
               JOIN employees e ON l.employee_id = e.id 
               ORDER BY l.start_date DESC";
$leaves = $pdo->query($sql_leaves)->fetchAll(PDO::FETCH_ASSOC);

// Рӯйхати идҳо
$holidays = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date ASC")->fetchAll(PDO::FETCH_ASSOC);

function formatDuration($seconds) {
    $seconds = max(0, (int)$seconds);
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    return sprintf("%d соат %02d дақ", $hours, $minutes);
}
?>

<!DOCTYPE html>
<html lang="tg">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Системаи Баҳисобгирии Вақти Корӣ</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1220px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        
        .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #e2e8f0; }
        .top-bar h2 { margin: 0; color: #1e293b; font-size: 22px; }
        .auth-box { display: flex; align-items: center; gap: 10px; font-size: 14px; }
        .btn-login { background: #1e293b; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 6px; font-weight: bold; }
        .btn-logout { background: #dc2626; color: #fff; text-decoration: none; padding: 8px 14px; border-radius: 6px; font-weight: bold; }

        .holiday-banner { background: #fef3c7; color: #92400e; padding: 12px; border-radius: 6px; text-align: center; font-weight: bold; margin-bottom: 20px; border: 1px solid #f59e0b; }
        .notice-box { background: #f1f5f9; color: #475569; padding: 12px 15px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #2563eb; }

        .tabs { display: flex; gap: 10px; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .tab-btn { background: #e2e8f0; color: #334155; border: none; padding: 10px 18px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px; }
        .tab-btn.active { background: #828386; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .section-title { font-size: 17px; color: #1e293b; margin: 22px 0 10px; border-left: 4px solid #2563eb; padding-left: 10px; }
        .form-box { background: #f8fafc; padding: 18px 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 18px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
        input, select { padding: 9px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 14px; }
        button.submit-btn { padding: 9px 16px; border: none; border-radius: 6px; color: white; font-weight: bold; cursor: pointer; font-size: 14px; }
        .btn-green { background: #16a34a; }
        .btn-orange { background: #ea580c; }
        .btn-blue { background: #2563eb; }
        .btn-teal { background: #0d9488; }

        .action-btns { display: flex; gap: 6px; flex-wrap: wrap; }
        .btn-sm { padding: 5px 10px; border: none; border-radius: 5px; font-size: 12px; font-weight: bold; cursor: pointer; color: #fff; }
        .btn-edit { background: #0284c7; }
        .btn-del { background: #dc2626; }

        table { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 18px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 14px; }
        th { background: #1e293b; color: white; }
        tr:hover { background: #f8fafc; }

        .badge { padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: bold; display: inline-block; }
        .badge-late { background: #fee2e2; color: #dc2626; }
        .badge-ontime { background: #dcfce7; color: #16a34a; }
        .badge-holiday { background: #fef3c7; color: #d97706; }
        .badge-excused { background: #e0f2fe; color: #0369a1; }
        .badge-vacation { background: #dbeafe; color: #1d4ed8; }
        .badge-leave { background: #f3e8ff; color: #7e22ce; }
        .num-center { text-align: center; }

        /* Модалҳои таҳрир */
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); justify-content: center; align-items: center; z-index: 1000; padding: 15px; }
        .modal-backdrop.open { display: flex; }
        .modal-card { background: #fff; padding: 22px; border-radius: 10px; width: 100%; max-width: 450px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-card h3 { margin-top: 0; color: #1e293b; }
        .modal-field { margin-bottom: 12px; }
        .modal-field label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 4px; color: #334155; }
        .modal-field input, .modal-field select { width: 100%; box-sizing: border-box; }
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
        .btn-cancel { background: #64748b; color: #fff; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="top-bar">
        <h2>Баҳисобгирии вақти кории кормандон</h2>
        <div class="auth-box">
            <?php if ($admin): ?>
                <span>Ҳолат: <strong>Админ (<?= htmlspecialchars($_SESSION['username']) ?>)</strong></span>
                <a href="logout.php" class="btn-logout">Баромад</a>
            <?php else: ?>
               <!-- <span>Ҳолат: Корманд / Меҳмон</span> -->
                <a href="login.php" class="btn-login">🔐 Воридшавии Админ</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($holiday_info): ?>
        <div class="holiday-banner">
            🎉 Имрӯз рӯзи ид аст: <?= htmlspecialchars($holiday_info['title']) ?>!
        </div> 
<?php endif; ?>

        <?php if (!empty($_GET['msg'])): ?>
        <div class="holiday-banner" style="background:#e0f2fe; color:#0369a1; border-color:#0284c7;">
            ℹ️ <?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="openTab('attendance', event)">🕒 Омадурафт ва Ҳисобот</button>
        <button class="tab-btn" onclick="openTab('leaves', event)">🏖 Таътил ва Ҷавоб</button>
        <button class="tab-btn" onclick="openTab('holidays', event)">📅 Рӯзҳои ид</button>
        <button class="tab-btn" onclick="openTab('employees', event)">👤 Кормандон</button>
    </div>

    <!-- БАХШИ 1: ОМАДУРАФТ ВА ҲИСОБОТИ МОҲОНА -->
    <div id="attendance" class="tab-content active">
        <form action="action.php" method="POST" class="form-box">
            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
            <select name="employee_id" required>
                <option value="">-- Кормандро интихоб кунед --</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?> (<?= htmlspecialchars($emp['position']) ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="action" value="check_in" class="submit-btn btn-green">Ба кор омадам</button>
            <button type="submit" name="action" value="check_out" class="submit-btn btn-orange">Аз кор рафтам</button>
        </form>

        <?php if ($admin): ?>
            <form action="action.php" method="POST" class="form-box">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                <strong>Сабти набаромадан бо сабаби узрнок:</strong>
                <select name="employee_id" required>
                    <option value="">-- Корманд --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="work_date" value="<?= $today ?>" required>
                <input type="text" name="reason" placeholder="Сабаб (масалан: беморӣ, ҳолати оилавӣ)" style="min-width: 240px;">
                <button type="submit" name="action" value="excused_absence" class="submit-btn btn-teal">Сабти сабаби узрнок</button>
            </form>
        <?php endif; ?>

        <form method="GET" style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
            <label><strong>Интихоби моҳ барои ҳисобот:</strong></label>
            <input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>">
            <button type="submit" class="submit-btn btn-blue">Нишон додан</button>
            <a href="export_csv.php?month=<?= urlencode($selected_month) ?>"
               class="submit-btn btn-green"
               style="text-decoration: none; display: inline-block;">
               ⬇️ Боргирии CSV (Excel)
            </a>
        </form>

        <!-- Ҳисоботи ҷамъбастӣ аз рӯи моҳ -->
        <h3 class="section-title">📊 Ҳисоботи ҷамъбастӣ барои моҳи <?= htmlspecialchars($selected_month) ?></h3>
        <table>
            <thead>
                <tr>
                    <th>Ному насаб</th>
                    <th>Вазифа</th>
                    <th class="num-center">Рӯзҳои корӣ</th>
                    <th class="num-center">Дер омаданҳо</th>
                    <th class="num-center">Сабабҳои узрнок</th>
                    <th>Соатҳои кории ҳисобшуда</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($summary_rows): ?>
                    <?php foreach ($summary_rows as $s): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($s['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($s['position']) ?></td>
                            <td class="num-center"><?= (int)$s['work_days'] ?></td>
                            <td class="num-center">
                                <?php if ((int)$s['late_count'] > 0): ?>
                                    <span class="badge badge-late"><?= (int)$s['late_count'] ?> бор</span>
                                <?php else: ?>
                                    <span class="badge badge-ontime">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="num-center">
                                <?php if ((int)$s['excused_count'] > 0): ?>
                                    <span class="badge badge-excused"><?= (int)$s['excused_count'] ?> рӯз</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">0</span>
                                <?php endif; ?>
                            </td>
                            <td><strong><?= formatDuration($s['total_seconds']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center;">Маълумот ёфт нашуд.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Ҷадвали муфассали рӯз ба рӯз -->
        <h3 class="section-title">📋 Рӯйхати рӯз ба рӯзи омадурафт</h3>
        <table>
            <thead>
                <tr>
                    <th>Ному насаб</th>
                    <th>Вазифа</th>
                    <th>Сана</th>
                    <th>Омад</th>
                    <th>Рафт</th>
                    <th>Давомнокӣ</th>
                    <th>Статус</th>
                    <?php if ($admin): ?>
                        <th>Амалҳо</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($records): ?>
                    <?php foreach ($records as $row): ?>
                        <?php
                            $day_sec = 0;
                            if ($row['check_in'] && $row['check_out']) {
                                $t1 = strtotime($row['work_date'] . ' ' . $row['check_in']);
                                $t2 = strtotime($row['work_date'] . ' ' . $row['check_out']);
                                $day_sec = max(0, $t2 - $t1);
                            }
                            $is_excused = (strpos($row['status'], 'Сабаби узрнок') === 0);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['full_name']) ?></td>
                            <td><?= htmlspecialchars($row['position']) ?></td>
                            <td><?= date('d.m.Y', strtotime($row['work_date'])) ?></td>
                            <td><?= $row['check_in'] ? date('H:i', strtotime($row['check_in'])) : '-' ?></td>
                            <td>
                                <?php if ($is_excused): ?>
                                    -
                                <?php else: ?>
                                    <?= $row['check_out'] ? date('H:i', strtotime($row['check_out'])) : 'Дар кор' ?>
                                <?php endif; ?>
                            </td>
                            <td><?= ($row['check_in'] && $row['check_out']) ? formatDuration($day_sec) : '-' ?></td>
                            <td>
                                <?php 
                                    $cls = 'badge-ontime';
                                    if ($row['status'] === 'Дер омад') $cls = 'badge-late';
                                    elseif ($is_excused) $cls = 'badge-excused';
                                    elseif (strpos($row['status'], 'Рӯзи ид') !== false) $cls = 'badge-holiday';
                                ?>
                                <span class="badge <?= $cls ?>"><?= htmlspecialchars($row['status']) ?></span>
                            </td>
                            <?php if ($admin): ?>
                                <td>
                                    <div class="action-btns">
                                        <button type="button" class="btn-sm btn-edit"
                                            onclick='openEditAttendanceModal(<?= json_encode([
                                                "id" => (int)$row["id"],
                                                "full_name" => $row["full_name"],
                                                "work_date" => $row["work_date"],
                                                "check_in" => $row["check_in"] ? substr($row["check_in"], 0, 5) : "",
                                                "check_out" => $row["check_out"] ? substr($row["check_out"], 0, 5) : "",
                                                "status" => $row["status"]
                                            ], JSON_UNESCAPED_UNICODE) ?>)'>Таҳрир</button>

                                        <form action="action.php" method="POST" style="display:inline;" onsubmit="return confirm('Ин сабт нест карда шавад?');">
                                            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                                            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                            <button type="submit" name="action" value="delete_attendance" class="btn-sm btn-del">Нест</button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="<?= $admin ? '8' : '7' ?>" style="text-align:center;">Дар ин моҳ сабт нест.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- БАХШИ 2: ТАЪТИЛИ МЕҲНАТӢ ВА ҶАВОБ -->
    <div id="leaves" class="tab-content">
        <?php if ($admin): ?>
            <form action="action.php" method="POST" class="form-box">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                <select name="employee_id" required>
                    <option value="">-- Кормандро интихоб кунед --</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="leave_type" required>
                    <option value="Таътили меҳнатӣ">Таътили меҳнатӣ</option>
                    <option value="Ҷавоб">Ҷавоб (Отгул)</option>
                </select>
                <label>Аз:</label>
                <input type="date" name="start_date" required>
                <label>То:</label>
                <input type="date" name="end_date" required>
                <input type="text" name="reason" placeholder="Сабаб (ихтиёрӣ)">
                <button type="submit" name="action" value="add_leave" class="submit-btn btn-blue">Сабт кардан</button>
            </form>
        <?php else: ?>
            <div class="notice-box">Барои сабти таътили меҳнатӣ ё ҷавоб ҳамчун Админ ворид шавед.</div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Корманд</th>
                    <th>Намуд</th>
                    <th>Аз санаи</th>
                    <th>То санаи</th>
                    <th>Сабаб</th>
                    <th>Ҳолати кунунӣ</th>
                    <?php if ($admin): ?>
                        <th>Амалҳо</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($leaves as $lv): ?>
                    <?php 
                        $is_active = ($today >= $lv['start_date'] && $today <= $lv['end_date']);
                        $badge_cls = ($lv['leave_type'] === 'Таътили меҳнатӣ') ? 'badge-vacation' : 'badge-leave';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($lv['full_name']) ?></td>
                        <td><span class="badge <?= $badge_cls ?>"><?= htmlspecialchars($lv['leave_type']) ?></span></td>
                        <td><?= date('d.m.Y', strtotime($lv['start_date'])) ?></td>
                        <td><?= date('d.m.Y', strtotime($lv['end_date'])) ?></td>
                        <td><?= htmlspecialchars($lv['reason'] ?: '-') ?></td>
                        <td>
                            <?= $is_active ? '<strong style="color:#16a34a;">Ҳоло дар рухсатӣ</strong>' : '<span style="color:#64748b;">Ба анҷом расида / Оянда</span>' ?>
                        </td>
                        <?php if ($admin): ?>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn-sm btn-edit"
                                        onclick='openEditLeaveModal(<?= json_encode([
                                            "id" => (int)$lv["id"],
                                            "employee_id" => (int)$lv["employee_id"],
                                            "leave_type" => $lv["leave_type"],
                                            "start_date" => $lv["start_date"],
                                            "end_date" => $lv["end_date"],
                                            "reason" => $lv["reason"] ?? ""
                                        ], JSON_UNESCAPED_UNICODE) ?>)'>Таҳрир</button>

                                    <form action="action.php" method="POST" style="display:inline;" onsubmit="return confirm('Ин рухсатӣ нест карда шавад?');">
                                        <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$lv['id'] ?>">
                                        <button type="submit" name="action" value="delete_leave" class="btn-sm btn-del">Нест</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- БАХШИ 3: РӮЗҲОИ ИД -->
    <div id="holidays" class="tab-content">
        <?php if ($admin): ?>
            <form action="action.php" method="POST" class="form-box">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                <input type="date" name="holiday_date" required>
                <input type="text" name="title" placeholder="Номи ид (масалан: Иди Наврӯз)" required style="min-width: 250px;">
                <button type="submit" name="action" value="add_holiday" class="submit-btn btn-blue">Иловаи ид</button>
            </form>
        <?php else: ?>
            <div class="notice-box">Барои илова кардани рӯзи ид ҳамчун Админ ворид шавед.</div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>Сана</th>
                    <th>Номи рӯзи ид</th>
                    <?php if ($admin): ?>
                        <th>Амалҳо</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($holidays as $hol): ?>
                    <tr>
                        <td><strong><?= date('d.m.Y', strtotime($hol['holiday_date'])) ?></strong></td>
                        <td><?= htmlspecialchars($hol['title']) ?></td>
                        <?php if ($admin): ?>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn-sm btn-edit"
                                        onclick='openEditHolidayModal(<?= json_encode([
                                            "id" => (int)$hol["id"],
                                            "holiday_date" => $hol["holiday_date"],
                                            "title" => $hol["title"]
                                        ], JSON_UNESCAPED_UNICODE) ?>)'>Таҳрир</button>

                                    <form action="action.php" method="POST" style="display:inline;" onsubmit="return confirm('Ин рӯзи ид нест карда шавад?');">
                                        <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$hol['id'] ?>">
                                        <button type="submit" name="action" value="delete_holiday" class="btn-sm btn-del">Нест</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- БАХШИ 4: КОРМАНДОН -->
    <div id="employees" class="tab-content">
        <?php if ($admin): ?>
            <form action="action.php" method="POST" class="form-box">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                <input type="text" name="full_name" placeholder="Ному насаби корманд" required style="min-width: 220px;">
                <input type="text" name="position" placeholder="Вазифа" required style="min-width: 200px;">
                <button type="submit" name="action" value="add_employee" class="submit-btn btn-green">Илова кардан</button>
            </form>
        <?php else: ?>
            <div class="notice-box">Барои илова кардани корманди нав ҳамчун Админ ворид шавед.</div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ному насаб</th>
                    <th>Вазифа</th>
                    <?php if ($admin): ?>
                        <th>Амалҳо</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= $emp['id'] ?></td>
                        <td><?= htmlspecialchars($emp['full_name']) ?></td>
                        <td><?= htmlspecialchars($emp['position']) ?></td>
                        <?php if ($admin): ?>
                            <td>
                                <div class="action-btns">
                                    <button type="button" class="btn-sm btn-edit"
                                        onclick='openEditEmployeeModal(<?= json_encode([
                                            "id" => (int)$emp["id"],
                                            "full_name" => $emp["full_name"],
                                            "position" => $emp["position"]
                                        ], JSON_UNESCAPED_UNICODE) ?>)'>Таҳрир</button>

                                    <form action="action.php" method="POST" style="display:inline;" onsubmit="return confirm('Корманд ва ҳамаи сабтҳои ӯ нест карда шаванд?');">
                                        <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$emp['id'] ?>">
                                        <button type="submit" name="action" value="delete_employee" class="btn-sm btn-del">Нест</button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($admin): ?>
            <h3 class="section-title">🔑 Иваз кардани пароли Админ</h3>
            <form action="action.php" method="POST" class="form-box">
                <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
                <input type="password" name="new_password" placeholder="Пароли нав (ҳадди ақал 5 аломат)" required minlength="5" style="min-width: 240px;">
                <button type="submit" name="action" value="change_password" class="submit-btn btn-blue">Иваз кардани парол</button>
            </form>
        <?php endif; ?>
    </div>
    
</div>

<?php if ($admin): ?>
<!-- 1. Равзанаи таҳрири сабти омадурафт -->
<div id="editAttendanceModal" class="modal-backdrop" onclick="if(event.target === this) closeModal('editAttendanceModal');">
    <div class="modal-card">
        <h3>✏️ Таҳрири сабти омадурафт</h3>
        <form action="action.php" method="POST">
            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
            <input type="hidden" name="id" id="edit_att_id">

            <div class="modal-field">
                <label>Корманд</label>
                <input type="text" id="edit_att_employee" disabled>
            </div>
            <div class="modal-field">
                <label>Сана</label>
                <input type="date" name="work_date" id="edit_att_date" required>
            </div>
            <div class="modal-field">
                <label>Вақти омадан (HH:MM)</label>
                <input type="time" name="check_in" id="edit_att_in">
            </div>
            <div class="modal-field">
                <label>Вақти рафтан (HH:MM)</label>
                <input type="time" name="check_out" id="edit_att_out">
            </div>
            <div class="modal-field">
                <label>Статус</label>
                <input type="text" name="status" id="edit_att_status" required>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editAttendanceModal')">Бекор кардан</button>
                <button type="submit" name="action" value="edit_attendance" class="submit-btn btn-blue">Захира кардан</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. Равзанаи таҳрири таътил ё ҷавоб -->
<div id="editLeaveModal" class="modal-backdrop" onclick="if(event.target === this) closeModal('editLeaveModal');">
    <div class="modal-card">
        <h3>✏️ Таҳрири таътил ё ҷавоб</h3>
        <form action="action.php" method="POST">
            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
            <input type="hidden" name="id" id="edit_lv_id">

            <div class="modal-field">
                <label>Корманд</label>
                <select name="employee_id" id="edit_lv_employee" required>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-field">
                <label>Намуд</label>
                <select name="leave_type" id="edit_lv_type" required>
                    <option value="Таътили меҳнатӣ">Таътили меҳнатӣ</option>
                    <option value="Ҷавоб">Ҷавоб</option>
                </select>
            </div>
            <div class="modal-field">
                <label>Аз санаи</label>
                <input type="date" name="start_date" id="edit_lv_start" required>
            </div>
            <div class="modal-field">
                <label>То санаи</label>
                <input type="date" name="end_date" id="edit_lv_end" required>
            </div>
            <div class="modal-field">
                <label>Сабаб</label>
                <input type="text" name="reason" id="edit_lv_reason">
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editLeaveModal')">Бекор кардан</button>
                <button type="submit" name="action" value="edit_leave" class="submit-btn btn-blue">Захира кардан</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. Равзанаи таҳрири рӯзи ид -->
<div id="editHolidayModal" class="modal-backdrop" onclick="if(event.target === this) closeModal('editHolidayModal');">
    <div class="modal-card">
        <h3>✏️ Таҳрири рӯзи ид</h3>
        <form action="action.php" method="POST">
            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
            <input type="hidden" name="id" id="edit_hol_id">

            <div class="modal-field">
                <label>Сана</label>
                <input type="date" name="holiday_date" id="edit_hol_date" required>
            </div>
            <div class="modal-field">
                <label>Номи рӯзи ид</label>
                <input type="text" name="title" id="edit_hol_title" required>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editHolidayModal')">Бекор кардан</button>
                <button type="submit" name="action" value="edit_holiday" class="submit-btn btn-blue">Захира кардан</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. Равзанаи таҳрири маълумоти корманд -->
<div id="editEmployeeModal" class="modal-backdrop" onclick="if(event.target === this) closeModal('editEmployeeModal');">
    <div class="modal-card">
        <h3>✏️ Таҳрири маълумоти корманд</h3>
        <form action="action.php" method="POST">
            <input type="hidden" name="redirect_month" value="<?= htmlspecialchars($selected_month) ?>">
            <input type="hidden" name="id" id="edit_emp_id">

            <div class="modal-field">
                <label>Ному насаб</label>
                <input type="text" name="full_name" id="edit_emp_name" required>
            </div>
            <div class="modal-field">
                <label>Вазифа</label>
                <input type="text" name="position" id="edit_emp_position" required>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editEmployeeModal')">Бекор кардан</button>
                <button type="submit" name="action" value="edit_employee" class="submit-btn btn-blue">Захира кардан</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
    function openTab(tabId, evt) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        if (evt && evt.currentTarget) {
            evt.currentTarget.classList.add('active');
        }
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('open');
    }

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('open');
    }

    function openEditAttendanceModal(data) {
        document.getElementById('edit_att_id').value = data.id;
        document.getElementById('edit_att_employee').value = data.full_name;
        document.getElementById('edit_att_date').value = data.work_date;
        document.getElementById('edit_att_in').value = data.check_in || '';
        document.getElementById('edit_att_out').value = data.check_out || '';
        document.getElementById('edit_att_status').value = data.status || '';
        openModal('editAttendanceModal');
    }

    function openEditLeaveModal(data) {
        document.getElementById('edit_lv_id').value = data.id;
        document.getElementById('edit_lv_employee').value = String(data.employee_id);
        document.getElementById('edit_lv_type').value = data.leave_type;
        document.getElementById('edit_lv_start').value = data.start_date;
        document.getElementById('edit_lv_end').value = data.end_date;
        document.getElementById('edit_lv_reason').value = data.reason || '';
        openModal('editLeaveModal');
    }

    function openEditHolidayModal(data) {
        document.getElementById('edit_hol_id').value = data.id;
        document.getElementById('edit_hol_date').value = data.holiday_date;
        document.getElementById('edit_hol_title').value = data.title || '';
        openModal('editHolidayModal');
    }

    function openEditEmployeeModal(data) {
        document.getElementById('edit_emp_id').value = data.id;
        document.getElementById('edit_emp_name').value = data.full_name || '';
        document.getElementById('edit_emp_position').value = data.position || '';
        openModal('editEmployeeModal');
    }
</script>

</body>
</html>