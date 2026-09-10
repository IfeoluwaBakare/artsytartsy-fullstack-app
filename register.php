<?php
require_once 'config.php';

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, email, and password are required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $stmt = $conn->prepare('SELECT customer_id FROM customers WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $error = 'An account with that email already exists. Try logging in instead.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare(
                'INSERT INTO customers (name, email, phone, address, password_hash) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssss', $name, $email, $phone, $address, $hash);
            $stmt->execute();
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>ArtsyTartsy - Create Account</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cream: #fdf6ec;
            --terracotta: #d2743a;
            --terracotta-dark: #b5602c;
            --choc: #4a2c1d;
            --choc-light: #7a5a45;
            --error: #b3261e;
            --success: #2e6b3e;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Nunito Sans', sans-serif;
            background: var(--cream);
            background-image: radial-gradient(circle at 20% 20%, #fbe9d5 0%, transparent 40%),
                               radial-gradient(circle at 80% 80%, #fbe9d5 0%, transparent 40%);
            color: var(--choc);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 24px;
        }
        .card {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 12px 32px rgba(74, 44, 29, 0.12);
            padding: 40px 36px;
            width: 100%;
            max-width: 420px;
        }
        h1 {
            font-family: 'Fraunces', serif;
            font-size: 1.6em;
            margin: 0 0 24px;
            text-align: center;
            color: var(--choc);
        }
        label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 6px; }
        input {
            width: 100%;
            padding: 12px 14px;
            margin-bottom: 16px;
            border: 1.5px solid #e8d9c9;
            border-radius: 10px;
            font-size: 1em;
            font-family: inherit;
            background: #fffaf3;
        }
        input:focus { outline: none; border-color: var(--terracotta); }
        button {
            width: 100%;
            padding: 13px;
            background: var(--terracotta);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1em;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
        }
        button:hover { background: var(--terracotta-dark); }
        .error { background: #fbeae8; color: var(--error); padding: 10px 14px; border-radius: 8px; font-size: 0.9em; margin-bottom: 18px; }
        .success { background: #e8f3ea; color: var(--success); padding: 10px 14px; border-radius: 8px; font-size: 0.9em; margin-bottom: 18px; }
        .success a { color: var(--success); font-weight: 700; }
        .footer-link { text-align: center; margin-top: 20px; font-size: 0.9em; color: var(--choc-light); }
        .footer-link a { color: var(--terracotta); font-weight: 700; text-decoration: none; }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Create Your Account</h1>
        <?php if ($success): ?>
            <div class="success">Account created! <a href="login.php">Log in now</a></div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <form method="post">
                <label>Full Name</label>
                <input type="text" name="name" required>
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Phone</label>
                <input type="text" name="phone">
                <label>Address</label>
                <input type="text" name="address">
                <label>Password (min 8 characters)</label>
                <input type="password" name="password" required minlength="8">
                <button type="submit">Create Account</button>
            </form>
        <?php endif; ?>
        <p class="footer-link"><a href="login.php">Already have an account? Log in</a></p>
    </div>
</body>
</html>
