<?php
require 'db.php';
$result = $conn->query("SELECT id, username, role FROM users");
$users = $result->fetch_all(MYSQLI_ASSOC);
echo json_encode($users);
$conn->close();
?>