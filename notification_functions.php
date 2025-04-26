<?php
/**
 * Notification utility functions for sending notifications from anywhere in the application
 */

/**
 * Send a notification to a user
 * 
 * @param int|null $user_id The user ID to send to, or null for global notification
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $notification_type Type of notification (general, order, payment, system, promotion)
 * @param string $icon Font Awesome icon class
 * @param string $action_url Optional URL to navigate to when clicking the notification
 * @return bool True on success, false on failure
 */
function send_notification($user_id, $title, $message, $notification_type = 'general', $icon = 'fas fa-bell', $action_url = '') {
    global $conn;
    
    
    
    // Validate notification type
    $valid_types = ['general', 'order', 'payment', 'system', 'promotion'];
    if (!in_array($notification_type, $valid_types)) {
        $notification_type = 'general';
    }
    
    // If user_id is provided, verify the user exists
    if ($user_id !== null) {
        $check_user = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $check_user->bind_param("i", $user_id);
        $check_user->execute();
        $user_result = $check_user->get_result();
        
        if ($user_result->num_rows === 0) {
            error_log("Failed to send notification: User ID $user_id does not exist");
            return false;
        }
    }
    
    // Prepare query based on whether user_id is null (global) or not
    if ($user_id === null) {
        $insert_query = "INSERT INTO notifications (user_id, title, message, notification_type, icon, action_url) 
                        VALUES (NULL, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sssss", $title, $message, $notification_type, $icon, $action_url);
    } else {
        $insert_query = "INSERT INTO notifications (user_id, title, message, notification_type, icon, action_url) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("isssss", $user_id, $title, $message, $notification_type, $icon, $action_url);
    }
    
    // Execute and return result
    if (!$stmt->execute()) {
        error_log("Error sending notification: " . $stmt->error);
        return false;
    }
    return true;
}

/**
 * Send a notification to all users
 * 
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $notification_type Type of notification (general, order, payment, system, promotion)
 * @param string $icon Font Awesome icon class
 * @param string $action_url Optional URL to navigate to when clicking the notification
 * @return bool True on success, false on failure
 */
function send_notification_to_all($title, $message, $notification_type = 'general', $icon = 'fas fa-bell', $action_url = '') {
    global $conn;
    
    // Ensure notifications table exists
    include_once 'init_notifications_table.php';
    
    // Get all users
    $users_query = "SELECT id FROM users WHERE id > 0";
    $users = $conn->query($users_query);
    
    if (!$users || $users->num_rows === 0) {
        // No users found, send a global notification instead
        return send_notification(null, $title, $message, $notification_type, $icon, $action_url);
    }
    
    // Start transaction
    $conn->begin_transaction();
    $success = true;
    
    try {
        while ($user = $users->fetch_assoc()) {
            $result = send_notification($user['id'], $title, $message, $notification_type, $icon, $action_url);
            if (!$result) {
                $success = false;
                break;
            }
        }
        
        if ($success) {
            $conn->commit();
            return true;
        } else {
            $conn->rollback();
            return false;
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error sending notifications: " . $e->getMessage());
        return false;
    }
}

/**
 * Send an order notification to a user
 * 
 * @param int $user_id The user ID
 * @param int $order_id The order ID
 * @param string $status The order status
 * @return bool True on success, false on failure
 */
function send_order_notification($user_id, $order_id, $status) {
    // Verify the user exists first
    global $conn;
    
    $check_user = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $check_user->bind_param("i", $user_id);
    $check_user->execute();
    $user_result = $check_user->get_result();
    
    if ($user_result->num_rows === 0) {
        error_log("Failed to send order notification: User ID $user_id does not exist");
        return false;
    }
    
    $title = "تحديث حالة الطلب #$order_id";
    $action_url = "orders.php?id=$order_id";
    
    switch ($status) {
        case 'pending':
            $message = "تم استلام طلبك #$order_id وهو الآن قيد الانتظار.";
            break;
        case 'processing':
            $message = "تم بدء تنفيذ طلبك #$order_id.";
            break;
        case 'completed':
            $message = "تم اكتمال طلبك #$order_id بنجاح.";
            break;
        case 'partial':
            $message = "تم تنفيذ طلبك #$order_id بشكل جزئي. يرجى مراجعة التفاصيل.";
            break;
        case 'cancelled':
            $message = "تم إلغاء طلبك #$order_id. تم استرداد المبلغ إلى رصيدك.";
            break;
        case 'failed':
            $message = "عذراً، فشل تنفيذ طلبك #$order_id. تم استرداد المبلغ إلى رصيدك.";
            break;
        default:
            $message = "تم تحديث حالة طلبك #$order_id.";
    }
    
    return send_notification($user_id, $title, $message, 'order', 'fas fa-shopping-cart', $action_url);
}

/**
 * Send a payment notification to a user
 * 
 * @param int $user_id The user ID
 * @param int $transaction_id The transaction ID
 * @param string $status The payment status
 * @param float $amount The payment amount
 * @return bool True on success, false on failure
 */
function send_payment_notification($user_id, $transaction_id, $status, $amount) {
    // Verify the user exists first
    global $conn;
    
    $check_user = $conn->prepare("SELECT id FROM users WHERE id = ?");
    $check_user->bind_param("i", $user_id);
    $check_user->execute();
    $user_result = $check_user->get_result();
    
    if ($user_result->num_rows === 0) {
        error_log("Failed to send payment notification: User ID $user_id does not exist");
        return false;
    }
    
    $formatted_amount = number_format($amount, 2);
    $title = "تحديث حالة الدفع #$transaction_id";
    $action_url = "balance.php";
    
    switch ($status) {
        case 'pending':
            $message = "تم استلام طلب الدفع الخاص بك بقيمة $$formatted_amount وهو قيد المراجعة.";
            break;
        case 'completed':
            $message = "تم اعتماد عملية الدفع الخاصة بك بنجاح بقيمة $$formatted_amount. تمت إضافة المبلغ إلى رصيدك.";
            break;
        case 'failed':
            $message = "عذراً، فشلت عملية الدفع الخاصة بك بقيمة $$formatted_amount.";
            break;
        default:
            $message = "تم تحديث حالة عملية الدفع الخاصة بك بقيمة $$formatted_amount.";
    }
    
    return send_notification($user_id, $title, $message, 'payment', 'fas fa-wallet', $action_url);
}
?>