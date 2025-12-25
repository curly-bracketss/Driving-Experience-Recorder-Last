<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require __DIR__ . '/db.php';

$idCol       = sessionIdColumn($conn);
$hasPart     = tableHasColumn($conn, 'drivingSession', 'partOfDay');
$hasIdOfDay  = tableHasColumn($conn, 'drivingSession', 'idOfDay');
$hasRoadCol  = tableHasColumn($conn, 'drivingSession', 'road_id');
$dayField    = $hasPart ? 'd.partOfDay' : ($hasIdOfDay ? 'd.idOfDay' : "''");
$junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
    ? 'idSession'
    : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);

$statsSql = "
    SELECT COUNT(*) AS total_sessions,
           COALESCE(SUM(mileage), 0) AS total_mileage,
           COALESCE(AVG(CASE WHEN end_time > start_time THEN TIMESTAMPDIFF(MINUTE, start_time, end_time) END), 0) AS avg_duration_minutes
    FROM drivingSession
";
$statsRes = $conn->query($statsSql);
$stats = $statsRes ? $statsRes->fetch_assoc() : ['total_sessions' => 0, 'total_mileage' => 0, 'avg_duration_minutes' => 0];
if ($statsRes) {
    $statsRes->free();
}

$totalSessions = (int) ($stats['total_sessions'] ?? 0);
$totalMileage = (float) ($stats['total_mileage'] ?? 0);
$avgDurationMinutes = (int) round($stats['avg_duration_minutes'] ?? 0);

$weatherCounts = array_fill_keys(array_keys($weatherOptions), 0);
$weatherRes = $conn->query("SELECT w.weather_id, COUNT(d.{$idCol}) AS total FROM weather w LEFT JOIN drivingSession d ON d.weather_id = w.weather_id GROUP BY w.weather_id, w.weather_type");
if ($weatherRes) {
    while ($row = $weatherRes->fetch_assoc()) {
        $wid = (string) $row['weather_id'];
        if (isset($weatherCounts[$wid])) {
            $weatherCounts[$wid] = (int) $row['total'];
        }
    }
    $weatherRes->free();
}

$trafficCounts = array_fill_keys(array_keys($trafficOptions), 0);
$trafficRes = $conn->query("SELECT t.traffic_id, COUNT(d.{$idCol}) AS total FROM trafficCondition t LEFT JOIN drivingSession d ON d.traffic_id = t.traffic_id GROUP BY t.traffic_id, t.traffic_type");
if ($trafficRes) {
    while ($row = $trafficRes->fetch_assoc()) {
        $tid = (string) $row['traffic_id'];
        if (isset($trafficCounts[$tid])) {
            $trafficCounts[$tid] = (int) $row['total'];
        }
    }
    $trafficRes->free();
}

$dayCounts = array_fill_keys(array_keys($dayParts), 0);
$daySql = "SELECT {$dayField} AS day_key, COUNT(*) AS total FROM drivingSession d GROUP BY day_key";
$dayRes = $conn->query($daySql);
if ($dayRes) {
    while ($row = $dayRes->fetch_assoc()) {
        $key = $row['day_key'] ?? '';
        if (isset($dayCounts[$key])) {
            $dayCounts[$key] = (int) $row['total'];
        }
    }
    $dayRes->free();
}

$roadCounts = array_fill_keys(array_keys($roadOptions), 0);
if ($hasRoadCol) {
    $roadRes = $conn->query("SELECT road_id, COUNT(*) AS total FROM drivingSession GROUP BY road_id");
} elseif ($junctionCol) {
    $roadRes = $conn->query("SELECT r.road_id, COUNT(DISTINCT d.{$idCol}) AS total FROM roadSurfaceType r LEFT JOIN drivingSession_roadSurfaceType rst ON rst.road_id = r.road_id LEFT JOIN drivingSession d ON d.{$idCol} = rst.{$junctionCol} GROUP BY r.road_id");
} else {
    $roadRes = false;
}
if ($roadRes) {
    while ($row = $roadRes->fetch_assoc()) {
        $rid = (string) $row['road_id'];
        if (isset($roadCounts[$rid])) {
            $roadCounts[$rid] = (int) $row['total'];
        }
    }
    $roadRes->free();
}

$mileageByDate = [];
$mileageRes = $conn->query("SELECT date, SUM(mileage) AS total_mileage FROM drivingSession GROUP BY date ORDER BY date");
if ($mileageRes) {
    while ($row = $mileageRes->fetch_assoc()) {
        $mileageByDate[$row['date']] = (float) $row['total_mileage'];
    }
    $mileageRes->free();
}

$mileageDates = array_keys($mileageByDate);
sort($mileageDates);
$mileageSeries = array_map(fn($d) => round($mileageByDate[$d], 2), $mileageDates);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driving Summary</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=BBH+Bogle&family=BBH+Hegarty&family=Lilita+One&family=Stack+Sans+Text:wght@200..700&family=Chivo:wght@400;600&display=swap"
        rel="stylesheet">
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
        @media (max-width: 768px) {
            header nav { padding: 10px; }
            header nav h1 { font-size: 1.25rem; line-height: 1.2; }
            header nav ul { gap: 4px; flex-wrap: wrap; }
            .summary-shell { padding: 1.25rem !important; }
            .summary-shell h2 { font-size: 1.8rem; }
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

    <main class="min-h-screen pt-40 pb-16">
        <div class="max-w-6xl mx-auto px-6 space-y-10">
            <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-10">
                   <div class="flex items-center justify-between mb-6  flex-col md:flex-row gap-2">
                    <div class="flex flex-col gap-2 items-center justify-center md:justify-start md:items-start">
                        <p class="text-main-green uppercase tracking-[0.3em] text-sm chivo-regular">Summary</p>
                        
                        <h2 class="text-3xl font-bold text-main-green md:text-left text-center bbh-hegarty-regular">Driving session insights</h2>
                        <p class="text-main-green/80 chivo-regular mt-2 md:text-left text-center">Quick stats pulled from your recorded drives.</p>
                    </div>
                    <a href="record.php" class="text-main-green hover:underline chivo-regular md:text-left text-center">+ Log another session</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-white rounded-2xl border border-main-green/20 p-6 shadow-sm">
                        <p class="text-main-green/70 chivo-regular text-sm uppercase tracking-widest">Total Sessions</p>
                        <p class="text-4xl text-main-green bbh-hegarty-regular mt-2"><?= $totalSessions ?></p>
                        <p class="text-main-green/80 chivo-regular text-sm mt-1">Recorded drives</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-main-green/20 p-6 shadow-sm">
                        <p class="text-main-green/70 chivo-regular text-sm uppercase tracking-widest">Mileage</p>
                        <p class="text-4xl text-main-green bbh-hegarty-regular mt-2"><?= round($totalMileage, 1) ?> km</p>
                        <p class="text-main-green/80 chivo-regular text-sm mt-1">Distance covered</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-main-green/20 p-6 shadow-sm">
                        <p class="text-main-green/70 chivo-regular text-sm uppercase tracking-widest">Avg Duration</p>
                        <p class="text-4xl text-main-green bbh-hegarty-regular mt-2"><?= $avgDurationMinutes ?> min</p>
                        <p class="text-main-green/80 chivo-regular text-sm mt-1">Per session</p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-6">
                    <h3 class="text-2xl text-main-green bbh-hegarty-regular mb-4">Weather</h3>
                    <canvas id="weatherChart"></canvas>
                </div>
                <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-6">
                    <h3 class="text-2xl text-main-green bbh-hegarty-regular mb-4">Traffic</h3>
                    <canvas id="trafficChart"></canvas>
                </div>
                <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-6">
                    <h3 class="text-2xl text-main-green bbh-hegarty-regular mb-4">Road Surface</h3>
                    <canvas id="roadChart"></canvas>
                </div>
            </div>

            <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-2xl text-main-green bbh-hegarty-regular">Sessions by time of day</h3>
                        <p class="text-main-green/80 chivo-regular text-sm">See when you tend to drive the most.</p>
                    </div>
                    <a href="experiences.php" class="text-main-green hover:underline chivo-regular">View table →</a>
                </div>
                <canvas id="dayChart"></canvas>
            </div>

            <div class="bg-[#FDF7E3] border border-main-green/30 shadow-xl rounded-3xl p-6">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-2xl text-main-green bbh-hegarty-regular">Mileage over time</h3>
                        <p class="text-main-green/80 chivo-regular text-sm">Trend of total kilometers per day.</p>
                    </div>
                </div>
                <canvas id="mileageChart"></canvas>
            </div>
        </div>
    </main>

    <footer class="bg-main-green text-yellow chivo-regular text-center p-4">
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

        const palette = ["#1E3C14", "#C7D36F", "#FFB347", "#FF6F61", "#4ECDC4", "#6A5ACD", "#E76F51"];

        const weatherLabels = <?= json_encode(array_values($weatherOptions)) ?>;
        const weatherData   = <?= json_encode(array_values($weatherCounts)) ?>;

        const trafficLabels = <?= json_encode(array_values($trafficOptions)) ?>;
        const trafficData   = <?= json_encode(array_values($trafficCounts)) ?>;

        const roadLabels = <?= json_encode(array_values($roadOptions)) ?>;
        const roadData   = <?= json_encode(array_values($roadCounts)) ?>;

        const dayLabels = <?= json_encode(array_values($dayParts)) ?>;
        const dayData   = <?= json_encode(array_values($dayCounts)) ?>;
        const mileageLabels = <?= json_encode(array_values($mileageDates)) ?>;
        const mileageData   = <?= json_encode(array_values($mileageSeries)) ?>;

        function makeDoughnut(ctxId, labels, data) {
            new Chart(document.getElementById(ctxId), {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data,
                        backgroundColor: palette,
                        borderWidth: 0,
                    }]
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom', labels: { color: '#1E3C14', font: { family: "Chivo" } } }
                    }
                }
            });
        }

        function makeBar(ctxId, labels, data) {
            new Chart(document.getElementById(ctxId), {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        data,
                        backgroundColor: palette[0],
                        borderRadius: 12,
                    }]
                },
                options: {
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: { ticks: { color: '#1E3C14', font: { family: "Chivo" } } },
                        y: { ticks: { color: '#1E3C14', font: { family: "Chivo" } }, beginAtZero: true, precision: 0 }
                    }
                }
            });
        }

        makeDoughnut('weatherChart', weatherLabels, weatherData);
        makeDoughnut('trafficChart', trafficLabels, trafficData);
        makeDoughnut('roadChart', roadLabels, roadData);
        makeBar('dayChart', dayLabels, dayData);

        new Chart(document.getElementById('mileageChart'), {
            type: 'line',
            data: {
                labels: mileageLabels,
                datasets: [{
                    label: 'Kilometers per day',
                    data: mileageData,
                    borderColor: palette[0],
                    backgroundColor: palette[0] + '33',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: palette[2],
                }]
            },
            options: {
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: '#1E3C14', font: { family: "Chivo" } } },
                    y: { ticks: { color: '#1E3C14', font: { family: "Chivo" } }, beginAtZero: true }
                }
            }
        });
    </script>
            <script src="index.js" ></script>

</body>

</html>
