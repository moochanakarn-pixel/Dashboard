<?php
$DB_HOST = '127.0.0.1';
$DB_PORT = 3307;
$DB_NAME = 'yoppa';
$DB_USER = 'root';

$DB_PASS_ENC = 'cG9zcHduZXQ=';
$DB_PASS = base64_decode($DB_PASS_ENC);

$DB_CHARSET = 'utf8';
$DASHBOARD_REFRESH_MS = 60000;

function db_connect(): mysqli {
    global $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, $DB_CHARSET;

    mysqli_report(MYSQLI_REPORT_OFF);

    // 🔥 ตั้ง timeout กันค้าง
    $conn = mysqli_init();
    mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, 3);

    // 🔥 resolve domain → IP
    $resolved_ip = gethostbyname($DB_HOST);

    $hosts = [
        $resolved_ip,
        '58.11.59.157' // fallback IP
    ];

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
        die('Database connection failed: ' . $last_error);
    }

    if (!mysqli_set_charset($conn, $DB_CHARSET)) {
        http_response_code(500);
        die('Unable to set charset: ' . mysqli_error($conn));
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
        'CloseBill' => 'ชำระแล้ว',
        'OpenBill' => 'เปิดบิล',
        'HoldBill' => 'พักบิล',
        'Reserve' => 'จองโต๊ะ',
        'Cancel Bill' => 'ยกเลิกบิล',
        'Void All' => 'ยกเลิกทั้งบิล',
    ];
    $status = trim((string)$status);
    return $map[$status] ?? ($status === '' ? '-' : $status);
}

function payment_type_display(?string $displayName, ?string $payType): string {
    $displayName = trim((string)$displayName);
    $payType = trim((string)$payType);
    return $displayName !== '' ? $displayName : ($payType !== '' ? $payType : '-');
}