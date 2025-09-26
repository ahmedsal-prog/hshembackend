<?php
require 'db.php';

$data = json_decode(file_get_contents("php://input"));
$password = $data->password ?? '';

if (empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(['message' => 'Settlement password is required.']);
    exit();
}

// Prepare a statement to get the global settlement password from the business_details table
$stmt = $conn->prepare("SELECT settlement_password FROM business_details WHERE id = 1");
$stmt->execute();
$result = $stmt->get_result();
$details = $result->fetch_assoc();

// Check if the details exist and if the provided password matches the settlement_password
if ($details && !empty($details['settlement_password']) && $password === $details['settlement_password']) {
    // Password is correct
    http_response_code(200);
    echo json_encode(['message' => 'Authorization successful.']);
} else {
    // Password is incorrect or not set
    http_response_code(401); // Unauthorized
    echo json_encode(['message' => 'Incorrect settlement password.']);
}

$stmt->close();
$conn->close();
?>