<?php
// notifications.php

// 1. Load Dependencies
require 'db.php';
require 'config.php'; // Your new secure config file
require 'vendor/autoload.php'; // Composer's autoloader

// 2. Import required classes
use PHPMailer\PHPMailer\PHPMailer;
use Twilio\Rest\Client as TwilioClient;

// 3. Get the request type from the frontend
$data = json_decode(file_get_contents("php://input"));
$notification_type = $data->type ?? '';

if (empty($notification_type)) {
    http_response_code(400);
    echo json_encode(['message' => 'Notification type is required.']);
    exit();
}

// 4. Get the business owner's contact details from the database
$result = $conn->query("SELECT owner_email, owner_phone FROM business_details WHERE id = 1");
$owner = $result->fetch_assoc();
$owner_email = $owner['owner_email'];
$owner_phone_sms = $owner['owner_phone'];
$owner_phone_whatsapp = 'whatsapp:' . $owner['owner_phone'];

// 5. Main router: decide which notification to build and send
try {
    $subject = '';
    $email_body = '';
    $sms_body = '';

    switch ($notification_type) {
        case 'low_stock':
            // Fetch low stock products
            $stmt = $conn->prepare("SELECT name, stock, lowStockThreshold FROM products WHERE stock > 0 AND stock <= lowStockThreshold ORDER BY stock ASC");
            $stmt->execute();
            $low_stock_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if (count($low_stock_products) > 0) {
                $subject = "SmartPOS: Low Stock Warning";
                $email_body = "<h3>Low Stock Warning</h3><p>The following items are running low:</p><ul>";
                $sms_body = "SmartPOS Low Stock Alert:\n";
                foreach ($low_stock_products as $product) {
                    $item_str = "{$product['name']} (Stock: {$product['stock']})";
                    $email_body .= "<li>{$item_str}</li>";
                    $sms_body .= "- {$item_str}\n";
                }
                $email_body .= "</ul>";
            } else {
                echo json_encode(['message' => 'No low stock items to report.']);
                exit();
            }
            break;

        case 'daily_settlement':
            // Fetch today's settlement data
            $stmt = $conn->prepare("SELECT * FROM orders WHERE DATE(order_date) = CURDATE() AND status = 'completed'");
            $stmt->execute();
            $today_orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $gross_sales = array_sum(array_column($today_orders, 'total'));
            $total_refunds = array_sum(array_column($today_orders, 'total_refunded'));
            $net_sales = $gross_sales - $total_refunds;
            $transaction_count = count($today_orders);

            $subject = "SmartPOS: Daily Settlement Report for " . date('Y-m-d');
            $email_body = "<h3>Daily Settlement</h3>" .
                          "<p><strong>Net Sales:</strong> QR " . number_format($net_sales, 2) . "</p>" .
                          "<p><strong>Gross Sales:</strong> QR " . number_format($gross_sales, 2) . "</p>" .
                          "<p><strong>Total Refunds:</strong> QR " . number_format($total_refunds, 2) . "</p>" .
                          "<p><strong>Transactions:</strong> " . $transaction_count . "</p>";
            $sms_body = "SmartPOS Daily Summary:\n" .
                        "Net Sales: QR " . number_format($net_sales, 2) . "\n" .
                        "Transactions: " . $transaction_count;
            break;
        
        default:
             throw new Exception('Invalid notification type specified.');
    }

    // 6. Send the notifications
    $success_messages = [];
    
    // Send Email
    if (!empty($owner_email) && !empty($email_body)) {
        send_email($owner_email, $subject, $email_body);
        $success_messages[] = 'Email sent';
    }

    // Send SMS
    if (!empty($owner_phone_sms) && !empty($sms_body)) {
        send_sms($owner_phone_sms, $sms_body);
        $success_messages[] = 'SMS sent';
    }

    // Send WhatsApp
    if (!empty($owner_phone_whatsapp) && !empty($sms_body)) {
        send_whatsapp($owner_phone_whatsapp, $sms_body);
        $success_messages[] = 'WhatsApp sent';
    }

    http_response_code(200);
    echo json_encode(['message' => 'Notifications sent successfully: ' . implode(', ', $success_messages)]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['message' => 'Failed to send notification: ' . $e->getMessage()]);
}

$conn->close();


// --- Helper Functions ---

function send_email($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        //Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        //Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($to);

        //Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body);

        $mail->send();
    } catch (Exception $e) {
        throw new Exception("Email could not be sent. Mailer Error: {$mail->ErrorInfo}");
    }
}

function send_sms($to, $body) {
    try {
        $twilio = new TwilioClient(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $twilio->messages->create(
            $to,
            [
                'from' => TWILIO_PHONE_NUMBER,
                'body' => $body
            ]
        );
    } catch (Exception $e) {
        throw new Exception("SMS could not be sent. Twilio Error: " . $e->getMessage());
    }
}

function send_whatsapp($to, $body) {
    try {
        $twilio = new TwilioClient(TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN);
        $twilio->messages->create(
            $to,
            [
                'from' => TWILIO_WHATSAPP_NUMBER,
                'body' => $body
            ]
        );
    } catch (Exception $e) {
        throw new Exception("WhatsApp message could not be sent. Twilio Error: " . $e->getMessage());
    }
}
?>