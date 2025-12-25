<?php
require __DIR__ . '/db.php';

$message = isset($_GET['message']) ? $_GET['message'] : '';
$errors = [];
$editingId = null;
$editingSession = null;
if (isset($_GET['edit'])) {
    $token = (string)$_GET['edit'];
    $resolved = token_to_id('session', $token);
    if ($resolved !== null) {
        $editingId = $resolved;
        $editingSession = findSession($conn, $editingId);
    } else {
        $errors[] = 'Invalid session token.';
    }
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

if (isset($_GET['delete'])) {
    $deleteToken = (string)$_GET['delete'];
    $deleteId = token_to_id('session', $deleteToken);
    if ($deleteId === null) {
        $errors[] = 'Invalid session token.';
    } else {
    $hasRoadCol = tableHasColumn($conn, 'drivingSession', 'road_id');
    $sessionIdCol = sessionIdColumn($conn);
    $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
        ? 'idSession'
        : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);
    $conn->begin_transaction();
    if (!$hasRoadCol) {
        if ($junctionCol) {
            $stmtJ = $conn->prepare("DELETE FROM drivingSession_roadSurfaceType WHERE {$junctionCol} = ?");
            $stmtJ->bind_param('i', $deleteId);
            $stmtJ->execute();
            $stmtJ->close();
        }
    }

    $stmt = $conn->prepare("DELETE FROM drivingSession WHERE {$sessionIdCol} = ?");
    $stmt->bind_param('i', $deleteId);
    $stmt->execute();
    $stmt->close();
    $conn->commit();
    $message = 'Session deleted.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $errors[] = 'Invalid request token.';
    }

    $tokenId    = $_POST['id'] ?? '';
    $id         = token_to_id('session', (string)$tokenId);
    $date       = $_POST['date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time   = $_POST['end_time'] ?? '';
    $mileage    = $_POST['mileage'] ?? '';
    $weather_id = $_POST['weather_id'] ?? '';
    $traffic_id = $_POST['traffic_id'] ?? '';
    $road_ids   = $_POST['road_id'] ?? [];
    if (!is_array($road_ids)) {
        $road_ids = [$road_ids];
    }
    $road_ids = array_values(array_unique(array_map('strval', $road_ids)));
    $idOfDay    = $_POST['idOfDay'] ?? '';
    $visibility_id = $_POST['visibility_id'] ?? '';
    $parking_id    = $_POST['parking_id'] ?? '';
    $manoeuvre_id  = $_POST['manoeuvre_id'] ?? '';

    $hasPartOfDay = tableHasColumn($conn, 'drivingSession', 'partOfDay');
    $hasIdOfDay   = tableHasColumn($conn, 'drivingSession', 'idOfDay');
    $hasRoadCol   = tableHasColumn($conn, 'drivingSession', 'road_id');
    $dayField     = $hasPartOfDay ? 'partOfDay' : ($hasIdOfDay ? 'idOfDay' : null);

    if (!$id || !$date || !$start_time || !$end_time || !$mileage || !$weather_id || !$traffic_id || empty($road_ids) || !$idOfDay || !$visibility_id || !$parking_id || !$manoeuvre_id) {
        $errors[] = 'Please complete all fields.';
    }

    $dtDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dtDate || $dtDate->format('Y-m-d') !== $date) {
        $errors[] = 'Invalid date format.';
    }

    $startValid = DateTime::createFromFormat('H:i', $start_time);
    $endValid = DateTime::createFromFormat('H:i', $end_time);
    if (!$startValid || !$endValid || $startValid->format('H:i') !== $start_time || $endValid->format('H:i') !== $end_time) {
        $errors[] = 'Invalid time format.';
    } elseif (strtotime($start_time) >= strtotime($end_time)) {
        $errors[] = 'Start time must be earlier than end time.';
    }

    if (!is_numeric($mileage) || $mileage <= 0) {
        $errors[] = 'Mileage must be a positive number.';
    }

    if ($weather_id && !array_key_exists((string)$weather_id, $weatherOptions)) {
        $errors[] = 'Invalid weather selection.';
    }
    if ($traffic_id && !array_key_exists((string)$traffic_id, $trafficOptions)) {
        $errors[] = 'Invalid traffic selection.';
    }
    if ($visibility_id && !array_key_exists((string)$visibility_id, $visibilityOptions)) {
        $errors[] = 'Invalid visibility selection.';
    }
    if ($parking_id && !array_key_exists((string)$parking_id, $parkingOptions)) {
        $errors[] = 'Invalid parking selection.';
    }
    if ($manoeuvre_id && !array_key_exists((string)$manoeuvre_id, $manoeuvreOptions)) {
        $errors[] = 'Invalid manoeuvre selection.';
    }
    foreach ($road_ids as $rid) {
        if (!array_key_exists((string)$rid, $roadOptions)) {
            $errors[] = 'Invalid road surface selection.';
            break;
        }
    }
    if ($idOfDay && !array_key_exists($idOfDay, $dayParts)) {
        $errors[] = 'Invalid part of day selection.';
    }
    if (!$dayField) {
        $errors[] = 'Database is missing part of day column.';
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();
            $sessionIdCol = sessionIdColumn($conn);
            $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
                ? 'idSession'
                : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);

            $cols = ['date = ?', 'start_time = ?', 'end_time = ?', 'mileage = ?', 'weather_id = ?', 'traffic_id = ?', 'visibility_id = ?', 'parking_id = ?', 'manoeuvre_id = ?'];
            $types = 'sssdiiiii';
            $params = [$date, $start_time, $end_time, $mileage, $weather_id, $traffic_id, $visibility_id, $parking_id, $manoeuvre_id];

            $cols[] = "{$dayField} = ?";
            $types .= 's';
            $params[] = $idOfDay;

            $types .= 'i';
            $params[] = $id;

            $sql = 'UPDATE drivingSession SET ' . implode(', ', $cols) . " WHERE {$sessionIdCol} = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();

            if ($junctionCol) {
                $stmtDel = $conn->prepare("DELETE FROM drivingSession_roadSurfaceType WHERE {$junctionCol} = ?");
                $stmtDel->bind_param('i', $id);
                $stmtDel->execute();
                $stmtDel->close();

                $stmtRoad = $conn->prepare("INSERT INTO drivingSession_roadSurfaceType ({$junctionCol}, road_id) VALUES (?, ?)");
                foreach ($road_ids as $rid) {
                    $ridInt = (int) $rid;
                    $stmtRoad->bind_param('ii', $id, $ridInt);
                    $stmtRoad->execute();
                }
                $stmtRoad->close();
            }

            $conn->commit();

            $message = 'Session updated.';
            $editingId = null;
            $editingSession = null;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    } else {
        $editingId = $id;
        $editingSession = [
            'id' => $id,
            'date' => $date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'mileage' => $mileage,
            'weather_id' => $weather_id,
            'traffic_id' => $traffic_id,
            'road_id' => $road_id,
            'idOfDay' => $idOfDay,
            'visibility_id' => $visibility_id,
            'parking_id' => $parking_id,
            'manoeuvre_id' => $manoeuvre_id,
        ];
    }
}

$sessions = fetchSessions($conn);

// Map each session id to an array of road_ids
$sessionRoads = [];
$junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
    ? 'idSession'
    : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);
if ($junctionCol && !empty($sessions)) {
    $ids = array_unique(array_map(fn($s) => (int)$s['id'], $sessions));
    $idList = implode(',', $ids);
    $resRoads = $conn->query("SELECT {$junctionCol} AS sid, road_id FROM drivingSession_roadSurfaceType WHERE {$junctionCol} IN ({$idList}) ORDER BY road_id");
    if ($resRoads) {
        while ($row = $resRoads->fetch_assoc()) {
            $sid = (string)$row['sid'];
            if (!isset($sessionRoads[$sid])) {
                $sessionRoads[$sid] = [];
            }
            $sessionRoads[$sid][] = $row['road_id'];
        }
        $resRoads->free();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driving Experiences</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=BBH+Bogle&family=BBH+Hegarty&family=Lilita+One&family=Stack+Sans+Text:wght@200..700&family=Chivo:wght@400;600&display=swap"
        rel="stylesheet">

    <style type="text/tailwindcss">
        @theme {
            --color-main-green: #1E3C14;
            --color-hover-green: #EEF3CB;
            --color-yellow: #FFF7C8;
        }
        
        .chivo-regular {
            font-family: "Chivo", sans-serif;
            font-optical-sizing: auto;
            font-weight: 800;
            font-style: normal;
        }

        .bbh-hegarty-regular { 
            font-family: "BBH Hegarty", sans-serif; 
            font-weight: 400; 
        }
        @media (max-width: 768px) {
            header nav { padding: 10px; }
            header nav h1 { font-size: 1.25rem; line-height: 1.2; }
            header nav ul { gap: 4px; flex-wrap: wrap; }
            .table-shell { padding: 1.25rem !important; }
            .table-shell h2 { font-size: 1.8rem; }
        }
        .mobile-menu { display: none; }
        .mobile-menu.active { display: block; }
    </style>
</head>

<body class="bg-yellow">
   <header class="bg-[#FFF7EE] fixed w-full border-b border-main-green shadow-md z-50">
        <nav class="max-w-[1680px] mx-auto px-3 sm:px-6">
            <div class="flex justify-between items-center py-3 sm:py-4 lg:h-30">
                <h1 class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold uppercase text-main-green bbh-hegarty-regular tracking-wider leading-tight">
                    Driving <br>Experience<br> Recorder
                </h1>
                
                <!-- Mobile menu button -->
                <button id="mobile-menu-btn" class="lg:hidden text-main-green p-2 hover:bg-hover-green rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>

                <!-- Desktop menu -->
                <ul class="hidden lg:flex  xl:text-base 2xl:text-xl chivo-regular h-full text-main-green">
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="form.html" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Home</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="record.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Record</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="experiences.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Experiences</a>
                    </li>
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="summary.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Summary</a>
                    </li>
                </ul>
            </div>

            <!-- Mobile menu -->
            <div id="mobile-menu" class="mobile-menu lg:hidden pb-3">
                <ul class="flex flex-col text-sm sm:text-base chivo-regular text-main-green space-y-1">
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="form.html" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Home</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="record.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Record Experience</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="experiences.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">View Experiences</a>
                    </li>
                    <li class="hover:bg-hover-green rounded-lg transition-colors">
                        <a href="summary.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Summary</a>
                    </li>
                </ul>
            </div>
        </nav>
    </header>


    <main class="min-h-screen pt-40 pb-16">
        <div class="max-w-7xl mx-auto px-6 space-y-8">
            <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-8">
                <div class="flex items-center justify-between mb-6  flex-col md:flex-row gap-2">
                    <div class="flex flex-col gap-2 items-center justify-center md:justify-start md:items-start">
                        <p class="text-main-green uppercase tracking-[0.3em] text-sm chivo-regular">Manage Sessions</p>
                        <h2 class="text-3xl font-bold text-main-green md:text-left text-center  bbh-hegarty-regular">Edit or delete saved drives</h2>
                    </div>
                    <a href="record.php" class="text-main-green hover:underline chivo-regular">+ Add new session</a>
                </div>

                <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-3">
                    <label class="flex flex-col gap-1 text-main-green chivo-regular">
                        <span class="text-sm uppercase tracking-widest">Filter by Weather</span>
                        <select id="filterWeather" class="rounded-xl border border-main-green/30 bg-white p-3">
                            <option value="">All</option>
                            <?php foreach ($weatherOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-main-green chivo-regular">
                        <span class="text-sm uppercase tracking-widest">Filter by Traffic</span>
                        <select id="filterTraffic" class="rounded-xl border border-main-green/30 bg-white p-3">
                            <option value="">All</option>
                            <?php foreach ($trafficOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1 text-main-green chivo-regular">
                        <span class="text-sm uppercase tracking-widest">Filter by Part of Day</span>
                        <select id="filterDay" class="rounded-xl border border-main-green/30 bg-white p-3">
                            <option value="">All</option>
                            <?php foreach ($dayParts as $key => $label): ?>
                                <option value="<?= h($key) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <?php if ($message): ?>
                    <div class="mb-6 rounded-2xl border border-main-green/40 bg-hover-green p-4 text-main-green chivo-regular">
                        <?= h($message) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-6 rounded-2xl border border-red-300 bg-red-50 p-4 text-red-800 chivo-regular">
                        <ul class="list-disc list-inside space-y-1">
                            <?php foreach ($errors as $error): ?>
                                <li><?= h($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($editingSession): ?>
                    <div class="mb-8 border border-main-green/30 rounded-2xl p-6 bg-white">
                        <h3 class="text-2xl text-main-green bbh-hegarty-regular mb-4">Editing Session</h3>
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="id" value="<?= h(id_to_token('session', (int)$editingSession['id'])) ?>">
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Date</span>
                                <input type="date" name="date" value="<?= h($editingSession['date']) ?>" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Mileage (km)</span>
                                <input type="number" step="0.1" min="0" name="mileage" value="<?= h($editingSession['mileage']) ?>" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Start Time</span>
                                <input type="time" name="start_time" value="<?= h($editingSession['start_time']) ?>" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>End Time</span>
                                <input type="time" name="end_time" value="<?= h($editingSession['end_time']) ?>" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Weather</span>
                                <select name="weather_id" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($weatherOptions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['weather_id'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Traffic</span>
                                <select name="traffic_id" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($trafficOptions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['traffic_id'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                    <?php
                        $selectedRoads = [];
                        if ($editingSession) {
                            $sid = (string)$editingSession['id'];
                            $selectedRoads = array_map('strval', $sessionRoads[$sid] ?? []);
                        }
                    ?>
                    <fieldset class="flex flex-col gap-2 text-main-green chivo-regular md:col-span-2">
                        <span>Road Surface (choose all that apply)</span>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            <?php foreach ($roadOptions as $key => $label): ?>
                                <?php $checked = in_array((string)$key, $selectedRoads, true); ?>
                                <label class="flex items-center gap-3 rounded-xl border border-main-green/30 bg-white px-4 py-3 hover:border-main-green cursor-pointer shadow-sm">
                                    <input type="checkbox" name="road_id[]" value="<?= $key ?>" <?= $checked ? 'checked' : '' ?> class="h-5 w-5 text-main-green focus:ring-main-green rounded">
                                    <span class="flex-1"><?= h($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Visibility</span>
                                <select name="visibility_id" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($visibilityOptions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['visibility_id'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Parking</span>
                                <select name="parking_id" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($parkingOptions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['parking_id'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Manoeuvre</span>
                                <select name="manoeuvre_id" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($manoeuvreOptions as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['manoeuvre_id'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="flex flex-col gap-2 text-main-green chivo-regular">
                                <span>Part of Day</span>
                                <select name="idOfDay" required class="rounded-xl border border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                                    <?php foreach ($dayParts as $key => $label): ?>
                                        <option value="<?= $key ?>" <?= ($editingSession['idOfDay'] == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <div class="md:col-span-2 flex items-center justify-end gap-3 pt-2">
                                <a href="experiences.php" class="text-main-green hover:underline chivo-regular">Cancel</a>
                                <button type="submit" name="update" class="bg-main-green text-hover-green px-6 py-3 rounded-2xl shadow-lg hover:translate-y-[-2px] transition duration-200 bbh-hegarty-regular uppercase tracking-widest">Save changes</button>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="overflow-x-auto">
                    <table class="w-full border-collapse rounded-xl overflow-hidden">
                        <thead class="bg-main-green text-yellow chivo-regular">
                            <tr>
                                <th class="py-3 px-4 text-left">Date</th>
                                <th class="py-3 px-4 text-left">Start</th>
                                <th class="py-3 px-4 text-left">End</th>
                                <th class="py-3 px-4 text-left">Mileage</th>
                                <th class="py-3 px-4 text-left">Weather</th>
                                <th class="py-3 px-4 text-left">Traffic</th>
                                <th class="py-3 px-4 text-left">Road</th>
                                <th class="py-3 px-4 text-left">Part of Day</th>
                                <th class="py-3 px-4 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sessions)): ?>
                                <tr class="bg-white">
                                    <td colspan="9" class="py-5 px-4 text-center text-main-green chivo-regular">No sessions recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($sessions as $session): ?>
                                <tr class="odd:bg-white even:bg-hover-green/60" data-weather="<?= h($session['weather_id']) ?>" data-traffic="<?= h($session['traffic_id']) ?>" data-day="<?= h($session['idOfDay']) ?>">
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($session['date']) ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($session['start_time']) ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($session['end_time']) ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($session['mileage']) ?> km</td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($weatherOptions[$session['weather_id']] ?? '—') ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($trafficOptions[$session['traffic_id']] ?? '—') ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular">
                                <?php
                                    $sid = (string)$session['id'];
                                    $roads = $sessionRoads[$sid] ?? [];
                                    $labels = array_map(fn($rid) => $roadOptions[$rid] ?? '—', $roads);
                                    echo h($labels ? implode(', ', $labels) : '—');
                        ?>
                    </td>
                            <td class="py-3 px-4 text-main-green chivo-regular"><?= h($dayParts[$session['idOfDay']] ?? '—') ?></td>
                            <td class="py-3 px-4 text-main-green chivo-regular">
                                        <?php $token = id_to_token('session', (int)$session['id']); ?>
                                        <a class="text-main-green underline mr-3" href="experiences.php?edit=<?= h($token) ?>">Edit</a>
                                        <a class="text-red-700 underline" href="experiences.php?delete=<?= h($token) ?>" onclick="return confirm('Delete this session?');">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <footer class="bg-main-green text-yellow chivo-regular text-center p-6">
        &copy; 2025 Driving Experience Recorder. All rights reserved.
    </footer>


<script>
    // mobile menu toggle
    const menuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    menuBtn?.addEventListener('click', () => mobileMenu?.classList.toggle('active'));
    document.addEventListener('click', (e) => {
        if (menuBtn && mobileMenu && !menuBtn.contains(e.target) && !mobileMenu.contains(e.target)) {
            mobileMenu.classList.remove('active');
        }
    });

    const rows = Array.from(document.querySelectorAll('tbody tr[data-weather]'));
    const wSel = document.getElementById('filterWeather');
    const tSel = document.getElementById('filterTraffic');
    const dSel = document.getElementById('filterDay');

    function applyFilters() {
        const w = wSel.value;
        const t = tSel.value;
        const d = dSel.value;
        rows.forEach(row => {
            const match = (!w || row.dataset.weather === w) &&
                          (!t || row.dataset.traffic === t) &&
                          (!d || row.dataset.day === d);
            row.style.display = match ? '' : 'none';
        });
    }

    [wSel, tSel, dSel].forEach(sel => sel?.addEventListener('change', applyFilters));
    applyFilters();
</script>
        <script src="index.js" ></script>
</body>
</html>
