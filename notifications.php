<?php
require_once __DIR__ . '/config.php';
$me = auth();
if (!$me) { redirect('login.php'); }
$isCustomer = $me['role'] === 'customer';

// Open one notification: mark it read, then go to the page it is about.
if (g('go') !== '') {
    $pp = [];
    $w  = notif_where($me, $pp);
    $pp[] = (int)g('go');
    $n = qa("SELECT n.* FROM notifications n WHERE $w AND n.id = ?", $pp)[0] ?? null;
    if ($n) {
        notif_mark_read((int)$n['id'], (int)$me['id']);
        redirect(safe_next((string)$n['link'], 'notifications.php'));
    }
    redirect('notifications.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    notif_mark_all($me);
    flash('All notifications marked as read.');
    redirect('notifications.php');
}

$rows   = notif_all($me, 100);
$unread = count(array_filter($rows, fn($n) => !$n['is_read']));

if ($isCustomer) { $shopTitle = 'Updates'; $shopActive = 'orders'; require __DIR__ . '/includes/shop_header.php'; }
else             { $pageTitle = 'Notifications'; $active = ''; require __DIR__ . '/includes/header.php'; }
?>
<div class="card">
  <div class="card-head">
    <h2><?= $isCustomer ? 'Updates about your orders' : 'Notifications' ?> <?php if ($unread): ?><span class="badge s-ready"><?= (int)$unread ?> new</span><?php endif; ?></h2>
    <?php if ($unread): ?>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn btn-outline" type="submit">Mark all as read</button></form>
    <?php endif; ?>
  </div>
  <div class="note-list">
    <?php foreach ($rows as $n): ?>
      <a class="note <?= $n['is_read'] ? '' : 'is-new' ?>" href="notifications.php?go=<?= (int)$n['id'] ?>">
        <span class="note-dot"></span>
        <span class="note-body"><b><?= e($n['title']) ?></b><?php if ($n['message']): ?><span><?= e($n['message']) ?></span><?php endif; ?></span>
        <small><?= e(date('M j, g:i A', strtotime($n['created_at']))) ?></small>
      </a>
    <?php endforeach; if (!$rows): ?><div class="empty">Nothing here yet. New orders and updates show up here.</div><?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/' . ($isCustomer ? 'shop_footer' : 'footer') . '.php'; ?>
