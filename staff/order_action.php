<?php
require_once '../auth_check.php';
require_role('staff');
require_once '../config.php';
$action = $_POST['action'] ?? '';
$redirectTab = 'overview';
if ($action === 'update_status') {
    $redirectTab = 'orders';
    $order_id = (int)($_POST['order_id'] ?? 0);
    $status = $_POST['status'] ?? '';
    $allowed = ['Pending', 'Confirmed', 'Shipped', 'Delivered', 'Cancelled'];
    if ($order_id > 0 && in_array($status, $allowed, true)) {
        $stmt = $conn->prepare('UPDATE orders SET order_status = ? WHERE order_id = ?');
        $stmt->bind_param('si', $status, $order_id);
        $stmt->execute();
    }
} elseif ($action === 'update_stock') {
    $redirectTab = 'inventory';
    $product_id = (int)($_POST['product_id'] ?? 0);
    $new_stock = (int)($_POST['stock_quantity'] ?? -1);
    if ($product_id > 0 && $new_stock >= 0) {
        $stmt = $conn->prepare('UPDATE products SET stock_quantity = ? WHERE product_id = ?');
        $stmt->bind_param('ii', $new_stock, $product_id);
        $stmt->execute();
    }
}
header('Location: dashboard.php?tab=' . $redirectTab);
exit;
