
<?php
require_once __DIR__ . '/config.php';
require_login(['manager']);

$pageTitle = 'Materials';
$active = 'materials';
$user = auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        $action = p('action');

        if (!can_stock()) {
            deny_write('Changing materials');
        }

        if ($action === 'save') {
            $id = (int) p('id');
            $name = p('name');
            $unit = p('unit');
            $branch = main_branch_id();
            $reorder = max(0, (float) p('reorder_level'));
            $status = in_array(p('status'), ['Active', 'Inactive'], true) ? p('status') : 'Active';

            if ($name === '' || $unit === '' || !$branch) {
                throw new RuntimeException('Name and unit are required.');
            }

            if ($id) {
                $pp = [$branch, $name, p('description'), $unit, $reorder, $status, $id];
                $sc = scope_sql('branch_id', $pp);

                run(
                    "UPDATE materials SET branch_id=?, name=?, description=?, unit=?, reorder_level=?, status=? WHERE id=? $sc",
                    $pp
                );

                audit('update', 'material', $id, 'Updated material ' . $name);
                flash('Material updated. Use Stock In and Stock Out to change quantities.');

            } else {
                $qty = max(0, (float) p('current_quantity'));

                $pdo->beginTransaction();

                run(
                    "INSERT INTO materials (branch_id, name, description, unit, current_quantity, reorder_level, status) VALUES (?,?,?,?,?,?,?)",
                    [$branch, $name, p('description'), $unit, $qty, $reorder, $status]
                );

                $mid = (int) $pdo->lastInsertId();

                if ($qty > 0) {
                    run(
                        "INSERT INTO inventory_transactions (material_id, branch_id, user_id, transaction_type, quantity, reference, transaction_date)
                         VALUES (?,?,?,?,?,?,NOW())",
                        [$mid, $branch, $user['id'], 'Stock In', $qty, 'Opening balance']
                    );
                }

                audit(
                    'create',
                    'material',
                    $mid,
                    'Added material ' . $name . ($qty > 0 ? ' with opening stock ' . qty($qty) . ' ' . $unit : '')
                );

                $pdo->commit();
                flash('Material added.');
            }
        }

    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash($ex->getMessage(), 'err');

    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        flash(db_error_message($ex) . ' (Tip: set it to Inactive instead of deleting.)', 'err');
    }

    redirect('materials.php');
}

$pp = [];
$sc = scope_sql('m.branch_id', $pp);

if (g('stock') === 'low') {
    $sc .= " AND m.status='Active' AND m.current_quantity <= m.reorder_level";
}

$rows = qa(
    "SELECT m.* FROM materials m WHERE 1=1 $sc ORDER BY m.name",
    $pp
);

$fmt = fn($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

require __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <h2>Sublimation Materials</h2>

        <div class="toolbar">
            <input
                class="w-220"
                type="search"
                placeholder="Search materials..."
                data-search="matTable"
            >

            <?php if (g('stock') === 'low'): ?>
                <a class="btn btn-outline" href="materials.php">
                    Show all materials
                </a>
            <?php endif; ?>

            <button
                class="btn"
                data-open="formModal"
                data-title="Add Material"
            >
                + Add material
            </button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="matTable">
            <thead>
                <tr>
                    <th>Material</th>
                    <th class="right">On hand</th>
                    <th class="right">Reorder level</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $r):
                    $isLow = $r['status'] === 'Active'
                        && (float) $r['current_quantity'] <= (float) $r['reorder_level'];
                ?>
                    <tr>
                        <td>
                            <strong><?= e($r['name']) ?></strong>
                            <div class="hint"><?= e($r['description']) ?></div>
                        </td>

                        <td class="right">
                            <?= e($fmt($r['current_quantity'])) ?>
                            <?= e($r['unit']) ?>
                        </td>

                        <td class="right">
                            <?= e($fmt($r['reorder_level'])) ?>
                        </td>

                        <td>
                            <?= $isLow
                                ? '<span class="badge s-low">Low</span>'
                                : '<span class="badge">OK</span>' ?>
                        </td>

                        <td><?= badge($r['status']) ?></td>

                        <td>
                            <div class="actions">

                                <?php if ($isLow): ?>
                                    <a
                                        class="btn btn-sm"
                                        href="stock_in.php?material=<?= (int) $r['id'] ?>"
                                    >
                                        Add stock
                                    </a>
                                <?php endif; ?>

                                <button
                                    class="btn btn-sm btn-outline"
                                    data-open="formModal"
                                    data-title="Edit Material"
                                    data-row='<?= e(json_encode([
                                        'id' => $r['id'],
                                        'name' => $r['name'],
                                        'description' => $r['description'],
                                        'unit' => $r['unit'],
                                        'current_quantity' => $r['current_quantity'],
                                        'reorder_level' => $r['reorder_level'],
                                        'status' => $r['status']
                                    ])) ?>'
                                >
                                    Edit
                                </button>

                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="6" class="empty">
                            No materials yet.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="formModal">
    <div class="modal-card">

        <div class="modal-head">
            <h3 class="modal-title">Material</h3>
            <button class="x" data-close type="button">&times;</button>
        </div>

        <div class="modal-body">
            <form method="post" class="form-grid">

                <?= csrf_field() ?>

                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div class="full">
                    <label>Material name *</label>
                    <input name="name" required maxlength="150">
                </div>

                <div class="full">
                    <label>Description</label>
                    <textarea name="description"></textarea>
                </div>

                <div>
                    <label>Unit *</label>
                    <input
                        name="unit"
                        required
                        maxlength="30"
                        placeholder="sheet, ml, meter, roll"
                    >
                </div>

                <div>
                    <label>Reorder level</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="reorder_level"
                        value="0"
                    >
                </div>

                <div>
                    <label>Opening quantity</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="current_quantity"
                        value="0"
                    >

                    <div class="hint">
                        Used only when adding. After that, use Stock In and Stock Out.
                    </div>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option>Active</option>
                        <option>Inactive</option>
                    </select>
                </div>

                <div class="full modal-foot">
                    <button type="button" class="btn btn-outline" data-close>
                        Cancel
                    </button>

                    <button class="btn" type="submit">
                        Save
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
