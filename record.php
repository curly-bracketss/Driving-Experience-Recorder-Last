<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/db.php';

$message = '';
$errors = [];

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

$sessionIdCol = sessionIdColumn($conn);
$junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
    ? 'idSession'
    : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);
$hasRoadCol = tableHasColumn($conn, 'drivingSession', 'road_id');
$dayField = tableHasColumn($conn, 'drivingSession', 'partOfDay')
    ? 'partOfDay'
    : (tableHasColumn($conn, 'drivingSession', 'idOfDay') ? 'idOfDay' : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        $errors[] = 'Invalid request token.';
    }

    $date         = trim($_POST['date'] ?? '');
    $start_time   = trim($_POST['start_time'] ?? '');
    $end_time     = trim($_POST['end_time'] ?? '');
    $mileage      = $_POST['mileage'] ?? '';
    $weather_id   = $_POST['weather_id'] ?? '';
    $traffic_id   = $_POST['traffic_id'] ?? '';
    $road_ids     = $_POST['road_id'] ?? [];
    if (!is_array($road_ids)) {
        $road_ids = [$road_ids];
    }
    $road_ids = array_values(array_unique(array_map('strval', $road_ids)));
    $partOfDay    = $_POST['partOfDay'] ?? '';
    $visibility_id = $_POST['visibility_id'] ?? '';
    $parking_id    = $_POST['parking_id'] ?? '';
    $manoeuvre_id  = $_POST['manoeuvre_id'] ?? '';

    if (!$date || !$start_time || !$end_time || $mileage === '' || !$weather_id || !$traffic_id || empty($road_ids) || !$partOfDay || !$visibility_id || !$parking_id || !$manoeuvre_id) {
        $errors[] = 'Please complete all fields.';
    }
    if (!$dayField) {
        $errors[] = 'Database is missing part of day column.';
    }

    $dtDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dtDate || $dtDate->format('Y-m-d') !== $date) {
        $errors[] = 'Invalid date format.';
    }

    if ($start_time && $end_time) {
        $startValid = DateTime::createFromFormat('H:i', $start_time);
        $endValid = DateTime::createFromFormat('H:i', $end_time);
        if (!$startValid || !$endValid || $startValid->format('H:i') !== $start_time || $endValid->format('H:i') !== $end_time) {
            $errors[] = 'Invalid time format.';
        } elseif (strtotime($start_time) >= strtotime($end_time)) {
            $errors[] = 'Start time must be earlier than end time.';
        }
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
    if ($partOfDay && !array_key_exists($partOfDay, $dayParts)) {
        $errors[] = 'Invalid part of day selection.';
    }

    if (empty($errors)) {
        $experience = DrivingExperience::fromForm(
            [
                'date' => $date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'mileage' => $mileage,
                'weather_id' => $weather_id,
                'traffic_id' => $traffic_id,
                'partOfDay' => $partOfDay,
                'visibility_id' => $visibility_id,
                'parking_id' => $parking_id,
                'manoeuvre_id' => $manoeuvre_id,
            ],
            $road_ids
        );

        try {
            $conn->begin_transaction();
            $nextId = null;
            $resId = $conn->query("SELECT COALESCE(MAX({$sessionIdCol}), 0) + 1 AS nextId FROM drivingSession FOR UPDATE");
            if ($resId) {
                $row = $resId->fetch_assoc();
                $nextId = (int) $row['nextId'];
                $resId->free();
            }
            if (!$nextId) {
                throw new Exception('Failed to compute next session id.');
            }
            $experience->id = $nextId;

            $columns = [$sessionIdCol, 'date', $dayField, 'start_time', 'end_time', 'mileage', 'weather_id', 'traffic_id', 'visibility_id', 'parking_id', 'manoeuvre_id'];
            $placeholders = array_fill(0, count($columns), '?');
            $types = 'issssdiiiii';
            $values = [
                $experience->id,
                $experience->date,
                $experience->partOfDay,
                $experience->startTime,
                $experience->endTime,
                $experience->mileage,
                $experience->weatherId,
                $experience->trafficId,
                $experience->visibilityId,
                $experience->parkingId,
                $experience->manoeuvreId,
            ];

            if ($hasRoadCol) {
                $columns[] = 'road_id';
                $placeholders[] = '?';
                $types .= 'i';
                $values[] = (int) ($experience->roadIds[0] ?? 0);
            }

            $sql = 'INSERT INTO drivingSession (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Prepare failed for drivingSession insert: ' . $conn->error);
            }

            $stmt->bind_param($types, ...$values);

            $stmt->execute();
            $stmt->close();

            if ($junctionCol) {
                $sql2 = "INSERT IGNORE INTO drivingSession_roadSurfaceType ({$junctionCol}, road_id) VALUES (?, ?)";
                $stmt2 = $conn->prepare($sql2);
                if (!$stmt2) {
                    throw new Exception('Prepare failed for drivingSession_roadSurfaceType insert: ' . $conn->error);
                }

                foreach ($experience->roadIds as $rid) {
                    $ridInt = (int)$rid;
                    $stmt2->bind_param('ii', $experience->id, $ridInt);
                    $stmt2->execute();
                }
                $stmt2->close();
            }

            $conn->commit();

            header('Location: experiences.php?message=' . urlencode('Driving experience saved successfully.'));
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Record Driving Experience</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
        href="https://fonts.googleapis.com/css2?family=BBH+Bogle&family=BBH+Hegarty&family=Lilita+One&family=Stack+Sans+Text:wght@200..700&family=Chivo:wght@400;600&display=swap"
        rel="stylesheet" />
    <style type="text/tailwindcss">
        @theme {
            --color-main-green:#1E3C14;
            --color-hover-green:#EEF3CB;
            --color-yellow:#FFF7C8
        }
        .chivo-regular {
            font-family: "Chivo", sans-serif;
            font-optical-sizing: auto;
            font-weight: 800;
            font-style: normal;
        }
        .bbh-hegarty-regular { font-family: "BBH Hegarty", sans-serif; font-weight: 400; }
       
        input, select, button { font-size: 1rem; }
        @media (max-width: 640px) {
            input, select, button { font-size: 1.05rem; }
        }
    </style>
</head>

<body class="bg-yellow">
  <header class="bg-[#FFF7EE] fixed w-full border-b border-main-green shadow-md z-50">
        <nav class="max-w-[1680px] mx-auto px-3 sm:px-6">
            <div class="flex justify-between items-center py-3 sm:py-4 lg:h-30">
                <h1 class="text-base sm:text-lg md:text-xl lg:text-2xl font-bold uppercase text-main-green bbh-hegarty-regular tracking-wider leading-tight">
                    Driving <br>Experience<br> Recorder
                </h1>
                
               
                <button id="mobile-menu-btn" class="lg:hidden text-main-green p-2 hover:bg-hover-green rounded-lg transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>

                <ul class="hidden lg:flex  xl:text-base 2xl:text-xl chivo-regular h-full text-main-green">
                    <li class="hover:bg-hover-green h-full flex items-center transition-colors">
                        <a href="form.php" class="tracking-wider px-2 xl:px-4 2xl:px-5 h-full flex items-center whitespace-nowrap">Home</a>
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
                        <a href="form.php" class="block tracking-wider px-3 py-2 sm:px-4 sm:py-3">Home</a>
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
    <main class="min-h-screen flex items-start pt-40 pb-16">
        <div class="max-w-5xl mx-auto w-full px-6">
            <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-10">
                <div class="flex items-center justify-between mb-6  flex-col md:flex-row gap-2">
                    <div class="flex flex-col gap-2 items-center justify-center md:justify-start md:items-start">
                        <p class="text-main-green uppercase tracking-[0.3em] text-sm chivo-regular">Log A Session</p>
                        <h2 class="text-3xl font-bold text-main-green md:text-left text-center bbh-hegarty-regular">Capture your driving experience</h2>
                    </div>
                    <a href="experiences.php" class="text-main-green hover:underline chivo-regular">Go to table →</a>
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

                <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Date</span>
                        <input type="date" name="date" value="<?= h($_POST['date'] ?? '') ?>" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3" />
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Mileage (km)</span>
                        <input type="number" step="0.1" min="0" name="mileage" value="<?= h($_POST['mileage'] ?? '') ?>" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3" />
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Start Time</span>
                        <input type="time" name="start_time" value="<?= h($_POST['start_time'] ?? '') ?>" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3" />
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>End Time</span>
                        <input type="time" name="end_time" value="<?= h($_POST['end_time'] ?? '') ?>" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3" />
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Weather</span>
                        <select name="weather_id" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select weather</option>
                            <?php foreach ($weatherOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['weather_id'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Traffic</span>
                        <select name="traffic_id" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select traffic</option>
                            <?php foreach ($trafficOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['traffic_id'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <fieldset class="md:col-span-2 flex flex-col gap-3 text-main-green chivo-regular">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <span>Road Surface (choose one or more)</span>
                            <div class="flex items-center gap-2 text-sm text-main-green/70">
                                <button type="button" id="roadSelectAll" class="px-3 py-1 rounded-lg border border-main-green/40 hover:border-main-green">Select all</button>
                                <button type="button" id="roadClearAll" class="px-3 py-1 rounded-lg border border-main-green/40 hover:border-main-green">Clear</button>
                                <span id="roadCount">(0 selected)</span>
                            </div>
                        </div>
                        <?php
                            $selectedRoads = $_POST['road_id'] ?? [];
                            if (!is_array($selectedRoads)) {
                                $selectedRoads = [$selectedRoads];
                            }
                            $selectedRoads = array_map('strval', $selectedRoads);
                        ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2" id="roadCheckboxes">
                            <?php foreach ($roadOptions as $key => $label): ?>
                                <?php $checked = in_array((string)$key, $selectedRoads, true); ?>
                                <label class="flex items-center gap-3 rounded-xl border border-main-green/30 bg-white px-4 py-3 hover:border-main-green cursor-pointer shadow-sm">
                                    <input type="checkbox" name="road_id[]" value="<?= h($key) ?>" <?= $checked ? 'checked' : '' ?> class="h-5 w-5 text-main-green focus:ring-main-green rounded road-choice">
                                    <span class="flex-1"><?= h($label) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Part of Day</span>
                        <select name="partOfDay" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select part of day</option>
                            <?php foreach ($dayParts as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['partOfDay'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Visibility</span>
                        <select name="visibility_id" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select visibility</option>
                            <?php foreach ($visibilityOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['visibility_id'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Parking Type</span>
                        <select name="parking_id" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select parking</option>
                            <?php foreach ($parkingOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['parking_id'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="flex flex-col gap-2 text-main-green chivo-regular">
                        <span>Manoeuvre</span>
                        <select name="manoeuvre_id" required class="rounded-xl border-main-green/30 focus:border-main-green focus:ring-main-green bg-white p-3">
                            <option value="">Select manoeuvre</option>
                            <?php foreach ($manoeuvreOptions as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= (($_POST['manoeuvre_id'] ?? '') == $key) ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <div class="md:col-span-2 flex flex-col md:flex-row gap-2 items-center justify-between pt-4">
                        <p class="text-main-green/80 text-center chivo-regular">Make sure your times are correct; we validate start vs end for you.</p>
                        <button type="submit" class="bg-main-green text-hover-green px-6 py-3 rounded-2xl shadow-lg hover:translate-y-[-2px] transition duration-200 chivo-regular uppercase tracking-widest">Save Session</button>
                    </div>
                </form>
            </div>
        </div>
</main>

<footer class="bg-main-green text-yellow chivo-regular text-center p-4">
    &copy; 2025 Driving Experience Recorder. All rights reserved.
</footer>

<script>
    const roadBoxes = Array.from(document.querySelectorAll('.road-choice'));
    const selectAllBtn = document.getElementById('roadSelectAll');
    const clearBtn = document.getElementById('roadClearAll');
    const countEl = document.getElementById('roadCount');

    function updateCount() {
        const checked = roadBoxes.filter(cb => cb.checked).length;
        if (countEl) countEl.textContent = `(${checked} selected)`;
    }

    selectAllBtn?.addEventListener('click', () => {
        roadBoxes.forEach(cb => cb.checked = true);
        updateCount();
    });
    clearBtn?.addEventListener('click', () => {
        roadBoxes.forEach(cb => cb.checked = false);
        updateCount();
    });
    roadBoxes.forEach(cb => cb.addEventListener('change', updateCount));
    updateCount();
</script>
<script  src="./index.js"></script>
</body>

</html>
