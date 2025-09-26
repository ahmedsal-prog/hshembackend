<?php
require 'db.php';
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $result = $conn->query("SELECT * FROM customers ORDER BY name ASC");
        $customers = $result->fetch_all(MYSQLI_ASSOC);
        echo json_encode($customers);
        break;
    case 'POST':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $conn->prepare("INSERT INTO customers (name, email, phone, joinDate) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("sss", $data->name, $data->email, $data->phone);
        if ($stmt->execute()) {
            $data->id = $conn->insert_id;
            $data->joinDate = date('Y-m-d H:i:s');
            http_response_code(201);
            echo json_encode($data);
        }
        break;
    case 'PUT':
        $data = json_decode(file_get_contents("php://input"));
        $stmt = $conn->prepare("UPDATE customers SET name=?, email=?, phone=? WHERE id=?");
        $stmt->bind_param("sssi", $data->name, $data->email, $data->phone, $data->id);
        if ($stmt->execute()) echo json_encode($data);
        break;
    case 'DELETE':
        $id = (int)$_GET['id'];
        $stmt = $conn->prepare("DELETE FROM customers WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) http_response_code(204);
        break;
}
$conn->close();
?>