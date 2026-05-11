<?php

// Load .env file
$envFile = __DIR__ . '/.env';
if (!is_readable($envFile)) {
    http_response_code(500);
    die('Configuration file .env not found. Copy .env.example to .env and fill in values.');
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $key   = trim(substr($line, 0, $pos));
    $value = trim(substr($line, $pos + 1));
    if ($key !== '') {
        $_ENV[$key] = $value;
    }
}

$DB_HOST            = $_ENV['DB_HOST']             ?? '127.0.0.1';
$DB_PORT            = (int)($_ENV['DB_PORT']        ?? 3306);
$DB_NAME            = $_ENV['DB_NAME']             ?? '';
$DB_USER            = $_ENV['DB_USER']             ?? '';
$DB_PASS            = $_ENV['DB_PASS']             ?? '';
$DB_FALLBACK_IP     = $_ENV['DB_FALLBACK_IP']      ?? '';
$DB_CHARSET         = $_ENV['DB_CHARSET']          ?? 'utf8';
$DASHBOARD_REFRESH_MS = (int)($_ENV['DASHBOARD_REFRESH_MS'] ?? 60000);
$DASHBOARD_CACHE_TTL  = (int)($_ENV['DASHBOARD_CACHE_TTL']  ?? 60);

function db_connect(): mysqli {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_FALLBACK_IP, $DB_CHARSET;

    mysqli_report(MYSQLI_REPORT_OFF);

    $conn = mysqli_init();
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    $resolved_ip = gethostbyname($DB_HOST);

    $hosts = array_filter(array_unique([$resolved_ip, $DB_FALLBACK_IP]));

    $conn_final = null;
    $last_error = '';

    foreach ($hosts as $host) {
        $conn_final = @mysqli_real_connect(
            $conn,
            $host,
            $DB_USER,
            $DB_PASS,
            $DB_NAME,
            (int)$DB_PORT
        );

        if ($conn_final) {
            break;
        }

        $last_error = mysqli_connect_error();
    }

    if (!$conn_final) {
        http_response_code(500);
        die('Database connection failed.');
    }

    if (!mysqli_set_charset($conn, $DB_CHARSET)) {
        http_response_code(500);
        die('Unable to set charset.');
    }

    return $conn;
}

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money_fmt($amount): string {
    return number_format((float)$amount, 2);
}

function bill_status_thai(?string $status): string {
    $map = [
        'CloseBill'   => 'ชำระแล้ว',
        'OpenBill'    => 'เปิดบิล',
        'HoldBill'    => 'พักบิล',
        'Reserve'     => 'จองโต๊ะ',
        'Cancel Bill' => 'ยกเลิกบิล',
        'Void All'    => 'ยกเลิกทั้งบิล',
    ];
    $status = trim((string)$status);
    return $map[$status] ?? ($status === '' ? '-' : $status);
}

function payment_type_display(?string $displayName, ?string $payType): string {
    $displayName = trim((string)$displayName);
    $payType     = trim((string)$payType);
    return $displayName !== '' ? $displayName : ($payType !== '' ? $payType : '-');
}
