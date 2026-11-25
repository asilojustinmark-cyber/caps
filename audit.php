<?php
require_once __DIR__ . '/config.php';
require_login(['owner']);
$pageTitle = 'Audit Log';
$active    = 'audit';

$fEntity = g('entity'); $fUser = g('user'); $from = g('from'); $to = g('to'); $q = g('q');
$w = '1=1'; $pp = [];
if ($fEntity !== '') { $w .= ' AND entity = ?'; $pp[] = $fEntity; }
if ($fUser !== '')   { $w .= ' AND user_name LIKE ?'; $pp[] = "%$fUser%"; }
if ($from !== '')    { $w .= ' AND DATE(created_at) >= ?'; $pp[] = $from; }
if ($to !== '')      { $w .= ' AND DATE(created_at) <= ?'; $pp[] = $to; }
if ($q !== '')       { $w .= ' AND details LIKE ?'; $pp[] = "%$q%"; }

$rows = qa("SELECT * FROM audit_logs WHERE $w ORDER BY id DESC LIMIT 300", $pp);
$entities = array_column(qa("SELECT DISTINCT entity FROM audit_logs ORDER BY entity"), 'entity');

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head"><h2>Activity History</h2><span class="muted">Showing the latest <?= count($rows) ?> matching entries</span></div>
  <div class="card-body pb-0">
    <form method="get" class="filters">
      <div><label>Search details</label><input type="search" name="q" value="<?= e($q) ?>" placeholder="e.g. OS-2026-00001"></div>
      <div><label>Record type</label><select name="entity"><option value="">All</option>
        <?php foreach ($entities as $en): ?><option <?= $fEntity === $en ? 'selected' : '' ?>><?= e($en) ?></option><?php endforeach; ?></select></div>
      <div><label>User</label><input name="user" value="<?= e($fUser) ?>" placeholder="Name"></div>
      <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
      <div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
      <button class="btn btn-outline" type="submit">Filter</button>
      <a class="btn btn-outline" href="audit.php">Reset</a>
    </form>
  </div>
  <div class="table-wrap mt-14"><table class="table">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
        <td><strong><?= e($r['user_name']) ?></strong></td>
        <td><span class="badge"><?= e($r['action']) ?></span></td>
        <td class="nowrap"><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td>
        <td><?= e($r['details']) ?></td>
        <td class="muted"><?= e($r['ip_address']) ?></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">No activity found.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
