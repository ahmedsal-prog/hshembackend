<?php
require 'db.php'; // Includes our database connection

$cart = json_decode(file_get_contents("php://input"));

if (empty($cart)) {
    http_response_code(400);
    echo json_encode(['message' => 'Cart data is missing.']);
    exit();
}

// Start a transaction
$conn->begin_transaction();

try {
    // Prepare the update statement once
    $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");

    foreach ($cart as $item) {
        $quantity_sold = $item->quantity;
        $product_id = $item->id;

        // Bind parameters and execute
        $stmt->bind_param("iii", $quantity_sold, $product_id, $quantity_sold);
        if (!$stmt->execute()) {
            throw new Exception("Failed to execute statement for product ID $product_id: " . $stmt->error);
        }

        // Check if the update was successful (i.e., if stock was sufficient)
        if ($stmt->affected_rows == 0) {
            // If no rows were affected, it means stock was insufficient.
            throw new Exception("Insufficient stock for product: " . $item->name);
        }
    }

    // If all items were updated successfully, commit the transaction
    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Stock updated successfully.']);

} catch (Exception $e) {
    // If any item fails, roll back the entire transaction
    $conn->rollback();
    http_response_code(400); // Bad Request
    echo json_encode(['message' => $e->getMessage()]);
}

$stmt->close();
$conn->close();
?>