
<?php
require_once __DIR__ . '/config.php';
require_login(['owner']);

$pageTitle = 'Users';
$active = 'users';
$me = auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        $action = p('action');

        if ((int) p('id') && q1("SELECT role FROM users WHERE id=?", [(int) p('id')]) === 'customer') {
            throw new RuntimeException('Customer accounts are managed by the customers themselves.');
        }

        if ($action === 'save') {
            $id = (int) p('id');
            $name = p('name');
            $email = p('email');
            $pw = (string) ($_POST['password'] ?? '');

            $role = in_array(p('role'), ['owner', 'manager', 'staff'], true) ? p('role') : 'staff';
            $status = in_array(p('status'), ['Pending', 'Active', 'Inactive'], true) ? p('status') : 'Active';
            $branch = $role === 'owner' ? null : main_branch_id();

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('A name and a valid email are required.');
            }

            if ($id === (int) $me['id'] && ($status !== 'Active' || $role !== 'owner')) {
                throw new RuntimeException('You cannot deactivate or demote your own account.');
            }

            if ($id) {
                if ($pw !== '') {
                    if (strlen($pw) < 8) {
                        throw new RuntimeException('Password must be at least 8 characters.');
                    }

                    run(
                        "UPDATE users SET branch_id=?, name=?, email=?, role=?, status=?, password_hash=? WHERE id=?",
                        [$branch, $name, $email, $role, $status, password_hash($pw, PASSWORD_DEFAULT), $id]
                    );
                } else {
                    run(
                        "UPDATE users SET branch_id=?, name=?, email=?, role=?, status=? WHERE id=?",
                        [$branch, $name, $email, $role, $status, $id]
                    );
                }

                audit(
                    'update',
                    'user',
                    $id,
                    'Updated user ' . $email . ' (' . $role . ', ' . $status . ')' . ($pw !== '' ? ' and reset password' : '')
                );

                flash('User updated.');
            } else {
                if (strlen($pw) < 8) {
                    throw new RuntimeException('Password must be at least 8 characters.');
                }

                run(
                    "INSERT INTO users (branch_id, name, email, password_hash, role, status) VALUES (?,?,?,?,?,?)",
                    [$branch, $name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $status]
                );

                audit('create', 'user', (int) $pdo->lastInsertId(), 'Created user ' . $email . ' (' . $role . ')');
                flash('User created.');
            }

        } elseif ($action === 'approve') {
            $id = (int) p('id');

            $n = run(
                "UPDATE users SET status='Active' WHERE id=? AND status='Pending'",
                [$id]
            );

            if ($n) {
                audit('update', 'user', $id, 'Approved a registration');
            }

            flash('Account approved. The user can sign in now.');

        } elseif ($action === 'delete') {
            $id = (int) p('id');

            if ($id === (int) $me['id']) {
                throw new RuntimeException('You cannot delete your own account.');
            }

            run("DELETE FROM users WHERE id=?", [$id]);
            audit('delete', 'user', $id, 'Deleted a user account');
            flash('User deleted.');
        }

    } catch (RuntimeException $ex) {
        flash($ex->getMessage(), 'err');

    } catch (PDOException $ex) {
        flash(db_error_message($ex) . ' (Tip: set the user to Inactive instead of deleting.)', 'err');
    }

    redirect('users.php');
}

$rows = qa("
    SELECT u.*, b.name branch
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.role <> 'customer'
    ORDER BY u.id
");

require __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-head">
        <h2>System Users</h2>

        <div class="toolbar">
            <input
                class="w-220"
                type="search"
                placeholder="Search users..."
                data-search="usersTable"
            >

            <button
                class="btn"
                data-open="formModal"
                data-title="Add User"
            >
                + Add User
            </button>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table" id="usersTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <strong><?= e($r['name']) ?></strong>
                        </td>

                        <td><?= e($r['email']) ?></td>

                        <td><?= e(ucfirst($r['role'])) ?></td>

                        <td><?= badge($r['status']) ?></td>

                        <td>
                            <div class="actions">

                                <?php if ($r['status'] === 'Pending'): ?>
                                    <form method="post" class="inline">
                                        <?= csrf_field() ?>

                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">

                                        <button class="btn btn-sm" type="submit">
                                            Approve
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <button
                                    class="btn btn-sm btn-outline"
                                    data-open="formModal"
                                    data-title="Edit User"
                                    data-row='<?= e(json_encode([
                                        'id' => $r['id'],
                                        'name' => $r['name'],
                                        'email' => $r['email'],
                                        'role' => $r['role'],
                                        'status' => $r['status']
                                    ])) ?>'
                                >
                                    Edit
                                </button>

                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="formModal">
    <div class="modal-card">

        <div class="modal-head">
            <h3 class="modal-title">User</h3>
            <button class="x" data-close type="button">&times;</button>
        </div>

        <div class="modal-body">
            <form method="post" class="form-grid" autocomplete="off">

                <?= csrf_field() ?>

                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div>
                    <label>Full name *</label>
                    <input name="name" required maxlength="100">
                </div>

                <div>
                    <label>Email *</label>
                    <input type="email" name="email" required maxlength="150">
                </div>

                <div>
                    <label>Password</label>
                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        autocomplete="new-password"
                    >

                    <div class="hint">
                        Min 8 characters. Leave blank when editing to keep the current one.
                    </div>
                </div>

                <div>
                    <label>Role</label>
                    <select name="role">
                        <option value="staff">Staff</option>
                        <option value="manager">Manager</option>
                        <option value="owner">Owner</option>
                    </select>
                </div>

                <div>
                    <label>Status</label>
                    <select name="status">
                        <option>Active</option>
                        <option>Pending</option>
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
