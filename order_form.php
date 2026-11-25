<?php
require_once __DIR__ . '/config.php';
$pageScripts = ['roster', 'order-form'];
require_login(['staff']);
$user = auth();
$id   = (int)($_POST['id'] ?? g('id'));

$order = null;
$items = [];
if ($id) {
    $pp = [$id]; $sc = scope_sql('branch_id', $pp);
    $order = qa("SELECT * FROM orders WHERE id=? $sc", $pp)[0] ?? null;
    if (!$order) { flash('Order not found.', 'err'); redirect('orders.php'); }
    if (!order_can_edit($order['status'])) {
        flash('Order ' . $order['order_number'] . ' is ' . $order['status'] . '. It can no longer be edited.', 'err');
        redirect('order_view.php?id=' . $id);
    }
    foreach (order_items_full($id) as $it) {
        $items[] = [
            'garment_type' => $it['garment_type'], 'unit_price' => $it['unit_price'], 'quantity' => $it['quantity'],
            'design_specifications' => $it['design_specifications'], 'sized' => count($it['players']) > 0,
            'players' => $it['players'],
            'dims' => array_map(fn($d) => ['name' => $d['dimension_name'], 'value' => $d['dimension_value'], 'unit' => $d['unit']], $it['dims']),
        ];
    }
}
$paidSoFar = $id ? order_paid($id) : 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $cust = $order ? (int)$order['customer_id'] : (int)p('customer_id');
        $date = $order ? $order['order_date'] : date('Y-m-d H:i:s');   // set by the system, not typed
        $due  = p('due_date');
        if (!$cust) { throw new RuntimeException('Choose a customer.'); }
        if (!$order && $due !== '' && $due < date('Y-m-d')) { throw new RuntimeException('The due date cannot be in the past.'); }
        if ($due !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) { throw new RuntimeException('Due date is not valid.'); }
        if (!qa("SELECT id FROM customers WHERE id=? AND branch_id=?", [$cust, main_branch_id()])) { throw new RuntimeException('Choose a customer from the list.'); }

        $lines = clean_order_lines((array)($_POST['items'] ?? []));
        $total = round(array_sum(array_column($lines, 'sub')), 2);

        $pdo->beginTransaction();
        if ($id) {
            if ($total + 0.005 < $paidSoFar) {
                throw new RuntimeException('The new total (' . money($total) . ') is lower than what the customer already paid (' . money($paidSoFar) . ').');
            }
            run("UPDATE orders SET order_date=?, due_date=?, notes=?, total_amount=? WHERE id=?", [$date, $due !== '' ? $due : null, p('notes') ?: null, $total, $id]);
            run("DELETE FROM order_items WHERE order_id=?", [$id]);
            insert_order_items($id, $lines);
            audit('update', 'order', $id, 'Edited order ' . $order['order_number'] . ' (total ' . number_format($total, 2, '.', '') . ', ' . lines_pieces($lines) . ' pcs)');
            $pdo->commit();
            notify_customer_of_order($order, 'Order ' . $order['order_number'] . ' was updated', 'The shop changed your order details. Please check them.');
            flash('Order updated.');
        } else {
            [$id, $no] = create_order($cust, (int)$user['id'], 'Staff', $due !== '' ? $due : null, p('notes'), $lines, $date);
            audit('create', 'order', $id, 'Walk-in order ' . $no . ' (total ' . number_format($total, 2, '.', '') . ')');
            $pdo->commit();
            flash('Order ' . $no . ' saved. Confirm the payment below when the customer pays.');
        }
        redirect('order_view.php?id=' . $id . ($order ? '' : '#payment'));
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash($ex->getMessage(), 'err');
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flash(db_error_message($ex), 'err');
    }
    // Show the form again with what was typed.
    $order = ($order ?? []) + ['customer_id' => p('customer_id'), 'status' => 'Pending'];
    $order['order_date'] = date('Y-m-d H:i:s');
    $order['due_date']   = p('due_date');
    $order['notes']      = p('notes');
    $items = array_map(fn($it) => [
        'garment_type' => $it['garment_type'] ?? '', 'unit_price' => $it['unit_price'] ?? 0, 'quantity' => $it['quantity'] ?? 1,
        'design_specifications' => $it['design_specifications'] ?? '', 'sized' => !empty($it['sized']),
        'players' => array_values((array)($it['players'] ?? [])), 'dims' => array_values((array)($it['dims'] ?? [])),
    ], array_values((array)($_POST['items'] ?? [])));
}

$pageTitle = $id ? 'Edit Order ' . ($order['order_number'] ?? '') : 'New Walk-in Order';
$active    = $id ? 'orders' : 'neworder';

$customers = qa("SELECT c.id, c.name, c.contact_number FROM customers c WHERE c.branch_id = ? ORDER BY c.name", [main_branch_id()]);
$products  = qa("SELECT id, name, unit_price, has_sizes FROM products WHERE status='Active' ORDER BY category, name");

$selCustomer = (int)($order['customer_id'] ?? g('customer_id'));
$orderDate   = date('Y-m-d\TH:i', strtotime($order['order_date'] ?? date('Y-m-d H:i:s')));
$dueDate     = !empty($order['due_date']) ? date('Y-m-d', strtotime($order['due_date'])) : '';

require __DIR__ . '/includes/header.php';
?>
<form method="post" id="orderForm">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="card">
    <div class="card-head"><h2>Order Details</h2><?php if ($id): ?><?= badge($order['status'] ?? 'Pending') ?><?php endif; ?></div>
    <div class="card-body">
      <?php if ($id && $paidSoFar > 0): ?>
        <div class="notice mb-14">This customer already paid <strong><?= e(money($paidSoFar)) ?></strong>. The new total cannot be lower than that.</div>
      <?php endif; ?>
      <div class="form-grid">
        <div><label>Customer *</label>
          <?php if ($id): $cn = qa("SELECT name FROM customers WHERE id=?", [$selCustomer])[0]['name'] ?? ''; ?>
            <input value="<?= e($cn) ?>" readonly><input type="hidden" name="customer_id" value="<?= (int)$selCustomer ?>">
          <?php else: ?>
            <select name="customer_id" id="customer_id" required><option value="">Select customer</option>
              <?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $selCustomer === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['contact_number'] ? ' (' . e($c['contact_number']) . ')' : '' ?></option><?php endforeach; ?></select>
            <div class="hint">Not listed? <a href="customers.php">Add the customer first</a>.</div>
          <?php endif; ?></div>
        <div><label>Order date</label><input value="<?= e(date('M j, Y g:i A', strtotime($order['order_date'] ?? 'now'))) ?>" readonly disabled><div class="hint">Set automatically.</div></div>
        <div><label>Due / pick-up date</label><input type="date" name="due_date" value="<?= e($dueDate) ?>"<?= $order ? '' : ' min="' . e(date('Y-m-d')) . '"' ?>>
          <div class="hint">When the customer needs it. Shown on the dashboard.</div></div>
        <div class="full"><label>Notes</label><textarea name="notes" placeholder="Delivery, special instructions..."><?= e($order['notes'] ?? '') ?></textarea></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h2>Items &amp; Players</h2>
      <button type="button" class="btn btn-outline" id="addItem">+ Add item</button></div>
    <div class="card-body">
      <div id="items"></div>
      <div class="total-bar"><span>Order total</span><span id="grandTotal"><?= e(money(0)) ?></span></div>
    </div>
  </div>

  <div class="toolbar">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Save order' ?></button>
    <a class="btn btn-outline" href="<?= $id ? 'order_view.php?id=' . (int)$id : 'orders.php' ?>">Cancel</a>
  </div>
</form>

<datalist id="dimNames">
  <option>Print Width</option><option>Print Height</option><option>Logo Size</option><option>Chest Width</option><option>Body Length</option><option>Sleeve Length</option>
</datalist>

<script type="application/json" id="orderData"><?= json_encode(['items' => $items, 'products' => $products, 'currency' => CURRENCY], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php require __DIR__ . '/includes/footer.php'; ?>