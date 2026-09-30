<?php
ob_start();

require_once __DIR__ . '/api-campaign/db-trivium.php';

header('Content-Type: application/json; charset=utf-8');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $phone   = trim($_POST['number'] ?? '');
    $message = trim($_POST['ask'] ?? '');

    // ---------------------------------------------------------
    // Validation
    // ---------------------------------------------------------

    if ($name === '') {
        throw new Exception('Name is required.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Valid email is required.');
    }

    if ($phone === '') {
        throw new Exception('Phone number is required.');
    }

    // ---------------------------------------------------------
    // Normalize email and phone
    // ---------------------------------------------------------

    $emailLower = strtolower($email);

    // Example:
    // +91-9876543210 -> 919876543210
    $phoneNumeric = preg_replace('/\D+/', '', $phone);

    if ($phoneNumeric === '') {
        throw new Exception('Valid phone number is required.');
    }

    // ---------------------------------------------------------
    // Split full name
    // ---------------------------------------------------------

    $nameParts = preg_split('/\s+/', $name);

    $firstName = $nameParts[0] ?? '';
    $lastName  = '';

    if (count($nameParts) > 1) {
        $lastName = implode(' ', array_slice($nameParts, 1));
    }

    // ---------------------------------------------------------
    // Generate EspoCRM IDs
    // ---------------------------------------------------------

    $contactId      = bin2hex(random_bytes(16));
    $emailAddressId = bin2hex(random_bytes(16));
    $phoneId        = bin2hex(random_bytes(16));

    // ---------------------------------------------------------
    // Check existing email
    // ---------------------------------------------------------

    $stmt = $pdo->prepare("
        SELECT id
        FROM email_address
        WHERE lower = ?
        LIMIT 1
    ");

    $stmt->execute([
        $emailLower
    ]);

    $existingEmail = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt_phn = $pdo->prepare("
        SELECT id
        FROM phone_number
        WHERE `numeric` = ?
        LIMIT 1
    ");
    
    $stmt_phn->execute([
        $phoneNumeric
    ]);
    
    $existingPhone = $stmt_phn->fetch(PDO::FETCH_ASSOC);

    // ---------------------------------------------------------
    // Start transaction
    // ---------------------------------------------------------

    $pdo->beginTransaction();

    // ---------------------------------------------------------
    // 1. Insert Contact
    // ---------------------------------------------------------

    $stmt = $pdo->prepare("
        INSERT INTO contact (
            id,
            first_name,
            last_name,
            description,
            deleted,
            assigned_user_id,
            created_at,
            modified_at
        )
        VALUES (
            ?,
            ?,
            ?,
            ?,
            0,
            ?,
            NOW(),
            NOW()
        )
    ");

    $stmt->execute([
        $contactId,
        $firstName,
        $lastName,
        $message,
        '692d2ae0365aef7d8'
    ]);

    // ---------------------------------------------------------
    // 2. Insert / Get Email Address
    // ---------------------------------------------------------

    if ($existingEmail) {

        $emailAddressId = $existingEmail['id'];

    } else {

        $stmt = $pdo->prepare("
            INSERT INTO email_address (
                id,
                name,
                lower
            )
            VALUES (
                ?,
                ?,
                ?
            )
        ");

        $stmt->execute([
            $emailAddressId,
            $email,
            $emailLower
        ]);
    }

    // ---------------------------------------------------------
    // 3. Connect Contact with Email
    // ---------------------------------------------------------
    
    $stmt = $pdo->prepare("
        INSERT INTO entity_email_address (
            entity_id,
            email_address_id,
            entity_type,
            `primary`
        )
        VALUES (
            ?,
            ?,
            'Contact',
            1
        )
    ");
    
    $stmt->execute([
        $contactId,
        $emailAddressId
    ]);

    // ---------------------------------------------------------
    // 4. Insert / Get Phone Number
    // ---------------------------------------------------------

    // ---------------------------------------------------------
    // Insert / Get Phone Number
    // ---------------------------------------------------------
    
    if ($existingPhone) {
    
        $phoneId = $existingPhone['id'];
    
    } else {
    
        $stmt = $pdo->prepare("
            INSERT INTO phone_number (
                id,
                name,
                `numeric`
            )
            VALUES (
                ?,
                ?,
                ?
            )
        ");
    
        $stmt->execute([
            $phoneId,
            $phone,
            $phoneNumeric
        ]);
    }
    // ---------------------------------------------------------
    // Connect Contact with Phone Number
    // ---------------------------------------------------------
    
    $stmt = $pdo->prepare("
        INSERT INTO entity_phone_number (
            entity_id,
            phone_number_id,
            entity_type,
            `primary`,
            deleted
        )
        VALUES (
            ?,
            ?,
            'Contact',
            1,
            0
        )
    ");
    
    $stmt->execute([
        $contactId,
        $phoneId
    ]);

    // ---------------------------------------------------------
    // Commit
    // ---------------------------------------------------------
    $pdo->commit();
    
    ob_clean();
    
    echo json_encode([
        'success' => true,
        'message' => 'Details sent successfully.',
        'contact_id' => $contactId
    ]);
    
    exit;
} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    ob_clean();

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);

    exit;
}
?>