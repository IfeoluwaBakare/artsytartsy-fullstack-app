<?php
require_once '../auth_check.php';
require_role('staff');
require_once '../config.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';
    $allowedRoles = ['staff', 'manager'];
    if (!in_array($role, $allowedRoles, true)) {
        $role = 'staff';
    }

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, email, and password are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $stmt = $conn->prepare('SELECT staff_id FROM staff WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $error = 'A staff account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'INSERT INTO staff (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
            );
            $stmt->bind_param('ssss', $name, $email, $hash, $role);
            $stmt->execute();
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Staff - ArtsyTartsy</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; max-width: 420px; margin: 60px auto; padding: 0 16px; }
        input, select { width: 100%; padding: 8px; margin: 6px 0 14px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; }
        .error { color: #b00020; margin-bottom: 10px; }
        .success { color: #1b5e20; margin-bottom: 10px; }
        a.back { display: inline-block; margin-top: 16px; }
    </style>
</head>
<body>
    <h2>Add Staff Account</h2>
    <?php if ($success): ?>
        <div class="success">Staff account created for <?= htmlspecialchars($name) ?>.</div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <label>Full Name</label>
            <input type="text" name="name" required>
            <label>Email</label>
            <input type="email" name="email" required>
            <label>Password (min 8 characters)</label>
            <input type="password" name="password" required minlength="8">
            <label>Role</label>
            <select name="role">
                <option value="staff">Staff</option>
                <option value="manager">Manager</option>
            </select>
            <button type="submit">Create Staff Account</button>
        </form>
    <?php endif; ?>
    <a class="back" href="dashboard.php">&larr; Back to dashboard</a>
</body>
</html>
