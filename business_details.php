<?php
require 'db.php';
$method = $_SERVER['REQUEST_METHOD'];

if ($method == 'GET') {
    $result = $conn->query("SELECT * FROM business_details WHERE id = 1");
    $details = $result->fetch_assoc();
    echo json_encode($details);
} elseif ($method == 'POST') {
    $data = json_decode(file_get_contents("php://input"));
    $stmt = $conn->prepare("UPDATE business_details SET name=?, address=?, phone=?, website=?, logoUrl=? WHERE id = 1");
    $stmt->bind_param("sssss", $data->name, $data->address, $data->phone, $data->website, $data->logoUrl);
    if ($stmt->execute()) {
        echo json_encode($data);
    } else {
        http_response_code(500);
        echo json_encode(['message' => 'Failed to update details']);
    }
}
$conn->close();
?>