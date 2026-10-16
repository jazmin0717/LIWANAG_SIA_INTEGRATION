<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */

// TODO: Implement the system integration here.

// TODO: Display the appropriate checkout result.

// STEP 8: PREPARE PAYMENT REQUEST

$idempotencyKey = 'cart-order-' . $orderId;

$testOutcome = 'PENDING';

$paymentRequest = [
    'order_id' => $orderId,
    'amount' => round((float)$total, 2),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => $idempotencyKey,
    'outcome' => $testOutcome
];

$jsonPayload = json_encode($paymentRequest);

if ($jsonPayload === false) {
    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment request could not be prepared.'
    );
}

// STEP 9: SEND REQUEST TO PAYMENT GATEWAY

$gatewayUrl = 'http://localhost/payment-gateway/api/payments.php';

$ch = curl_init($gatewayUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $jsonPayload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Idempotency-Key: ' . $idempotencyKey
    ]
]);

$response = curl_exec($ch);

$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

// STEP 10 & 12: HANDLE CONNECTION ERROR

if ($response === false || $curlErrno !== 0) {

    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment gateway could not be reached or the request timed out. Check the order status before attempting payment again.'
    );
}

// HANDLE HTTP ERROR

if ($httpCode < 200 || $httpCode >= 300) {

    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment gateway returned an HTTP error. Check the order status before attempting payment again.'
    );
}

// STEP 10: DECODE RESPONSE

$gatewayData = json_decode(
    $response,
    true
);

if (
    !is_array($gatewayData) ||
    json_last_error() !== JSON_ERROR_NONE
) {

    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment gateway returned an invalid response. Check the order status before attempting payment again.'
    );
}

// CHECK REQUIRED RESPONSE FIELDS

if (
    !isset($gatewayData['order_id']) ||
    !isset($gatewayData['status'])
) {

    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment gateway response is missing required information.'
    );
}

// VERIFY ORDER ID
if (
    (int)$gatewayData['order_id'] !==
    (int)$orderId
) {

    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );

    $stmt->execute([$orderId]);

    finish(
        'Payment status unknown',
        'The payment response does not match this order.'
    );
}

// GET STATUS AND TRANSACTION ID

$paymentStatus = strtoupper(
    trim(
        (string)$gatewayData['status']
    )
);

$transactionId = null;

if (
    isset($gatewayData['transaction_id']) &&
    is_string($gatewayData['transaction_id']) &&
    trim($gatewayData['transaction_id']) !== ''
) {

    $transactionId =
        trim($gatewayData['transaction_id']);
}

// STEP 11 & 13: PROCESS PAYMENT RESULT

switch ($paymentStatus) {

    // SUCCESS
    case 'SUCCESS':
    case 'PAID':

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "SELECT status
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );

            $stmt->execute([$orderId]);

            $order = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$order) {
                throw new RuntimeException(
                    'Order not found.'
                );
            }

            if ($order['status'] !== 'PAID') {

                foreach ($validItems as $item) {

                    $productId =
                        (int)$item['product']['id'];

                    $quantity =
                        (int)$item['qty'];

                    $stockStmt = $pdo->prepare(
                        "UPDATE products
                         SET stock = stock - ?
                         WHERE id = ?
                           AND stock >= ?"
                    );

                    $stockStmt->execute([
                        $quantity,
                        $productId,
                        $quantity
                    ]);

                    if (
                        $stockStmt->rowCount() !== 1
                    ) {

                        throw new RuntimeException(
                            'Insufficient product stock.'
                        );
                    }
                }

                $stmt = $pdo->prepare(
                    "UPDATE orders
                     SET status = 'PAID',
                         gateway_transaction_id = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $transactionId,
                    $orderId
                ]);
            }

            $pdo->commit();

            $_SESSION['cart'] = [];

            finish(
                'Payment successful',
                'Your payment was confirmed successfully. Order #' .
                $orderId .
                ' has been paid.'
            );

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            finish(
                'Order processing error',
                'The payment was confirmed, but the order could not be fully processed. Please check the order before trying again.'
            );
        }

        break;

    // FAILED
    case 'FAILED':

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = 'PAYMENT_FAILED',
                 gateway_transaction_id = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $transactionId,
            $orderId
        ]);

        finish(
            'Payment failed',
            'The payment gateway confirmed that payment for order #' .
            $orderId .
            ' failed.'
        );

        break;

    // PENDING
    case 'PENDING':

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = 'PAYMENT_PENDING',
                 gateway_transaction_id = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $transactionId,
            $orderId
        ]);

        finish(
            'Payment pending',
            'Payment for order #' .
            $orderId .
            ' is awaiting confirmation.'
        );

        break;

    // UNKNOWN STATUS
    default:

        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = 'INTEGRATION_ERROR',
                 gateway_transaction_id = ?
             WHERE id = ?"
        );

        $stmt->execute([
            $transactionId,
            $orderId
        ]);

        finish(
            'Payment status unknown',
            'The payment gateway returned an unrecognized payment status. Check the order status before attempting payment again.'
        );

        break;
}