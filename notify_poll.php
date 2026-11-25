<?php
// Small JSON endpoint: the bell asks it every few seconds whether something new arrived.
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

$u = auth();
if (!$u) { http_response_code(401); echo json_encode(['unread' => 0]); exit; }

$latest = notif_unread($u, 1)[0] ?? null;
echo json_encode([
    'unread' => notif_unread_count($u),
    'latest' => $latest ? ['id' => (int)$latest['id'], 'title' => $latest['title'], 'message' => (string)$latest['message'], 'link' => 'notifications.php?go=' . (int)$latest['id']] : null,
]);
