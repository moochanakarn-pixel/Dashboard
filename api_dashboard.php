<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
ob_start();

require __DIR__ . '/dashboard_config.php';

mysqli_report(MYSQLI_REPORT_OFF);

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$forceRefresh = isset($_GET['force']) && $_GET['force'] === '1';
$cacheDir = __DIR__ . '/cache';
$cacheKey = preg_replace('/[^0-9\-]/', '', $date);
$cacheFile = $cacheDir . '/dashboard_' . $cacheKey . '.json';
$cacheTtl = isset($DASHBOARD_CACHE_TTL) ? (int)$DASHBOARD_CACHE_TTL : 60;
$cacheTtl = max(0, $cacheTtl);

if (!$forceRefresh && $cacheTtl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile) < $cacheTtl)) {
    readfile($cacheFile);
    exit;
}

$data = [
    'date' => $date,
    'summary' => [
        'sales_total' => 0,
        'bill_count' => 0,
        'avg_bill' => 0,
        'guest_count' => 0,
        'discount_total' => 0,
        'discount_bill_count' => 0,
    ],
    'sale_modes' => [],
    'hourly' => [],
    'recent_bills' => [],
    'payment_types' => [],
    'top_products' => [],
    'discount_summary' => [],
    'void_summary' => [
        'bill_count' => 0,
        'total_voided' => 0.0,
    ],
    'void_bills' => [],
    'error' => null,
];

function set_error_once(array &$data, string $message): void {
    if (empty($data['error'])) {
        $data['error'] = $message;
    }
}

function safe_prepare(mysqli $conn, array &$data, string $sql): ?mysqli_stmt {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        set_error_once($data, 'Prepare failed: ' . $conn->error);
        return null;
    }
    return $stmt;
}

function safe_execute(mysqli_stmt $stmt, array &$data): ?mysqli_result {
    if (!$stmt->execute()) {
        set_error_once($data, 'Execute failed: ' . $stmt->error);
        return null;
    }
    $result = $stmt->get_result();
    if ($result === false) {
        set_error_once($data, 'Get result failed: ' . $stmt->error);
        return null;
    }
    return $result;
}

function is_valid_utf8_string(string $value): bool {
    if (function_exists('mb_check_encoding')) {
        return mb_check_encoding($value, 'UTF-8');
    }
    return preg_match('//u', $value) === 1;
}

function convert_to_utf8_string(string $value): string {
    if ($value === '' || is_valid_utf8_string($value)) {
        return $value;
    }

    $encodings = ['TIS-620', 'Windows-874', 'ISO-8859-1', 'UTF-8'];

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($value, 'UTF-8', implode(',', $encodings));
        if (is_string($converted) && $converted !== '') {
            return $converted;
        }
    }

    if (function_exists('iconv')) {
        foreach ($encodings as $encoding) {
            $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }
    }

    return $value;
}

function normalize_utf8($mixed) {
    if (is_array($mixed)) {
        foreach ($mixed as $key => $value) {
            $mixed[$key] = normalize_utf8($value);
        }
        return $mixed;
    }

    if (is_string($mixed)) {
        return convert_to_utf8_string($mixed);
    }

    return $mixed;
}

try {
    $conn = db_connect();

    $paidWhere = "
        ot.Deleted = 0
        AND ot.SaleDate = ?
        AND ot.ReceiptPayPrice > 0
        AND ot.TransactionStatusID NOT IN (5, 8, 12, 13, 16)
        AND (
            ot.TransactionStatusID = 2
            OR EXISTS (
                SELECT 1
                FROM ordertransactionstatus ots
                WHERE ots.TransactionStatusID = ot.TransactionStatusID
                  AND ots.Description = 'CloseBill'
            )
        )
    ";

    $voidWhere = "
        ot.Deleted = 0
        AND ot.SaleDate = ?
        AND ot.TransactionStatusID IN (5, 8, 12, 13, 16)
    ";

    $sqlSummary = "
        SELECT
            COALESCE(SUM(ot.ReceiptPayPrice), 0) AS sales_total,
            COUNT(*) AS bill_count,
            COALESCE(AVG(ot.ReceiptPayPrice), 0) AS avg_bill,
            COALESCE(SUM(ot.NoCustomer), 0) AS guest_count
        FROM ordertransaction ot
        WHERE $paidWhere
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlSummary)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            if ($row = $res->fetch_assoc()) {
                $data['summary']['sales_total'] = (float)($row['sales_total'] ?? 0);
                $data['summary']['bill_count'] = (int)($row['bill_count'] ?? 0);
                $data['summary']['avg_bill'] = (float)($row['avg_bill'] ?? 0);
                $data['summary']['guest_count'] = (int)($row['guest_count'] ?? 0);
            }
        }
        $stmt->close();
    }

    $sqlModes = "
        SELECT
            COALESCE(NULLIF(sm.SaleModeName, ''), CONCAT('Mode ', ot.SaleMode)) AS sale_mode_name,
            COUNT(*) AS total_bills,
            COALESCE(SUM(ot.ReceiptPayPrice), 0) AS total_sales
        FROM ordertransaction ot
        LEFT JOIN salemode sm ON sm.SaleModeID = ot.SaleMode
        WHERE $paidWhere
        GROUP BY ot.SaleMode, sm.SaleModeName
        ORDER BY total_sales DESC, total_bills DESC
        LIMIT 10
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlModes)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            while ($row = $res->fetch_assoc()) {
                $data['sale_modes'][] = [
                    'sale_mode_name' => $row['sale_mode_name'] ?? '-',
                    'total_bills' => (int)($row['total_bills'] ?? 0),
                    'total_sales' => (float)($row['total_sales'] ?? 0),
                ];
            }
        }
        $stmt->close();
    }

    $sqlHourly = "
        SELECT
            LPAD(HOUR(COALESCE(ot.PaidTime, ot.CloseTime, ot.UpdateDate, ot.OpenTime)), 2, '0') AS hour_label,
            COUNT(*) AS total_bills,
            COALESCE(SUM(ot.ReceiptPayPrice), 0) AS total_sales
        FROM ordertransaction ot
        WHERE $paidWhere
        GROUP BY HOUR(COALESCE(ot.PaidTime, ot.CloseTime, ot.UpdateDate, ot.OpenTime))
        ORDER BY hour_label ASC
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlHourly)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            while ($row = $res->fetch_assoc()) {
                $data['hourly'][] = [
                    'hour_label' => (($row['hour_label'] ?? '00') . ':00'),
                    'total_bills' => (int)($row['total_bills'] ?? 0),
                    'total_sales' => (float)($row['total_sales'] ?? 0),
                ];
            }
        }
        $stmt->close();
    }

    $sqlPay = "
        SELECT
            pd.PayTypeID,
            pt.PayType,
            pt.DisplayName,
            COUNT(*) AS payment_rows,
            COUNT(DISTINCT CONCAT(pd.TransactionID, '-', pd.ComputerID)) AS bill_count,
            COALESCE(SUM(pd.Amount), 0) AS total_amount
        FROM paydetail pd
        INNER JOIN ordertransaction ot
            ON ot.TransactionID = pd.TransactionID
           AND ot.ComputerID = pd.ComputerID
        LEFT JOIN paytype pt
            ON pt.TypeID = pd.PayTypeID
        WHERE $paidWhere
        GROUP BY pd.PayTypeID, pt.PayType, pt.DisplayName
        ORDER BY total_amount DESC, bill_count DESC
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlPay)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            while ($row = $res->fetch_assoc()) {
                $data['payment_types'][] = [
                    'pay_type_id' => (int)($row['PayTypeID'] ?? 0),
                    'pay_type_name' => payment_type_display($row['DisplayName'] ?? '', $row['PayType'] ?? ''),
                    'payment_rows' => (int)($row['payment_rows'] ?? 0),
                    'bill_count' => (int)($row['bill_count'] ?? 0),
                    'total_amount' => (float)($row['total_amount'] ?? 0),
                ];
            }
        }
        $stmt->close();
    }

    $sqlProducts = "
        SELECT
            COALESCE(NULLIF(od.OtherFoodName, ''), NULLIF(p.ProductName, ''), NULLIF(p.ProductName1, ''), CONCAT('Product #', od.ProductID)) AS product_name,
            SUM(od.Amount) AS qty_sold,
            COALESCE(SUM(odd.SalePrice), 0) AS total_sales
        FROM ordertransaction ot
        INNER JOIN orderdetail od
            ON ot.TransactionID = od.TransactionID
           AND ot.ComputerID = od.ComputerID
        INNER JOIN orderdiscountdetail odd
            ON odd.OrderDetailID = od.OrderDetailID
           AND odd.TransactionID = od.TransactionID
           AND odd.ComputerID = od.ComputerID
        LEFT JOIN products p
            ON p.ProductID = od.ProductID
        WHERE $paidWhere
          AND od.Deleted = 0
          AND od.ProductSetType NOT IN (-1, -3, 14, 16)
        GROUP BY product_name
        ORDER BY total_sales DESC, qty_sold DESC
        LIMIT 10
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlProducts)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            while ($row = $res->fetch_assoc()) {
                $data['top_products'][] = [
                    'product_name' => $row['product_name'] ?? '-',
                    'qty_sold' => (float)($row['qty_sold'] ?? 0),
                    'total_sales' => (float)($row['total_sales'] ?? 0),
                ];
            }
        }
        $stmt->close();
    }

    $sqlDiscount = "
        SELECT
            COALESCE(NULLIF(ppg.PromotionPriceName, ''), 'Other Discount') AS discount_name,
            COUNT(DISTINCT CONCAT(opd.TransactionID, '-', opd.ComputerID)) AS bill_count,
            COALESCE(SUM(opd.DiscountPrice), 0) AS total_discount
        FROM orderpromotiondiscountdetail opd
        INNER JOIN ordertransaction ot
            ON ot.TransactionID = opd.TransactionID
           AND ot.ComputerID = opd.ComputerID
        INNER JOIN orderdetail od
            ON od.TransactionID = opd.TransactionID
           AND od.ComputerID = opd.ComputerID
           AND od.OrderDetailID = opd.OrderDetailID
        LEFT JOIN promotionpricegroup ppg
            ON ppg.PriceGroupID = opd.PriceGroupID
        WHERE $paidWhere
          AND od.OrderStatusID IN (1, 2, 5)
          AND COALESCE(opd.DiscountPrice, 0) > 0
        GROUP BY COALESCE(NULLIF(ppg.PromotionPriceName, ''), 'Other Discount')
        ORDER BY total_discount DESC, bill_count DESC
        LIMIT 10
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlDiscount)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            $discountTotal = 0.0;
            $discountBillCount = 0;
            $salesTotal = (float)$data['summary']['sales_total'];
            while ($row = $res->fetch_assoc()) {
                $discountTotal += (float)($row['total_discount'] ?? 0);
                $discountBillCount += (int)($row['bill_count'] ?? 0);
                $pct = $salesTotal > 0 ? (((float)$row['total_discount']) / $salesTotal) * 100 : 0;
                $data['discount_summary'][] = [
                    'discount_name' => $row['discount_name'] ?? '-',
                    'bill_count' => (int)($row['bill_count'] ?? 0),
                    'total_discount' => (float)($row['total_discount'] ?? 0),
                    'pct_of_sales' => $pct,
                ];
            }
            $data['summary']['discount_total'] = $discountTotal;
            $data['summary']['discount_bill_count'] = $discountBillCount;
        }
        $stmt->close();
    }

    $sqlRecent = "
        SELECT
            ot.TransactionID,
            ot.ComputerID,
            ot.ReceiptID,
            ot.QueueName,
            ot.TransactionName,
            ot.NoCustomer,
            ot.ReceiptPayPrice,
            ot.PaidTime,
            ot.CloseTime,
            COALESCE(NULLIF(sm.SaleModeName, ''), CONCAT('Mode ', ot.SaleMode)) AS sale_mode_name,
            COALESCE(ots.Description, '-') AS bill_status,
            COALESCE(NULLIF(tn.TableName, ''), CONCAT('โต๊ะ ', tfo.TableNo), CONCAT('โต๊ะ ', ot.TableID), '-') AS table_name
        FROM ordertransaction ot
        LEFT JOIN salemode sm ON sm.SaleModeID = ot.SaleMode
        LEFT JOIN ordertransactionstatus ots ON ots.TransactionStatusID = ot.TransactionStatusID
        LEFT JOIN tablenofororder tfo
            ON tfo.TransactionID = ot.TransactionID
           AND tfo.ComputerID = ot.ComputerID
           AND tfo.HistoryTrack = 0
        LEFT JOIN tableno tn ON tn.TableID = tfo.TableNo
        WHERE $paidWhere
        ORDER BY COALESCE(ot.PaidTime, ot.CloseTime, ot.UpdateDate, ot.OpenTime) DESC
        LIMIT 20
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlRecent)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            while ($row = $res->fetch_assoc()) {
                $data['recent_bills'][] = [
                    'transaction_id' => (int)($row['TransactionID'] ?? 0),
                    'computer_id' => (int)($row['ComputerID'] ?? 0),
                    'receipt_id' => (int)($row['ReceiptID'] ?? 0),
                    'queue_name' => $row['QueueName'] ?? '',
                    'transaction_name' => $row['TransactionName'] ?? '',
                    'table_name' => $row['table_name'] ?? '-',
                    'sale_mode_name' => $row['sale_mode_name'] ?? '-',
                    'bill_status' => bill_status_thai($row['bill_status'] ?? '-'),
                    'no_customer' => (int)($row['NoCustomer'] ?? 0),
                    'receipt_pay_price' => (float)($row['ReceiptPayPrice'] ?? 0),
                    'paid_time' => $row['PaidTime'] ?? '',
                    'close_time' => $row['CloseTime'] ?? '',
                ];
            }
        }
        $stmt->close();
    }

    $sqlVoid = "
        SELECT
            ot.TransactionID,
            ot.ComputerID,
            ot.ReceiptID,
            ot.ReceiptPayPrice,
            ot.VoidTime,
            ot.VoidReason,
            ot.PaidTime,
            ot.CloseTime,
            COALESCE(NULLIF(sm.SaleModeName, ''), CONCAT('Mode ', ot.SaleMode)) AS sale_mode_name,
            ots.Description AS status_description,
            COALESCE(NULLIF(tn.TableName, ''), CONCAT('โต๊ะ ', tfo.TableNo), CONCAT('โต๊ะ ', ot.TableID), '-') AS table_name
        FROM ordertransaction ot
        LEFT JOIN salemode sm ON sm.SaleModeID = ot.SaleMode
        LEFT JOIN ordertransactionstatus ots ON ots.TransactionStatusID = ot.TransactionStatusID
        LEFT JOIN tablenofororder tfo
            ON tfo.TransactionID = ot.TransactionID
           AND tfo.ComputerID = ot.ComputerID
           AND tfo.HistoryTrack = 0
        LEFT JOIN tableno tn ON tn.TableID = tfo.TableNo
        WHERE $voidWhere
        ORDER BY COALESCE(ot.VoidTime, ot.CloseTime, ot.UpdateDate, ot.OpenTime) DESC
        LIMIT 20
    ";
    if ($stmt = safe_prepare($conn, $data, $sqlVoid)) {
        $stmt->bind_param('s', $date);
        if ($res = safe_execute($stmt, $data)) {
            $voidTotal = 0.0;
            $voidCount = 0;
            while ($row = $res->fetch_assoc()) {
                $voidTotal += (float)($row['ReceiptPayPrice'] ?? 0);
                $voidCount++;
                $data['void_bills'][] = [
                    'transaction_id'    => (int)($row['TransactionID'] ?? 0),
                    'computer_id'       => (int)($row['ComputerID'] ?? 0),
                    'receipt_id'        => (int)($row['ReceiptID'] ?? 0),
                    'receipt_pay_price' => (float)($row['ReceiptPayPrice'] ?? 0),
                    'void_time'         => $row['VoidTime'] ?? '',
                    'void_reason'       => $row['VoidReason'] ?? '',
                    'paid_time'         => $row['PaidTime'] ?? '',
                    'close_time'        => $row['CloseTime'] ?? '',
                    'sale_mode_name'    => $row['sale_mode_name'] ?? '-',
                    'status_description'=> $row['status_description'] ?? '-',
                    'table_name'        => $row['table_name'] ?? '-',
                ];
            }
            $data['void_summary']['bill_count']   = $voidCount;
            $data['void_summary']['total_voided'] = $voidTotal;
        }
        $stmt->close();
    }

    $conn->close();
} catch (Throwable $e) {
    set_error_once($data, $e->getMessage());
}

$bufferOutput = trim((string)ob_get_clean());
if ($bufferOutput !== '') {
    set_error_once($data, 'Unexpected output: ' . preg_replace('/\s+/', ' ', $bufferOutput));
}

$data = normalize_utf8($data);

$json = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

if ($json === false) {
    $fallback = [
        'date' => $date,
        'summary' => [
            'sales_total' => 0,
            'bill_count' => 0,
            'avg_bill' => 0,
            'guest_count' => 0,
            'discount_total' => 0,
            'discount_bill_count' => 0,
        ],
        'sale_modes' => [],
        'hourly' => [],
        'recent_bills' => [],
        'payment_types' => [],
        'top_products' => [],
        'discount_summary' => [],
        'error' => 'JSON encode failed: ' . json_last_error_msg(),
    ];
    echo json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($cacheTtl > 0) {
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    if (is_dir($cacheDir) && is_writable($cacheDir)) {
        @file_put_contents($cacheFile, $json, LOCK_EX);
    }
}

echo $json;
exit;
