<?php
require 'db.php';

$data = json_decode(file_get_contents("php://input"));

if (empty($data->itemId) || empty($data->quantityToReturn)) {
    http_response_code(400);
    echo json_encode(['message' => 'Item ID and quantity are required.']);
    exit();
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
    $stmt->bind_param("ii", $data->quantityToReturn, $data->itemId);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update stock: ' . $stmt->error);
    }

    $conn->commit();
    http_response_code(200);
    echo json_encode(['message' => 'Stock updated successfully for return.']);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['message' => $e->getMessage()]);
}

$stmt->close();
$conn->close();
?>