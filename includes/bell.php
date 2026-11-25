<?php
/**
 * Notification bell shared by the team layout and the customer shop.
 * $user is the signed-in user. $alerts are extra "needs attention" counts: [label, count, link].
 */
function bell_html(array $user, array $alerts = []): string
{
    $unread     = notif_unread($user, 6);
    $unreadN    = notif_unread_count($user);
    $alertTotal = array_sum(array_column($alerts, 1));
    $total      = $unreadN + $alertTotal;

    ob_start(); ?>
    <div class="dd" data-dd data-poll="notify_poll.php" data-alerts="<?= (int)$alertTotal ?>" data-latest="<?= (int)($unread[0]['id'] ?? 0) ?>">
      <button class="tb-btn" type="button" data-dd-toggle aria-label="Notifications">
        <?= icon('bell') ?><span class="bell-count" data-bell-count <?= $total ? '' : 'hidden' ?>><?= $total > 99 ? '99+' : (int)$total ?></span>
      </button>
      <div class="dd-menu dd-wide">
        <h4>Notifications</h4>
        <?php foreach ($unread as $n): ?>
          <a class="dd-note" href="notifications.php?go=<?= (int)$n['id'] ?>">
            <b><?= e($n['title']) ?></b>
            <?php if ($n['message']): ?><span><?= e($n['message']) ?></span><?php endif; ?>
            <small><?= e(time_ago($n['created_at'])) ?></small>
          </a>
        <?php endforeach; ?>
        <?php foreach ($alerts as [$label, $n, $href]): ?>
          <a class="dd-item" href="<?= e($href) ?>"><span><?= e($label) ?></span><b><?= (int)$n ?></b></a>
        <?php endforeach; ?>
        <?php if (!$unread && !$alerts): ?><div class="dd-empty">You are all caught up.</div><?php endif; ?>
        <a class="dd-foot" href="notifications.php">See all notifications</a>
      </div>
    </div>
    <?php
    return (string)ob_get_clean();
}
