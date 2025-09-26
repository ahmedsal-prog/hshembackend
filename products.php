<?php
require 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// A simple router to handle different HTTP methods
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
        handleDelete($conn, $id);
        break;
    default:
        http_response_code(405); // Method Not Allowed
        echo json_encode(['message' => 'Method not supported']);
        break;
}

function handleGet($conn) {
    $sql = "SELECT * FROM products ORDER BY name ASC";
    $result = $conn->query($sql);
    $products = [];
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Cast numeric types for correct JSON formatting
            $row['id'] = (int)$row['id'];
            $row['sellingPrice'] = (float)$row['sellingPrice'];
            $row['purchasePrice'] = (float)$row['purchasePrice'];
            $row['stock'] = (int)$row['stock'];
            $row['lowStockThreshold'] = (int)$row['lowStockThreshold'];
            $products[] = $row;
        }
    }
    echo json_encode($products);
}

function handlePost($conn) {
    $data = json_decode(file_get_contents("php://input"));
    $stmt = $conn->prepare("INSERT INTO products (name, sku, itemCode, sellingPrice, purchasePrice, stock, lowStockThreshold, description, imageUrl, supplier, lastPurchaseOrderNumber, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssddiisssss", $data->name, $data->sku, $data->itemCode, $data->sellingPrice, $data->purchasePrice, $data->stock, $data->lowStockThreshold, $data->description, $data->imageUrl, $data->supplier, $data->lastPurchaseOrderNumber, $data->category);

    if ($stmt->execute()) {
        $data->id = $conn->insert_id;
        http_response_code(201);
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to add product', 'error' => $stmt->error]);
    }
    $stmt->close();
}

function handlePut($conn) {
    $data = json_decode(file_get_contents("php://input"));
    $stmt = $conn->prepare("UPDATE products SET name=?, sku=?, itemCode=?, sellingPrice=?, purchasePrice=?, stock=?, lowStockThreshold=?, description=?, imageUrl=?, supplier=?, lastPurchaseOrderNumber=?, category=? WHERE id=?");
    $stmt->bind_param("sssddiisssssi", $data->name, $data->sku, $data->itemCode, $data->sellingPrice, $data->purchasePrice, $data->stock, $data->lowStockThreshold, $data->description, $data->imageUrl, $data->supplier, $data->lastPurchaseOrderNumber, $data->category, $data->id);

    if ($stmt->execute()) {
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to update product', 'error' => $stmt->error]);
    }
    $stmt->close();
}

function handleDelete($conn, $id) {
    if (!$id) {
        http_response_code(400);
        echo json_encode(['message' => 'Product ID is required']);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        http_response_code(204); // No Content
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to delete product', 'error' => $stmt->error]);
    }
    $stmt->close();
}

$conn->close();
?>