<?php
// Each role lands on its own dashboard. Customers use "My Orders" (account.php).
require_once __DIR__ . '/config.php';
require_login();
$user = auth();
if ($user['role'] === 'customer') { redirect('account.php'); }

$pageTitle = 'Dashboard';
$active    = 'dashboard';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/dash_' . $user['role'] . '.php';
require __DIR__ . '/includes/footer.php';
