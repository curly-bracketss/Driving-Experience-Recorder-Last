<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['anon_map'])) {
    $_SESSION['anon_map'] = [];
}
if (!isset($_SESSION['anon_reverse'])) {
    $_SESSION['anon_reverse'] = [];
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

require_once __DIR__ . '/src/DrivingExperience.php';

$DB_HOST = 'mysql-nazrin33.alwaysdata.net';
$DB_USER = 'nazrin33';
$DB_PASS = '******';
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
    if (!isset($_SESSION['anon_map'][$type])) {
        $_SESSION['anon_map'][$type] = [];
    }
    if (!isset($_SESSION['anon_reverse'][$type])) {
        $_SESSION['anon_reverse'][$type] = [];
    }
    if (!isset($_SESSION['anon_reverse'][$type][$id])) {
        $token = genToken();
        $_SESSION['anon_reverse'][$type][$id] = $token;
        $_SESSION['anon_map'][$type][$token] = $id;
    }
    return $_SESSION['anon_reverse'][$type][$id];
}

function token_to_id(string $type, string $token): ?int
{
    return $_SESSION['anon_map'][$type][$token] ?? null;
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

    $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
        ? 'idSession'
        : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);

    $dayExpr = $hasPartOfDay ? 'd.partOfDay' : ($hasIdOfDay ? 'd.idOfDay' : "''");
    $roadSelect = $hasRoadCol
        ? 'CAST(d.road_id AS CHAR) AS road_ids'
        : ($junctionCol ? "GROUP_CONCAT(DISTINCT rst.road_id ORDER BY rst.road_id) AS road_ids" : "'' AS road_ids");
    $roadJoin = (!$hasRoadCol && $junctionCol) ? "LEFT JOIN drivingSession_roadSurfaceType rst ON rst.{$junctionCol} = d.{$idCol}" : '';

    $sql = "
        SELECT d.{$idCol} AS id,
               d.date,
               d.start_time,
               d.end_time,
               d.mileage,
               d.weather_id,
               d.traffic_id,
               d.visibility_id,
               d.parking_id,
               d.manoeuvre_id,
               {$dayExpr} AS idOfDay,
               {$roadSelect}
        FROM drivingSession d
        {$roadJoin}
        GROUP BY d.{$idCol}, d.date, d.start_time, d.end_time, d.mileage, d.weather_id, d.traffic_id, d.visibility_id, d.parking_id, d.manoeuvre_id, idOfDay" . ($roadJoin ? ', rst.' . $junctionCol : '') . "
        ORDER BY d.date DESC, d.start_time DESC
    ";

    $res = $conn->query($sql);
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    return array_map(fn(array $row) => DrivingExperience::fromRow($row), $rows);
}


function findSession(mysqli $conn, int $id): ?DrivingExperience
{
    $idCol       = sessionIdColumn($conn);
    $hasPartOfDay = tableHasColumn($conn, 'drivingSession', 'partOfDay');
    $hasIdOfDay   = tableHasColumn($conn, 'drivingSession', 'idOfDay');
    $hasRoadCol   = tableHasColumn($conn, 'drivingSession', 'road_id');

    $junctionCol = tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'idSession')
        ? 'idSession'
        : (tableHasColumn($conn, 'drivingSession_roadSurfaceType', 'session_id') ? 'session_id' : null);

    $dayExpr = $hasPartOfDay ? 'd.partOfDay' : ($hasIdOfDay ? 'd.idOfDay' : "''");
    $roadSelect = $hasRoadCol
        ? 'CAST(d.road_id AS CHAR) AS road_ids'
        : ($junctionCol ? "GROUP_CONCAT(DISTINCT rst.road_id ORDER BY rst.road_id) AS road_ids" : "'' AS road_ids");
    $roadJoin = (!$hasRoadCol && $junctionCol) ? "LEFT JOIN drivingSession_roadSurfaceType rst ON rst.{$junctionCol} = d.{$idCol}" : '';

    $sql = "
        SELECT d.{$idCol} AS id,
               d.date,
               d.start_time,
               d.end_time,
               d.mileage,
               d.weather_id,
               d.traffic_id,
               d.visibility_id,
               d.parking_id,
               d.manoeuvre_id,
               {$dayExpr} AS idOfDay,
               {$roadSelect}
        FROM drivingSession d
        {$roadJoin}
        WHERE d.{$idCol} = ?
        GROUP BY d.{$idCol}, d.date, d.start_time, d.end_time, d.mileage, d.weather_id, d.traffic_id, d.visibility_id, d.parking_id, d.manoeuvre_id, idOfDay" . ($roadJoin ? ', rst.' . $junctionCol : '') . "
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc() ?: null;
    $stmt->close();

    if (!$session) {
        return null;
    }

    return DrivingExperience::fromRow($session);
}
