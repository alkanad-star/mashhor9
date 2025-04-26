<?php
// referral_functions.php - Core functions for the referral system

/**
 * Generate a unique referral code for a user
 * 
 * @param int $user_id The ID of the user
 * @return string The generated referral code
 */
function generateReferralCode($user_id) {
    // Start with prefix for readability
    $prefix = 'REF';
    
    // Use user ID for uniqueness
    $unique_part = str_pad($user_id, 6, '0', STR_PAD_LEFT);
    
    // Add some randomness (4 characters)
    $random_part = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 4));
    
    // Combine parts
    $referral_code = $prefix . $unique_part . $random_part;
    
    return $referral_code;
}

/**
 * Validate a referral code
 * 
 * @param string $code The referral code to validate
 * @param int $current_user_id (Optional) Current user ID to prevent self-referral
 * @return int|false The user ID associated with the code, or false if invalid
 */
function validateReferralCode($code, $current_user_id = null) {
    global $conn;
    
    if (empty($code)) {
        return false;
    }
    
    $stmt = $conn->prepare("SELECT id FROM users WHERE referral_code = ?");
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Prevent self-referral if current user ID is provided
        if ($current_user_id && $user['id'] == $current_user_id) {
            return false;
        }
        
        return $user['id'];
    }
    
    return false;
}

/**
 * Create a referral relationship between users
 * 
 * @param int $referrer_id User ID of the referrer
 * @param int $referred_id User ID of the referred user
 * @return bool Success status
 */
function createReferral($referrer_id, $referred_id) {
    global $conn;
    
    // Check if users exist
    $stmt = $conn->prepare("SELECT id FROM users WHERE id IN (?, ?)");
    $stmt->bind_param("ii", $referrer_id, $referred_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows !== 2) {
        return false;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update the referred user
        $update_user = $conn->prepare("UPDATE users SET referred_by = ? WHERE id = ?");
        $update_user->bind_param("ii", $referrer_id, $referred_id);
        $update_user->execute();
        
        // Create referral record
        $create_referral = $conn->prepare("
            INSERT INTO referrals (referrer_id, referred_id, status, reward_type) 
            VALUES (?, ?, 'pending', 'signup')
        ");
        $create_referral->bind_param("ii", $referrer_id, $referred_id);
        $create_referral->execute();
        
        // Get referral settings
        $settings = $conn->query("SELECT * FROM referral_settings WHERE id = 1")->fetch_assoc();
        
        // If signup rewards are enabled, process immediately
        if ($settings['enabled'] && $settings['signup_reward'] > 0) {
            $reward_amount = $settings['signup_reward'];
            
            // Update referral record
            $update_referral = $conn->prepare("
                UPDATE referrals 
                SET status = 'completed', 
                    reward_amount = ?, 
                    reward_paid = TRUE,
                    completed_at = NOW() 
                WHERE referrer_id = ? AND referred_id = ?
            ");
            $update_referral->bind_param("dii", $reward_amount, $referrer_id, $referred_id);
            $update_referral->execute();
            
            // Add reward to referrer's balance
            $update_balance = $conn->prepare("
                UPDATE users 
                SET balance = balance + ?, 
                    total_referral_earnings = total_referral_earnings + ? 
                WHERE id = ?
            ");
            $update_balance->bind_param("ddi", $reward_amount, $reward_amount, $referrer_id);
            $update_balance->execute();
            
            // Create transaction record
            $description = "مكافأة إحالة: تسجيل مستخدم جديد";
            $create_transaction = $conn->prepare("
                INSERT INTO transactions (user_id, amount, type, status, description) 
                VALUES (?, ?, 'deposit', 'completed', ?)
            ");
            $create_transaction->bind_param("ids", $referrer_id, $reward_amount, $description);
            $create_transaction->execute();
            
            // Send notification to referrer
            $notification_title = "مكافأة إحالة";
            $notification_message = "لقد حصلت على $" . number_format($reward_amount, 2) . " كمكافأة لإحالة مستخدم جديد.";
            
            // Check if notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'")->num_rows;
            
            if ($table_check > 0) {
                $notification_query = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, notification_type, icon) 
                    VALUES (?, ?, ?, 'system', 'fas fa-gift')
                ");
                $notification_query->bind_param("iss", $referrer_id, $notification_title, $notification_message);
                $notification_query->execute();
            }
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Referral creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Process order-based referral rewards
 * 
 * @param int $user_id User ID who placed the order
 * @param float $order_amount Order amount
 * @param int $order_id Order ID
 * @param bool $pay_immediately Whether to pay the reward immediately
 * @return bool Success status
 */
function processOrderReferralReward($user_id, $order_amount, $order_id, $pay_immediately = false) {
    global $conn;
    
    // Get user info with referrer
    $stmt = $conn->prepare("SELECT u1.referred_by, u2.referral_percentage 
                           FROM users u1 
                           LEFT JOIN users u2 ON u1.referred_by = u2.id 
                           WHERE u1.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    // If user wasn't referred, nothing to do
    if (!$user['referred_by']) {
        return false;
    }
    
    $referrer_id = $user['referred_by'];
    
    // Get referral settings
    $settings = $conn->query("SELECT * FROM referral_settings WHERE id = 1")->fetch_assoc();
    
    // Check if reward should be processed
    if (!$settings['enabled'] || $order_amount < $settings['min_order_amount']) {
        return false;
    }
    
    // Determine percentage - use custom if available, otherwise use default
    $percentage = !is_null($user['referral_percentage']) ? 
                  $user['referral_percentage'] : 
                  $settings['order_reward_percentage'];
    
    // Check if percentage is valid
    if ($percentage <= 0) {
        return false;
    }
    
    // Calculate reward using the determined percentage
    $reward_amount = $order_amount * ($percentage / 100);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create referral record for this order
        $create_referral = $conn->prepare("
            INSERT INTO referrals (referrer_id, referred_id, status, reward_type, reward_amount, reward_paid, completed_at, order_id) 
            VALUES (?, ?, ?, 'order', ?, ?, NOW(), ?)
        ");
        
        $status = $pay_immediately ? 'completed' : 'pending';
        $reward_paid = $pay_immediately ? 1 : 0;
        
        $create_referral->bind_param("iisdii", $referrer_id, $user_id, $status, $reward_amount, $reward_paid, $order_id);
        $create_referral->execute();
        
        // If immediate payment is requested, process payment now
        if ($pay_immediately) {
            // Add reward to referrer's balance
            $update_balance = $conn->prepare("
                UPDATE users 
                SET balance = balance + ?, 
                    total_referral_earnings = total_referral_earnings + ? 
                WHERE id = ?
            ");
            $update_balance->bind_param("ddi", $reward_amount, $reward_amount, $referrer_id);
            $update_balance->execute();
            
            // Create transaction record
            $description = "مكافأة إحالة: طلب بقيمة $" . number_format($order_amount, 2);
            $create_transaction = $conn->prepare("
                INSERT INTO transactions (user_id, amount, type, status, description) 
                VALUES (?, ?, 'deposit', 'completed', ?)
            ");
            $create_transaction->bind_param("ids", $referrer_id, $reward_amount, $description);
            $create_transaction->execute();
            
            // Send notification to referrer
            $notification_title = "مكافأة إحالة من طلب";
            $notification_message = "لقد حصلت على $" . number_format($reward_amount, 2) . " كمكافأة من طلب تم بواسطة أحد المستخدمين الذين قمت بإحالتهم.";
            
            // Check if notifications table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'notifications'")->num_rows;
            
            if ($table_check > 0) {
                $notification_query = $conn->prepare("
                    INSERT INTO notifications (user_id, title, message, notification_type, icon) 
                    VALUES (?, ?, ?, 'system', 'fas fa-gift')
                ");
                $notification_query->bind_param("iss", $referrer_id, $notification_title, $notification_message);
                $notification_query->execute();
            }
        }
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Order referral reward error: " . $e->getMessage());
        return false;
    }
}

/**
 * Complete a pending referral reward when an order is finalized
 * 
 * @param int $order_id Order ID
 * @return bool Success status
 */
function completePendingReferralReward($order_id) {
    global $conn;
    
    // Get pending referral for this order
    $stmt = $conn->prepare("
        SELECT r.*, o.amount as order_amount 
        FROM referrals r
        JOIN orders o ON r.order_id = o.id
        WHERE r.order_id = ? AND r.status = 'pending' AND r.reward_paid = 0
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $referral = $stmt->get_result()->fetch_assoc();
    
    if (!$referral) {
        return false; // No pending referral found
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update referral status
        $update_referral = $conn->prepare("
            UPDATE referrals 
            SET status = 'completed', 
                reward_paid = 1,
                completed_at = NOW()
            WHERE id = ?
        ");
        $update_referral->bind_param("i", $referral['id']);
        $update_referral->execute();
        
        // Add reward to referrer's balance
        $update_balance = $conn->prepare("
            UPDATE users 
            SET balance = balance + ?, 
                total_referral_earnings = total_referral_earnings + ? 
            WHERE id = ?
        ");
        $update_balance->bind_param("ddi", $referral['reward_amount'], $referral['reward_amount'], $referral['referrer_id']);
        $update_balance->execute();
        
        // Create transaction record
        $description = "مكافأة إحالة: طلب بقيمة $" . number_format($referral['order_amount'], 2);
        $create_transaction = $conn->prepare("
            INSERT INTO transactions (user_id, amount, type, status, description) 
            VALUES (?, ?, 'deposit', 'completed', ?)
        ");
        $create_transaction->bind_param("ids", $referral['referrer_id'], $referral['reward_amount'], $description);
        $create_transaction->execute();
        
        // Send notification to referrer
        $notification_title = "مكافأة إحالة من طلب";
        $notification_message = "لقد حصلت على $" . number_format($referral['reward_amount'], 2) . " كمكافأة من طلب تم بواسطة أحد المستخدمين الذين قمت بإحالتهم.";
        
        $notification_query = $conn->prepare("
            INSERT INTO notifications (user_id, title, message, notification_type, icon) 
            VALUES (?, ?, ?, 'system', 'fas fa-gift')
        ");
        $notification_query->bind_param("iss", $referral['referrer_id'], $notification_title, $notification_message);
        $notification_query->execute();
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Complete referral reward error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get referral statistics for a user
 * 
 * @param int $user_id User ID
 * @return array Statistics about user's referrals
 */
function getUserReferralStats($user_id) {
    global $conn;
    
    $stats = [
        'total_referrals' => 0,
        'completed_referrals' => 0,
        'total_earnings' => 0,
        'referral_code' => '',
        'referred_users' => []
    ];
    
    // Get user's referral code
    $stmt = $conn->prepare("SELECT referral_code, total_referral_earnings FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stats['total_earnings'] = $user['total_referral_earnings'];
        
        // Generate referral code if user doesn't have one
        if (empty($user['referral_code'])) {
            $referral_code = generateReferralCode($user_id);
            $update = $conn->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
            $update->bind_param("si", $referral_code, $user_id);
            $update->execute();
            $stats['referral_code'] = $referral_code;
        } else {
            $stats['referral_code'] = $user['referral_code'];
        }
    }
    
    // Get count of referrals
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_referrals,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_referrals
        FROM referrals 
        WHERE referrer_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $counts = $result->fetch_assoc();
        $stats['total_referrals'] = $counts['total_referrals'];
        $stats['completed_referrals'] = $counts['completed_referrals'];
    }
    
    // Get list of referred users
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.full_name, u.created_at, 
               COUNT(o.id) as order_count,
               SUM(r.reward_amount) as rewards_generated
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        LEFT JOIN referrals r ON u.id = r.referred_id AND r.referrer_id = ?
        WHERE u.referred_by = ?
        GROUP BY u.id
        ORDER BY u.created_at DESC
        LIMIT 10
    ");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $stats['referred_users'][] = $row;
    }
    
    return $stats;
}