<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$DB_HOST = 'mysql-nazrin33.alwaysdata.net';
$DB_USER = 'nazrin33';
$DB_PASS = '*****';
$DB_NAME = 'nazrin33_hw';
$DB_PORT = 3306;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME, (int)$DB_PORT);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Database connection error: ' . $e->getMessage());
    http_response_code(500);
    echo 'Database connection failed. Please check server configuration and logs.';
    exit;
}

function fetchOptions(mysqli $conn, string $table, string $keyCol, string $labelCol): array
{
    $allowed = [
        'weather' => ['weather_id', 'weather_type'],
        'trafficCondition' => ['traffic_id', 'traffic_type'],
        'roadSurfaceType' => ['road_id', 'road_type'],
        'visibilityCondition' => ['visibility_id', 'visibility_type'],
        'parkingType' => ['parking_id', 'parking_type'],
        'manoeuvres' => ['manoeuvre_id', 'manoeuvre_type'],
    ];
    if (!isset($allowed[$table]) || $allowed[$table][0] !== $keyCol || $allowed[$table][1] !== $labelCol) {
        throw new InvalidArgumentException('Invalid lookup request');
    }

    $sql = "SELECT {$keyCol} AS k, {$labelCol} AS v FROM `{$table}` ORDER BY {$labelCol} ASC";
    $res = $conn->query($sql);
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(string)$row['k']] = $row['v'];
    }
    $res->free();
    return $out;
}

$weatherOptions     = fetchOptions($conn, 'weather', 'weather_id', 'weather_type');
$trafficOptions     = fetchOptions($conn, 'trafficCondition', 'traffic_id', 'traffic_type');
$roadOptions        = fetchOptions($conn, 'roadSurfaceType', 'road_id', 'road_type');
$visibilityOptions  = fetchOptions($conn, 'visibilityCondition', 'visibility_id', 'visibility_type');
$parkingOptions     = fetchOptions($conn, 'parkingType', 'parking_id', 'parking_type');
$manoeuvreOptions   = fetchOptions($conn, 'manoeuvres', 'manoeuvre_id', 'manoeuvre_type');

$dayParts = [
    'Morning'   => 'Morning',
    'Afternoon' => 'Afternoon',
    'Evening'   => 'Evening',
    'Night'     => 'Night',
];


function sessionIdColumn(mysqli $conn): string
{
    if (tableHasColumn($conn, 'drivingSession', 'id')) {
        return 'id';
    }
    if (tableHasColumn($conn, 'drivingSession', 'idSession')) {
        return 'idSession';
    }
    throw new RuntimeException('drivingSession table is missing an id column.');
}

function genToken(): string
{
    return bin2hex(random_bytes(16));
}

function id_to_token(string $type, int $id): string
{
    if (!isset($_SESSION['id_map'][$type])) {
        $_SESSION['id_map'][$type] = [];
        $_SESSION['token_to_id'][$type] = [];
    }
    if (!isset($_SESSION['id_map'][$type][$id])) {
        $token = genToken();
        $_SESSION['id_map'][$type][$id] = $token;
        $_SESSION['token_to_id'][$type][$token] = $id;
    }
    return $_SESSION['id_map'][$type][$id];
}

function token_to_id(string $type, string $token): ?int
{
    return $_SESSION['token_to_id'][$type][$token] ?? null;
}


function tableHasColumn(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $sql = 'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $cache[$key] = $stmt->num_rows > 0;
    $stmt->close();

    return $cache[$key];
}


function fetchSessions(mysqli $conn): array
{
    $idCol       = sessionIdColumn($conn);
    $hasPartOfDay = tableHasColumn($conn, 'drivingSession', 'partOfDay');
    $hasIdOfDay   = tableHasColumn($conn, 'drivingSession', 'idOfDay');
    $hasRoadCol   = tableHasColumn($conn, 'drivingSession', 'road_id');

    $select = [
        "{$idCol} AS id",
        'date',
        'start_time',
        'end_time',
        'mileage',
        'weather_id',
        'traffic_id',
        'visibility_id',
        'parking_id',
        'manoeuvre_id',
    ];

    if ($hasPartOfDay) {
        $select[] = 'partOfDay AS idOfDay';
    } elseif ($hasIdOfDay) {
        $select[] = 'idOfDay';
    } else {
        $select[] = "'' AS idOfDay";
    }

  
    $select[] = $hasRoadCol ? 'road_id' : 'NULL AS road_id';

    $sql = 'SELECT ' . implode(', ', $select) . " FROM drivingSession ORDER BY date DESC, start_time DESC";
    $res = $conn->query($sql);
    $sessions = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    if (!$hasRoadCol) {
        $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
            ? 'idSession'
            : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);
        if ($junctionCol) {
            $roadMap = [];
            $res2 = $conn->query("SELECT {$junctionCol} AS sid, MIN(road_id) AS road_id FROM drivingSession_roadSurfaceType GROUP BY {$junctionCol}");
            while ($row = $res2->fetch_assoc()) {
                $roadMap[(string)$row['sid']] = $row['road_id'];
            }
            $res2->free();

            foreach ($sessions as &$session) {
                $id = (string)$session['id'];
                if (isset($roadMap[$id])) {
                    $session['road_id'] = $roadMap[$id];
                }
            }
            unset($session);
        }
    }

    return $sessions;
}


function findSession(mysqli $conn, int $id): ?array
{
    $idCol       = sessionIdColumn($conn);
    $hasPartOfDay = tableHasColumn($conn, 'drivingSession', 'partOfDay');
    $hasIdOfDay   = tableHasColumn($conn, 'drivingSession', 'idOfDay');
    $hasRoadCol   = tableHasColumn($conn, 'drivingSession', 'road_id');

    $select = [
        "{$idCol} AS id",
        'date',
        'start_time',
        'end_time',
        'mileage',
        'weather_id',
        'traffic_id',
        'visibility_id',
        'parking_id',
        'manoeuvre_id',
    ];

    if ($hasPartOfDay) {
        $select[] = 'partOfDay AS idOfDay';
    } elseif ($hasIdOfDay) {
        $select[] = 'idOfDay';
    } else {
        $select[] = "'' AS idOfDay";
    }

    $select[] = $hasRoadCol ? 'road_id' : 'NULL AS road_id';

    $sql = 'SELECT ' . implode(', ', $select) . " FROM drivingSession WHERE {$idCol} = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc() ?: null;
    $stmt->close();

    if (!$session) {
        return null;
    }

    if (!$hasRoadCol) {
        $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
            ? 'idSession'
            : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);
        if ($junctionCol) {
            $sqlRoad = "SELECT road_id FROM drivingSession_roadSurfaceType WHERE {$junctionCol} = ? ORDER BY road_id ASC LIMIT 1";
            $stmtRoad = $conn->prepare($sqlRoad);
            $stmtRoad->bind_param('i', $id);
            $stmtRoad->execute();
            $stmtRoad->bind_result($roadId);
            if ($stmtRoad->fetch()) {
                $session['road_id'] = $roadId;
            }
            $stmtRoad->close();
        }
    }

    return $session;
}
