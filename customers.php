<?php
require_once __DIR__ . '/config.php';
require_login(['staff']);
$pageTitle = 'Customers';
$active    = 'customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = p('action');
        if ($action === 'save' && !can_transact()) { deny_write('Editing customers'); }
        if ($action === 'save') {
            $id     = (int)p('id');
            $name   = p('name');
            $branch = main_branch_id();
            $email  = p('email');
            if ($name === '' || !$branch) { throw new RuntimeException('Customer name and branch are required.'); }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Email is not valid.'); }
            if ($id) {
                $pp = [$branch, $name, p('contact_number'), $email, p('address'), $id];
                $sc = scope_sql('branch_id', $pp);
                run("UPDATE customers SET branch_id=?, name=?, contact_number=?, email=?, address=? WHERE id=? $sc", $pp);
                audit('update', 'customer', $id, 'Updated customer ' . $name);
                flash('Customer updated.');
            } else {
                run("INSERT INTO customers (branch_id, name, contact_number, email, address) VALUES (?,?,?,?,?)",
                    [$branch, $name, p('contact_number'), $email, p('address')]);
                audit('create', 'customer', (int)$pdo->lastInsertId(), 'Added customer ' . $name);
                flash('Customer added.');
            }
        }
    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        flash(db_error_message($ex), 'err');
    }
    redirect('customers.php');
}

$pp = []; $sc = scope_sql('c.branch_id', $pp);
$rows = qa("SELECT c.*, (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) orders_count
            FROM customers c WHERE 1=1 $sc ORDER BY c.name", $pp);

require __DIR__ . '/includes/header.php';
?>
<div class="card">
  <div class="card-head">
    <h2>Customer Records</h2>
    <div class="toolbar">
      <input class="w-220" type="search" placeholder="Search customers..." data-search="custTable">
      <button class="btn" data-open="formModal" data-title="Add Walk-in Customer">+ Add walk-in customer</button>
    </div>
  </div>
  <div class="table-wrap"><table class="table" id="custTable">
    <thead><tr><th>Name</th><th>Contact</th><th>Email</th><th>Address</th><th>Account</th><th>Orders</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><strong><?= e($r['name']) ?></strong></td>
        <td><?= e($r['contact_number']) ?></td>
        <td><?= e($r['email']) ?></td>
        <td><?= e($r['address']) ?></td>
        <td><?= $r['user_id'] ? '<span class="badge s-active">Online account</span>' : '<span class="badge">Walk-in</span>' ?></td>
        <td><?= (int)$r['orders_count'] ?></td>
        <td><div class="actions">
          <a class="btn btn-sm" href="order_form.php?customer_id=<?= (int)$r['id'] ?>">New order</a>
          <button class="btn btn-sm btn-outline" data-open="formModal" data-title="Edit Customer"
                  data-row='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'contact_number' => $r['contact_number'], 'email' => $r['email'], 'address' => $r['address']])) ?>'>Edit</button>
        </div></td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="empty">No customers yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<div class="modal" id="formModal"><div class="modal-card">
  <div class="modal-head"><h3 class="modal-title">Customer</h3><button class="x" data-close type="button">&times;</button></div>
  <div class="modal-body">
    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">
      <div class="full"><label>Customer / Organization name *</label><input name="name" required maxlength="150"></div>
      <div><label>Contact number</label><input name="contact_number" maxlength="30"></div>
      <div><label>Email</label><input type="email" name="email" maxlength="150"></div>
      <div class="full"><label>Address</label><input name="address" maxlength="255"></div>
      <div class="full modal-foot">
        <button type="button" class="btn btn-outline" data-close>Cancel</button>
        <button class="btn" type="submit">Save</button>
      </div>
    </form>
  </div>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
