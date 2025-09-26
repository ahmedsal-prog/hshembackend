<?php
require 'db.php'; // Includes the database connection from db.php

// --- Router ---
// This part checks the request method (GET, POST, PUT, DELETE) and calls the correct function.
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        handleGet($conn);
        break;
    case 'POST':
        handlePost($conn);
        break;
    case 'PUT':
        handlePut($conn);
        break;
    case 'DELETE':
        handleDelete($conn);
        break;
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['message' => 'Method not supported']);
        break;
}

// --- Function Implementations ---

/**
 * Handles GET requests.
 * Fetches all orders and their associated items from the database.
 */
function handleGet($conn) {
    $sql = "
        SELECT 
            o.id, o.order_date, o.payment_method, o.total, o.subtotal, o.amount_tendered, o.change_due, o.total_refunded, o.status,
            oi.id as item_id, oi.product_id, oi.product_name, oi.quantity, oi.selling_price, oi.purchase_price, oi.quantity_returned
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        ORDER BY o.order_date DESC
    ";
    
    $result = $conn->query($sql);
    $orders = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $orderId = $row['id'];
            if (!isset($orders[$orderId])) {
                $orders[$orderId] = [
                    'id' => $orderId,
                    'date' => $row['order_date'],
                    'paymentDetails' => [
                        'method' => $row['payment_method'],
                        'total' => (float)$row['total'],
                        'amountTendered' => (float)$row['amount_tendered'],
                        'changeDue' => (float)$row['change_due']
                    ],
                    'subtotal' => (float)$row['subtotal'],
                    'status' => $row['status'],
                    'totalRefunded' => (float)$row['total_refunded'],
                    'cart' => []
                ];
            }
            if ($row['item_id']) {
                $orders[$orderId]['cart'][] = [
                    'id' => (int)$row['product_id'],
                    'name' => $row['product_name'],
                    'quantity' => (int)$row['quantity'],
                    'sellingPrice' => (float)$row['selling_price'],
                    'purchasePrice' => (float)$row['purchase_price'],
                    'quantityReturned' => (int)$row['quantity_returned'],
                ];
            }
        }
    }
    // Convert associative array to indexed array for JSON output
    echo json_encode(array_values($orders));
}

/**
 * Handles POST requests.
 * Creates a new order and its items in the database.
 * This function uses a transaction to ensure data integrity.
 */
function handlePost($conn) {
    $data = json_decode(file_get_contents("php://input"));
    $orderId = 'ORD-' . time() . '-' . rand(100, 999);
    $date = date('Y-m-d H:i:s');

    // Start a transaction: All queries must succeed, or none will.
    $conn->begin_transaction();

    try {
        // 1. Insert into the main 'orders' table
        $stmt_order = $conn->prepare("INSERT INTO orders (id, order_date, payment_method, total, subtotal, amount_tendered, change_due, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')");
        $stmt_order->bind_param("sssdddd", $orderId, $date, $data->paymentDetails->method, $data->paymentDetails->total, $data->subtotal, $data->paymentDetails->amountTendered, $data->paymentDetails->changeDue);
        $stmt_order->execute();

        // 2. Insert each item into the 'order_items' table and update stock
        $stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, product_name, quantity, selling_price, purchase_price) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

        foreach ($data->cart as $item) {
            // Insert item
            $stmt_item->bind_param("sisidd", $orderId, $item->id, $item->name, $item->quantity, $item->sellingPrice, $item->purchasePrice);
            $stmt_item->execute();

            // Update stock
            $stmt_stock->bind_param("iii", $item->quantity, $item->id, $item->quantity);
            $stmt_stock->execute();
            if ($stmt_stock->affected_rows == 0) {
                throw new Exception("Insufficient stock for product: " . $item->name);
            }
        }
        
        // If everything was successful, commit the changes to the database
        $conn->commit();

        // 3. Send back the newly created order object to the frontend
        $newOrder = [
            'id' => $orderId,
            'date' => $date,
            'cart' => $data->cart,
            'paymentDetails' => $data->paymentDetails,
            'subtotal' => $data->subtotal,
            'status' => 'completed',
            'totalRefunded' => 0
        ];
        http_response_code(201); // Created
        echo json_encode($newOrder);

    } catch (Exception $e) {
        // If anything failed, roll back all changes
        $conn->rollback();
        http_response_code(400); // Bad Request
        echo json_encode(['message' => $e->getMessage()]);
    }
}

/**
 * Handles PUT requests.
 * Processes an item return.
 */
function handlePut($conn) {
    $data = json_decode(file_get_contents("php://input"));

    $conn->begin_transaction();
    try {
        // 1. Get the item's selling price to calculate refund amount
        $stmt_price = $conn->prepare("SELECT selling_price FROM order_items WHERE order_id = ? AND product_id = ?");
        $stmt_price->bind_param("si", $data->orderId, $data->itemId);
        $stmt_price->execute();
        $result = $stmt_price->get_result();
        $item = $result->fetch_assoc();
        $refundAmount = $item['selling_price'] * $data->quantityToReturn;

        // 2. Update the returned quantity in order_items
        $stmt_item = $conn->prepare("UPDATE order_items SET quantity_returned = quantity_returned + ? WHERE order_id = ? AND product_id = ?");
        $stmt_item->bind_param("isi", $data->quantityToReturn, $data->orderId, $data->itemId);
        $stmt_item->execute();

        // 3. Update the total refunded amount in the main orders table
        $stmt_order = $conn->prepare("UPDATE orders SET total_refunded = total_refunded + ? WHERE id = ?");
        $stmt_order->bind_param("ds", $refundAmount, $data->orderId);
        $stmt_order->execute();

        // 4. Increase the stock in the products table
        $stmt_stock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt_stock->bind_param("ii", $data->quantityToReturn, $data->itemId);
        $stmt_stock->execute();

        $conn->commit();
        http_response_code(200);
        echo json_encode(['message' => 'Return processed successfully.']);

    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['message' => 'Failed to process return.', 'error' => $e->getMessage()]);
    }
}


/**
 * Handles DELETE requests.
 * Clears all order history after verifying admin password.
 */
function handleDelete($conn) {
    $data = json_decode(file_get_contents("php://input"));
    $password = $data->password ?? '';

    // 1. Verify admin password first for security
    // This assumes you have a 'users' table with an 'administrator' user.
    $stmt_user = $conn->prepare("SELECT password FROM users WHERE username = 'administrator'");
    $stmt_user->execute();
    $result = $stmt_user->get_result();
    $admin = $result->fetch_assoc();

    if (!$admin || $password !== $admin['password']) {
        http_response_code(401); // Unauthorized
        echo json_encode(['message' => 'Incorrect admin password.']);
        return;
    }
    
    // 2. If password is correct, clear the tables
    // TRUNCATE is faster than DELETE and resets auto-increment counters.
    // We disable foreign key checks temporarily to allow truncating tables with relationships.
    $conn->query("SET FOREIGN_KEY_CHECKS = 0;");
    $conn->query("TRUNCATE TABLE order_items;");
    $conn->query("TRUNCATE TABLE orders;");
    $conn->query("SET FOREIGN_KEY_CHECKS = 1;");

    http_response_code(204); // No Content
}


$conn->close();
?>