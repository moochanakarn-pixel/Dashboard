<?php
require __DIR__ . '/dashboard_config.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';
$token  = $_POST['token']  ?? '';

if (!$token || !in_array($action, ['heartbeat', 'release'], true)) {
    echo json_encode(['ok' => false]);
    exit;
}

$cacheDir = __DIR__ . '/cache';
$slotFile = $cacheDir . '/active_slots.json';
$slotTtl  = 90;

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0777, true);
}

$lock = fopen($slotFile . '.lock', 'c');
flock($lock, LOCK_EX);

$slots = [];
if (is_file($slotFile)) {
    $slots = json_decode(@file_get_contents($slotFile), true) ?? [];
}

$now = time();
foreach ($slots as $t => $ts) {
    if ($now - $ts >= $slotTtl) unset($slots[$t]);
}

if ($action === 'heartbeat' && isset($slots[$token])) {
    $slots[$token] = $now;
} elseif ($action === 'release') {
    unset($slots[$token]);
}

@file_put_contents($slotFile, json_encode($slots), LOCK_EX);
flock($lock, LOCK_UN);
fclose($lock);

echo json_encode(['ok' => true]);
