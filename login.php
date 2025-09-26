<?php
require 'db.php';

$data = json_decode(file_get_contents("php://input"));
$username = $data->username ?? '';
$password = $data->password ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(['message' => 'Username and password are required.']);
    exit();
}

// Use a prepared statement to prevent SQL injection
$stmt = $conn->prepare("SELECT id, username, role FROM users WHERE username = ? AND password = ?");
$stmt->bind_param("ss", $username, $password);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    // User found, send back user data (without the password)
    http_response_code(200);
    echo json_encode($user);
} else {
    // User not found or password incorrect
    http_response_code(401); // Unauthorized
    echo json_encode(['message' => 'Invalid username or password.']);
}

$stmt->close();
$conn->close();
?>