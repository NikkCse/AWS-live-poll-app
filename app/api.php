<?php
// JSON results for the live-updating bars.
// Also reports which server answered, so you can watch the load balancer
// spread requests across both Availability Zones.
require __DIR__ . '/lib.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$id = (string) ($_GET['id'] ?? '');
$poll = $id !== '' ? find_poll($id) : null;

if (!$poll) {
    http_response_code(404);
    echo json_encode(['error' => 'Poll not found']);
    exit;
}

$allowed = voted_option($id) !== null || !$poll['is_open'] || is_admin();
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['error' => 'Vote first to see results']);
    exit;
}

echo json_encode([
    'open'    => (bool) $poll['is_open'],
    'total'   => $poll['total'],
    'options' => array_map(fn($o) => ['id' => (int) $o['id'], 'votes' => (int) $o['votes']], $poll['options']),
    'server'  => instance_info(),
]);
