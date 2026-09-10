<?php
require_once '../auth_check.php';
require_role('customer');
require_once '../config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = []; // [product_id => quantity]
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = max(1, (int)($_POST['quantity'] ?? 1));
    if ($product_id > 0) {
        $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + $qty;
    }
} elseif ($action === 'remove') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    unset($_SESSION['cart'][$product_id]);
} elseif ($action === 'clear') {
    $_SESSION['cart'] = [];
} elseif ($action === 'place_order') {
    $customer_id = $_SESSION['user_id'];
    $cart = $_SESSION['cart'];

    if (empty($cart)) {
        header('Location: dashboard.php?error=empty_cart');
        exit;
    }

    $ids = implode(',', array_map('intval', array_keys($cart)));
    $products = [];
    $result = $conn->query("SELECT product_id, price, stock_quantity FROM products WHERE product_id IN ($ids)");
    while ($row = $result->fetch_assoc()) {
        $products[$row['product_id']] = $row;
    }

    foreach ($cart as $pid => $qty) {
        if (!isset($products[$pid]) || $products[$pid]['stock_quantity'] < $qty) {
            header('Location: dashboard.php?error=out_of_stock');
            exit;
        }
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('INSERT INTO orders (customer_id, order_date, order_status) VALUES (?, CURDATE(), ?)');
        $status = 'Pending';
        $stmt->bind_param('is', $customer_id, $status);
        $stmt->execute();
        $order_id = $conn->insert_id;

        $itemStmt = $conn->prepare(
            'INSERT INTO order_items (order_id, product_id, quantity, unit_price) VALUES (?, ?, ?, ?)'
        );
        $stockStmt = $conn->prepare(
            'UPDATE products SET stock_quantity = stock_quantity - ? WHERE product_id = ?'
        );

        foreach ($cart as $pid => $qty) {
            $price = $products[$pid]['price'];
            $itemStmt->bind_param('iiid', $order_id, $pid, $qty, $price);
            $itemStmt->execute();

            $stockStmt->bind_param('ii', $qty, $pid);
            $stockStmt->execute();
        }

        $conn->commit();
        $_SESSION['cart'] = [];
        header('Location: dashboard.php?success=order_placed');
        exit;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Order placement failed: ' . $e->getMessage());
        header('Location: dashboard.php?error=order_failed');
        exit;
    }
}

header('Location: dashboard.php');
exit;
