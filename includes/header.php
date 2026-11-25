<?php
$user      = auth();
$pageTitle = $pageTitle ?? 'Dashboard';
$active    = $active ?? '';
require_once __DIR__ . '/bell.php';

// Every role gets its own menu: only what that person does.
$role = $user['role'];
$navByRole = [
    'staff' => [
        'Orders' => [
            ['index.php',      'dashboard', 'Dashboard',        'home'],
            ['orders.php',     'orders',    'All Orders',       'clipboard'],
            ['order_form.php', 'neworder',  'New Walk-in Order', 'plus'],
        ],
        'Customers & Payments' => [
            ['customers.php', 'customers', 'Customers',            'users'],
            ['payments.php',  'payments',  'Payments & Receipts',  'receipt'],
        ],
    ],
    'manager' => [
        'Overview' => [
            ['index.php', 'dashboard', 'Dashboard', 'home'],
        ],
        'Stock' => [
            ['materials.php', 'materials', 'Materials',  'box'],
            ['stock_in.php',  'stockin',   'Stock In',   'download'],
            ['stock_out.php', 'stockout',  'Stock Out',  'upload'],
        ],
        'Money' => [
            ['expenses.php', 'expenses', 'Expenses', 'card'],
        ],
    ],
    'owner' => [
        'Overview' => [
            ['index.php', 'dashboard', 'Dashboard', 'home'],
        ],
        'Sales' => [
            
            ['expenses.php', 'expenses', 'Expenses', 'tag'],
        ],
        'Business' => [
            ['reports.php',  'reports',  'Reports',    'chart'],
            ['products.php', 'products', 'Price List', 'shirt'],
        ],
        'Administration' => [
            ['users.php', 'users', 'Users',     'users'],
            ['audit.php', 'audit', 'Audit Log', 'shield'],
        ],
    ],
];
$nav   = $navByRole[$role] ?? [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// "Needs attention" counts that are not notifications: they stay until the problem is fixed.
$alerts = [];
if ($role === 'staff') {
    $pb = []; $sb = scope_sql('o.branch_id', $pb);
    $nLate = (int)q1("SELECT COUNT(*) FROM orders o WHERE o.due_date < CURDATE() AND o.status NOT IN ('Completed','Cancelled') $sb", $pb);
    if ($nLate) { $alerts[] = ['Overdue orders', $nLate, 'orders.php?due=overdue']; }
}
if ($role === 'manager') {
    $pc = []; $sc2 = scope_sql('m.branch_id', $pc);
    $nLow = (int)q1("SELECT COUNT(*) FROM materials m WHERE m.status='Active' AND m.current_quantity <= m.reorder_level $sc2", $pc);
    if ($nLow) { $alerts[] = ['Materials low on stock', $nLow, 'materials.php?stock=low']; }
}
if ($role === 'owner') {
    $nPend = (int)q1("SELECT COUNT(*) FROM users WHERE status='Pending'");
    if ($nPend) { $alerts[] = ['Registrations to approve', $nPend, 'users.php']; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48'%3E%3Ccircle cx='24' cy='24' r='22' fill='%231d4ed8'/%3E%3C/svg%3E">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">

      <div class="brand-text"><strong>Own<b>style</b></strong><span>Sublimation Management<br>&amp; Inventory</span></div>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $section => $links): ?>
        <div class="nav-section"><?= e($section) ?></div>
        <?php foreach ($links as [$href, $key, $label, $ico]): ?>
          <a href="<?= e($href) ?>" class="nav-link <?= $active === $key ? 'active' : '' ?>">
            <?= icon($ico) ?><span><?= e($label) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="side-user">
      <div class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div>
      <div class="who"><strong><?= e($user['name']) ?></strong><span><?= e(ucfirst($user['role'])) ?></span></div>
      <a href="logout.php" class="logout" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out?" data-confirm-yes="Yes, log out" data-confirm-no="Stay signed in" title="Sign out"><?= icon('logout') ?></a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-btn" data-toggle-sidebar aria-label="Toggle menu"><?= icon('menu') ?></button>
      <h1><?= e($pageTitle) ?></h1>
      <span class="topbar-date"><?= e(date('l, F j, Y')) ?></span>
      <?= bell_html($user, $alerts) ?>
      <div class="dd" data-dd>
        <button class="user-btn" type="button" data-dd-toggle><span class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></span><span><?= e(ucfirst($user['role'])) ?></span><svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
        <div class="dd-menu minw-210">
          <div class="dd-empty"><strong class="c-white"><?= e($user['name']) ?></strong><br><?= e($user['email']) ?></div>
          <a class="dd-item" href="logout.php" data-confirm="Are you sure you want to log out?" data-confirm-title="Log out?" data-confirm-yes="Yes, log out" data-confirm-no="Stay signed in"><span>Logout</span><?= icon('logout') ?></a>
        </div>
      </div>
    </header>
    <main class="content">
      <?php if ($flash): ?>
        <div class="flash flash-<?= e($flash[1]) ?>" data-flash><?= e($flash[0]) ?></div>
      <?php endif; ?>
