<?php
session_start();
header('Content-Type: application/json');

// --- Configuration Loading ---
$config_file = 'config.json';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration file (config.json) not found.']);
    exit;
}

$config_json = file_get_contents($config_file);
$config = json_decode($config_json, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON in config.json: ' . json_last_error_msg()]);
    exit;
}

// --- Database Configuration from config.json ---
$db_host = $config['db_host'] ?? 'localhost';
$db_name = $config['db_name'] ?? 'game';
$db_user = $config['db_user'] ?? 'root';
$db_pass = $config['db_pass'] ?? '';

// --- Database Connection ---
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// --- Global Variables & Game Constants ---
define('GOVERNMENT_USER_ID', 1);
define('GOVERNMENT_TAX_RATE', 0.25);
define('REFERRAL_BONUS_RATE', 0.05);
define('OCCUPATION_TAX_RATE', 0.10);
define('TASK_DURATION_SECONDS', 3);

// --- Main API Router ---
$action = $_POST['action'] ?? '';
$response = ['success' => false, 'message' => 'Invalid action.'];

// Routes that do not require authentication
$public_actions = ['login', 'register', 'logout'];
if (in_array($action, $public_actions)) {
    // Let it pass
} else if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.', 'auth_error' => true]);
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;

try {
    switch ($action) {
        case 'register':
            $response = handle_register($pdo, $_POST['username'], $_POST['password'], $_POST['email']);
            break;
        case 'login':
            $response = handle_login($pdo, $_POST['username'], $_POST['password']);
            break;
        case 'logout':
            session_destroy();
            $response = ['success' => true];
            break;
        case 'get_user_data':
            $response = get_user_data($pdo, $user_id);
            break;
        case 'start_task':
            $response = start_task($pdo, $user_id, $_POST['task_type'], $_POST['worker_id']);
            break;
        case 'complete_task':
            $response = complete_task($pdo, $user_id);
            break;
        case 'upgrade_building':
            $response = upgrade_building($pdo, $user_id, $_POST['building_type']);
            break;
        case 'recruit_soldiers':
            $response = handle_recruit_soldiers($pdo, $user_id);
            break;
        case 'post_job_offer':
            $response = handle_post_job_offer($pdo, $user_id, $_POST['item'], $_POST['quantity'], $_POST['salary']);
            break;
        case 'get_job_offers':
            $response = handle_get_job_offers($pdo, $user_id);
            break;
        case 'accept_job':
            $response = handle_accept_job($pdo, $user_id, $_POST['job_id']);
            break;
        case 'place_market_order':
            $response = place_market_order($pdo, $user_id, $_POST['order_type'], $_POST['item_type'], $_POST['quantity'], $_POST['price']);
            if ($response['success']) {
                matchMarketOrders($pdo, $_POST['item_type']);
            }
            break;
        case 'get_market_data':
            $response = get_market_data($pdo, $_POST['item_type']);
            break;
        case 'get_history':
            $response = get_history($pdo, $user_id);
            break;
        case 'get_tax_history':
            $response = handle_get_tax_history($pdo);
            break;
        case 'get_government_data':
            $response = handle_get_government_data($pdo);
            break;
        case 'get_distribution_data':
            $response = handle_get_distribution_data($pdo);
            break;
        case 'get_distribution_history':
            $response = handle_get_distribution_history($pdo);
            break;
        case 'get_harvesting_activity':
            $response = handle_get_harvesting_activity($pdo, $_POST['timeframe'] ?? '24_hours');
            break;
        case 'get_leaderboard':
            $response = handle_get_leaderboard($pdo, $_POST['sort_by'] ?? 'net_worth');
            break;
        case 'get_conquer_data':
            $response = handle_get_conquer_data($pdo, $user_id);
            break;
        case 'conquer_player':
            $response = handle_conquer_player($pdo, $user_id, $_POST['target_id']);
            break;
        case 'liberate_player':
            $response = handle_liberate_player($pdo, $user_id, $_POST['target_id']);
            break;
        default:
            $response = ['success' => false, 'message' => 'Unknown action.'];
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    $response = ['success' => false, 'message' => 'An internal server error occurred: ' . $e->getMessage()];
}


echo json_encode($response);
exit;


// --- Helper Functions ---

function log_action($pdo, $user_id, $action_type, $description) {
    $stmt = $pdo->prepare("INSERT INTO action_logs (user_id, action_type, description) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $action_type, $description]);
}

function log_transaction($pdo, $user_id, $type, $amount, $description) {
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $type, $amount, $description]);
}

function get_army_power($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT u.soldiers, u.sword, b.level as barracks_level 
        FROM users u 
        LEFT JOIN buildings b ON u.id = b.user_id AND b.building_type = 'Barracks' 
        WHERE u.id = ?
    ");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch();
    if (!$data) return 0;
    
    $barracks_bonus = 1 + (($data['barracks_level'] ?? 0) / 100);
    return min((float)$data['soldiers'], (float)$data['sword']) * $barracks_bonus;
}

function apply_taxes($pdo, $user_id, $gross_income, $resource_type, $source_description) {
    $user = $pdo->query("SELECT * FROM users WHERE id = $user_id")->fetch();
    $net_income = $gross_income;

    // Total Tax (25%)
    $total_tax = $gross_income * GOVERNMENT_TAX_RATE;
    $net_income -= $total_tax;

    // Split tax into Government Share (15%) and Harvesting Reward Pool (10%)
    $harvesting_reward_pool = $gross_income * 0.10;
    $government_share = $gross_income * 0.15;
    
    log_action($pdo, $user_id, 'tax_payment', "Paid $total_tax $resource_type in taxes from $source_description.");
    if ($resource_type === 'nano_earned_balance') {
        log_transaction($pdo, $user_id, 'tax', -$total_tax, "Total tax on $source_description.");
        log_transaction($pdo, GOVERNMENT_USER_ID, 'tax_income', $total_tax, "Gross tax revenue from user #{$user_id} ({$user['username']}).");
    }
    // Temporarily give the full tax amount to government before distribution
    $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$total_tax, GOVERNMENT_USER_ID]);

    $referral_bonus = 0;
    // Referral Bonus (5% of gross, paid from government's KEEP share)
    if ($user['referrer_id']) {
        $referral_bonus = $gross_income * REFERRAL_BONUS_RATE;
        $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` - ? WHERE id = ?")->execute([$referral_bonus, GOVERNMENT_USER_ID]);
        $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$referral_bonus, $user['referrer_id']]);
        log_action($pdo, $user['referrer_id'], 'referral_bonus', "Received $referral_bonus $resource_type as referral bonus from user {$user['username']}.");
        if ($resource_type === 'nano_earned_balance') {
            log_transaction($pdo, GOVERNMENT_USER_ID, 'referral_payout', -$referral_bonus, "Referral bonus paid for user #{$user_id}.");
            log_transaction($pdo, $user['referrer_id'], 'referral_income', $referral_bonus, "Referral bonus from user #{$user_id}.");
        }
    }

    // Occupation Tax on the user's NET income (10% of gross)
    if ($user['occupied_by']) {
        $occupation_tax = $gross_income * OCCUPATION_TAX_RATE;
        $net_income -= $occupation_tax;
        $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$occupation_tax, $user['occupied_by']]);
        log_action($pdo, $user['occupied_by'], 'occupation_tax', "Received $occupation_tax $resource_type as occupation tax from user {$user['username']}.");
        log_action($pdo, $user_id, 'tax_payment', "Paid $occupation_tax $resource_type in occupation tax.");
        if ($resource_type === 'nano_earned_balance') {
            log_transaction($pdo, $user_id, 'tax', -$occupation_tax, "Occupation tax paid.");
            log_transaction($pdo, $user['occupied_by'], 'tax_income', $occupation_tax, "Occupation tax from user #{$user_id}.");
        }
    }

    // --- Harvesting Reward Distribution (10% of gross) ---
    if ($harvesting_reward_pool > 0) {
        $activity_query = $pdo->prepare("
            SELECT user_id, COUNT(id) as action_count
            FROM action_logs
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND action_type IN ('Harvest Wood', 'Harvest Iron', 'Harvest Stone')
            GROUP BY user_id
        ");
        $activity_query->execute();
        $harvesters = $activity_query->fetchAll();
        
        $total_actions_24h = array_sum(array_column($harvesters, 'action_count'));

        // Take the reward pool from the government's temporary balance
        $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` - ? WHERE id = ?")->execute([$harvesting_reward_pool, GOVERNMENT_USER_ID]);

        if ($total_actions_24h > 0) {
            foreach ($harvesters as $harvester) {
                $recipient_id = $harvester['user_id'];
                $share_percentage = $harvester['action_count'] / $total_actions_24h;
                $distribution_share = $harvesting_reward_pool * $share_percentage;

                // Check if recipient is occupied
                $recipient_stmt = $pdo->prepare("SELECT username, occupied_by FROM users WHERE id = ?");
                $recipient_stmt->execute([$recipient_id]);
                $recipient_data = $recipient_stmt->fetch();
                
                $net_share = $distribution_share;
                if ($recipient_data && $recipient_data['occupied_by']) {
                    $occupier_id = $recipient_data['occupied_by'];
                    $occupation_tax_on_reward = $distribution_share * OCCUPATION_TAX_RATE;
                    $net_share -= $occupation_tax_on_reward;
                    
                    // Pay occupier
                    $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$occupation_tax_on_reward, $occupier_id]);
                    
                    $occupier_stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
                    $occupier_stmt->execute([$occupier_id]);
                    $occupier_username = $occupier_stmt->fetchColumn();
                    
                    log_action($pdo, $occupier_id, 'occupation_tax', "Received {$occupation_tax_on_reward} {$resource_type} as occupation tax from {$recipient_data['username']}'s harvesting reward.");
                }

                // Pay recipient
                $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$net_share, $recipient_id]);
                $asset_name = str_replace(['nano_earned_', '_'], ['', ' '], $resource_type);
                $dist_desc = "Received a harvesting reward of {$net_share} {$asset_name}. Your actions: {$harvester['action_count']}. Total actions: {$total_actions_24h}. Your share: " . round($share_percentage * 100, 2) . "%.";
                log_action($pdo, $recipient_id, 'tax_distribution', $dist_desc);
                if ($resource_type === 'nano_earned_balance') {
                    log_transaction($pdo, $recipient_id, 'distribution_income', $net_share, "Received harvesting reward.");
                }
            }
        } else {
            // If no one harvested, the reward pool goes to the government
            $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?")->execute([$harvesting_reward_pool, GOVERNMENT_USER_ID]);
        }
    }

    return $net_income;
}

function process_payment($pdo, $user_id, $total_cost, $description) {
    $stmt = $pdo->prepare("SELECT nano_unearned_balance, nano_earned_balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$user_id]);
    $user_balances = $stmt->fetch();

    $unearned_payment = min($total_cost, (float)$user_balances['nano_unearned_balance']);
    $earned_payment = $total_cost - $unearned_payment;
    
    $pdo->prepare("UPDATE users SET nano_unearned_balance = nano_unearned_balance - ?, nano_earned_balance = nano_earned_balance - ? WHERE id = ?")
        ->execute([$unearned_payment, $earned_payment, $user_id]);

    log_transaction($pdo, $user_id, 'payment', -$total_cost, $description);
}


// --- API Endpoint Handlers ---

function handle_register($pdo, $username, $password, $email) {
    if (empty($username) || empty($password) || empty($email)) {
        return ['success' => false, 'message' => 'All fields are required.'];
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username or email already exists.'];
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
    if ($stmt->execute([$username, $hashed_password, $email])) {
        $user_id = $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Dormitory', 1)")->execute([$user_id]);
        $pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Barracks', 0)")->execute([$user_id]);
        $pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Blacksmith', 0)")->execute([$user_id]);
        log_action($pdo, $user_id, 'register', 'User account created.');
        return ['success' => true, 'message' => 'Registration successful.'];
    }
    return ['success' => false, 'message' => 'Registration failed.'];
}

function handle_login($pdo, $username, $password) {
    $stmt = $pdo->prepare("SELECT id, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $username;
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Invalid username or password.'];
}

function get_user_data($pdo, $user_id) {
    $pdo->exec("SET time_zone = '+03:00'");

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch();
    if (!$data) {
        session_destroy();
        return ['success' => false, 'message' => 'User not found.', 'auth_error' => true];
    }
    unset($data['password']);

    $stmt = $pdo->prepare("SELECT building_type, level FROM buildings WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data['buildings'] = [];
    foreach ($stmt->fetchAll() as $building) {
        $data['buildings'][$building['building_type']] = $building['level'];
    }

    $stmt = $pdo->prepare("SELECT worker_id, task_type, completion_time FROM worker_tasks WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $data['worker_tasks'] = $stmt->fetchAll();

    $total_power = get_army_power($pdo, $user_id);
    $barracks_level = $data['buildings']['Barracks'] ?? 0;
    $base_power = floor(min((float)$data['soldiers'], (float)$data['sword']));
    $data['military_power_breakdown'] = [
        'base' => $base_power,
        'bonus_perc' => $barracks_level,
        'total' => $total_power
    ];
    $data['military_power'] = $total_power; // Keep this for backward compatibility if needed anywhere else
    $data['soldier_recruit_cost'] = [
        'wood' => pow(1.001, (int)$data['soldiers']),
        'iron' => pow(1.001, (int)$data['soldiers']),
        'stone' => pow(1.001, (int)$data['soldiers']),
    ];
    $dorm_level = $data['buildings']['Dormitory'] ?? 0;
    $data['next_worker_level'] = (floor($dorm_level / 5) + 1) * 5;


    // Add server constants for the frontend
    $data['task_duration_seconds'] = TASK_DURATION_SECONDS;

    // Productivity
    $action_counts_stmt = $pdo->prepare("SELECT action_type, COUNT(*) as count FROM action_logs WHERE user_id = ? AND action_type IN ('Harvest Wood', 'Harvest Iron', 'Harvest Stone', 'job_completed') GROUP BY action_type");
    $action_counts_stmt->execute([$user_id]);
    $counts = array_column($action_counts_stmt->fetchAll(), 'count', 'action_type');
    
    $data['productivity'] = [];
    $task_types = ['Harvest Wood' => 'Wood', 'Harvest Iron' => 'Iron', 'Harvest Stone' => 'Stone', 'job_completed' => 'Sword'];
    $building_bonus = 1 + ($dorm_level / 100);

    foreach($task_types as $task_name => $item_name) {
        $actions = $counts[$task_name] ?? 0;
        $exp_level = floor(sqrt($actions));
        $exp_bonus = 1 + ($exp_level / 100);
        
        $data['productivity'][$item_name] = [
            'base_yield' => 1.0,
            'exp_bonus_perc' => $exp_level,
            'building_bonus_perc' => $dorm_level,
            'total_yield' => 1.0 * $exp_bonus * $building_bonus,
            'actions' => $actions,
            'current_bonus_perc' => $exp_level,
            'actions_for_next_level' => pow($exp_level + 1, 2),
            'next_level_bonus_perc' => $exp_level + 1
        ];
    }
    
    // Last 24H Harvest Activity
    $harvest_actions = ['Harvest Wood', 'Harvest Iron', 'Harvest Stone'];
    $stmt_user_24h = $pdo->prepare("SELECT COUNT(*) FROM action_logs WHERE user_id = ? AND action_type IN ('" . implode("','", $harvest_actions) . "') AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt_user_24h->execute([$user_id]);
    $user_actions_24h = $stmt_user_24h->fetchColumn();

    $stmt_all_24h = $pdo->prepare("SELECT COUNT(*) FROM action_logs WHERE action_type IN ('" . implode("','", $harvest_actions) . "') AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt_all_24h->execute();
    $all_actions_24h = $stmt_all_24h->fetchColumn();

    $stmt_rewards = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE user_id = ? AND type = 'distribution_income' AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt_rewards->execute([$user_id]);
    $total_rewards_24h = $stmt_rewards->fetchColumn() ?: 0;

    $data['harvest_activity_24h'] = [
        'user_actions_24h' => $user_actions_24h,
        'all_actions_24h' => $all_actions_24h,
        'user_share_perc' => ($all_actions_24h > 0) ? ($user_actions_24h / $all_actions_24h) * 100 : 0,
        'total_rewards_24h' => $total_rewards_24h,
    ];

    return ['success' => true, 'data' => $data];
}


function start_task($pdo, $user_id, $task_type, $worker_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ? AND worker_id = ?");
    $stmt->execute([$user_id, $worker_id]);
    if ($stmt->fetchColumn() > 0) {
        return ['success' => false, 'message' => 'Worker is already busy.'];
    }
    
    $completion_time = date('Y-m-d H:i:s', time() + TASK_DURATION_SECONDS);
    $stmt = $pdo->prepare("INSERT INTO worker_tasks (user_id, worker_id, task_type, completion_time) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$user_id, $worker_id, $task_type, $completion_time])) {
        return ['success' => true, 'message' => 'Task started.'];
    }
    return ['success' => false, 'message' => 'Failed to start task.'];
}

function complete_task($pdo, $user_id) {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT id, task_type, job_offer_id FROM worker_tasks WHERE user_id = ? AND completion_time <= NOW() LIMIT 1");
    $stmt->execute([$user_id]);
    $task = $stmt->fetch();

    if (!$task) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'No completed tasks found.'];
    }
    
    if ($task['job_offer_id']) { 
        $job_stmt = $pdo->prepare("SELECT * FROM job_offers WHERE id = ?");
        $job_stmt->execute([$task['job_offer_id']]);
        $job = $job_stmt->fetch();
        
        if ($job) {
            $pdo->prepare("UPDATE users SET {$job['item_to_produce']} = {$job['item_to_produce']} + ? WHERE id = ?")->execute([$job['quantity'], $job['employer_id']]);
            
            $net_salary = apply_taxes($pdo, $user_id, $job['salary'], 'nano_earned_balance', "salary for job #{$job['id']}");
            $pdo->prepare("UPDATE users SET nano_earned_balance = nano_earned_balance + ? WHERE id = ?")->execute([$net_salary, $user_id]);
            log_transaction($pdo, $user_id, 'job_payout', $net_salary, "Received salary for job #{$job['id']}.");
            
            $pdo->prepare("UPDATE job_offers SET status = 'completed' WHERE id = ?")->execute([$job['id']]);

            log_action($pdo, $user_id, 'job_completed', "Completed job #{$job['id']} for {$job['quantity']} swords and earned a salary of {$net_salary} currency.");
            log_action($pdo, $job['employer_id'], 'job_production', "Your job #{$job['id']} was completed, you received {$job['quantity']} {$job['item_to_produce']}(s).");
        }
    } else if (strpos($task['task_type'], 'Harvest') !== false) {
        $resource_type = strtolower(str_replace('Harvest ', '', $task['task_type']));
        
        $stmt_before = $pdo->prepare("SELECT `$resource_type` FROM users WHERE id = ?");
        $stmt_before->execute([$user_id]);
        $before_balance = $stmt_before->fetchColumn();

        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM action_logs WHERE user_id = ? AND action_type = ?");
        $count_stmt->execute([$user_id, $task['task_type']]);
        $actions = $count_stmt->fetchColumn();
        $exp_level = floor(sqrt($actions));
        $exp_bonus = 1 + ($exp_level / 100);

        $dorm_stmt = $pdo->prepare("SELECT level FROM buildings WHERE user_id = ? AND building_type = 'Dormitory'");
        $dorm_stmt->execute([$user_id]);
        $dorm_level = $dorm_stmt->fetchColumn() ?: 0;
        $building_bonus = 1 + ($dorm_level / 100);

        $yield = 1 * $exp_bonus * $building_bonus;

        $net_yield = apply_taxes($pdo, $user_id, $yield, $resource_type, "harvesting $resource_type");
        
        $update_stmt = $pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?");
        $update_stmt->execute([$net_yield, $user_id]);

        $after_balance = $before_balance + $net_yield;

        log_action($pdo, $user_id, $task['task_type'], "Completed harvesting, gained {$net_yield} {$resource_type} (gross: {$yield}). Balance changed from {$before_balance} to {$after_balance}.");
    }

    $pdo->prepare("DELETE FROM worker_tasks WHERE id = ?")->execute([$task['id']]);
    $pdo->commit();
    return ['success' => true, 'message' => 'Task completed successfully!'];
}

function upgrade_building($pdo, $user_id, $building_type) {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT level FROM buildings WHERE user_id = ? AND building_type = ?");
    $stmt->execute([$user_id, $building_type]);
    $building = $stmt->fetch();
    if (!$building) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Building not found.'];
    }
    $current_level = $building['level'];
    $next_level = $current_level + 1;

    $cost = pow(2, $current_level);

    $stmt = $pdo->prepare("SELECT wood, iron, stone FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $resources = $stmt->fetch();

    if ($resources['wood'] < $cost || $resources['iron'] < $cost || $resources['stone'] < $cost) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Not enough resources.'];
    }

    $stmt = $pdo->prepare("UPDATE users SET wood = wood - ?, iron = iron - ?, stone = stone - ? WHERE id = ?");
    $stmt->execute([$cost, $cost, $cost, $user_id]);

    $stmt = $pdo->prepare("UPDATE buildings SET level = ? WHERE user_id = ? AND building_type = ?");
    $stmt->execute([$next_level, $user_id, $building_type]);
    
    if ($building_type == 'Dormitory' && $next_level % 5 == 0) {
        $stmt = $pdo->prepare("UPDATE users SET workers = workers + 1 WHERE id = ?");
        $stmt->execute([$user_id]);
        log_action($pdo, $user_id, 'new_worker', "Gained a new worker from Dormitory reaching level $next_level.");
    }

    log_action($pdo, $user_id, 'upgrade_building', "Upgraded $building_type to level $next_level.");
    $pdo->commit();
    return ['success' => true, 'message' => "$building_type upgraded to level $next_level."];
}

function handle_recruit_soldiers($pdo, $user_id) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT wood, iron, stone, soldiers FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $resources = $stmt->fetch();

        $cost = pow(1.001, (int)$resources['soldiers']);

        if ($resources['wood'] < $cost || $resources['iron'] < $cost || $resources['stone'] < $cost) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Not enough resources to recruit a soldier.'];
        }

        $stmt = $pdo->prepare("UPDATE users SET wood = wood - ?, iron = iron - ?, stone = stone - ?, soldiers = soldiers + 1 WHERE id = ?");
        $stmt->execute([$cost, $cost, $cost, $user_id]);

        log_action($pdo, $user_id, 'recruit', "Recruited 1 soldier.");
        $pdo->commit();
        return ['success' => true, 'message' => "Successfully recruited 1 soldier."];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Error recruiting soldiers: ' . $e->getMessage()];
    }
}

function handle_get_job_offers($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT j.*, u.username as employer_username 
        FROM job_offers j 
        JOIN users u ON j.employer_id = u.id 
        WHERE j.status = 'open' AND j.employer_id != ? 
        ORDER BY j.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $jobs = $stmt->fetchAll();
    return ['success' => true, 'data' => $jobs];
}

function handle_post_job_offer($pdo, $user_id, $item, $quantity, $salary_per_item) {
    if ($item !== 'sword' || $quantity <= 0 || $salary_per_item <= 0) {
        return ['success' => false, 'message' => 'Invalid job offer details.'];
    }

    $total_salary = (float)$quantity * (float)$salary_per_item;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT iron, nano_unearned_balance, nano_earned_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        $iron_cost = (float)$quantity;
        
        if ((float)$user['iron'] < $iron_cost) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Not enough iron.'];
        }
        if (((float)$user['nano_unearned_balance'] + (float)$user['nano_earned_balance']) < $total_salary) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Not enough currency.'];
        }

        $pdo->prepare("UPDATE users SET iron = iron - ? WHERE id = ?")->execute([$iron_cost, $user_id]);
        
        process_payment($pdo, $user_id, $total_salary, "Escrow for posting job to produce $quantity $item.");

        $stmt = $pdo->prepare("INSERT INTO job_offers (employer_id, item_to_produce, quantity, salary) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $item, $quantity, $total_salary]);

        log_action($pdo, $user_id, 'post_job', "Posted a job to produce $quantity $item for a total salary of $total_salary currency.");
        $pdo->commit();
        return ['success' => true, 'message' => 'Job offer posted successfully.'];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Error posting job: ' . $e->getMessage()];
    }
}

function handle_accept_job($pdo, $user_id, $job_id) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT workers FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $total_workers = $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $busy_workers = $stmt->fetchColumn();

        if ($busy_workers >= $total_workers) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'All your workers are busy.'];
        }

        $worker_id = -1;
        for ($i=1; $i <= $total_workers; $i++) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ? AND worker_id = ?");
            $stmt->execute([$user_id, $i]);
            if ($stmt->fetchColumn() == 0) {
                $worker_id = $i;
                break;
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM job_offers WHERE id = ? AND status = 'open' FOR UPDATE");
        $stmt->execute([$job_id]);
        $job = $stmt->fetch();

        if (!$job) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'This job is no longer available.'];
        }

        $pdo->prepare("UPDATE job_offers SET status = 'taken' WHERE id = ?")->execute([$job_id]);

        $completion_time = date('Y-m-d H:i:s', time() + TASK_DURATION_SECONDS);
        $stmt = $pdo->prepare("INSERT INTO worker_tasks (user_id, worker_id, task_type, completion_time, job_offer_id) VALUES (?, ?, 'Work on Job', ?, ?)");
        $stmt->execute([$user_id, $worker_id, $completion_time, $job_id]);

        log_action($pdo, $user_id, 'accept_job', "Accepted job #{$job_id}.");
        $pdo->commit();
        return ['success' => true, 'message' => "Job accepted! Worker #{$worker_id} has started the task."];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => 'Error accepting job: ' . $e->getMessage()];
    }
}

function get_market_data($pdo, $item_type) {
    $buy_stmt = $pdo->prepare("SELECT price, SUM(quantity) as quantity FROM market_orders WHERE order_type = 'buy' AND item_type = ? GROUP BY price ORDER BY price DESC");
    $buy_stmt->execute([$item_type]);
    $buys = $buy_stmt->fetchAll();

    $sell_stmt = $pdo->prepare("SELECT price, SUM(quantity) as quantity FROM market_orders WHERE order_type = 'sell' AND item_type = ? GROUP BY price ORDER BY price ASC");
    $sell_stmt->execute([$item_type]);
    $sells = $sell_stmt->fetchAll();

    return ['success' => true, 'data' => ['buys' => $buys, 'sells' => $sells]];
}

function place_market_order($pdo, $user_id, $order_type, $item_type, $quantity, $price) {
    if ($quantity <= 0 || $price <= 0) {
        return ['success' => false, 'message' => 'Invalid quantity or price.'];
    }
    $quantity = floor($quantity);
    $pdo->beginTransaction();

    if ($order_type == 'sell') {
        $stmt = $pdo->prepare("SELECT `$item_type` FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $balance = $stmt->fetchColumn();
        if ((float)$balance < $quantity) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "Not enough $item_type to sell."];
        }
        $stmt = $pdo->prepare("UPDATE users SET `$item_type` = `$item_type` - ? WHERE id = ?");
        $stmt->execute([$quantity, $user_id]);
    } else { // 'buy' order
        $total_cost = (float)$quantity * (float)$price;
        $stmt = $pdo->prepare("SELECT (nano_unearned_balance + nano_earned_balance) as total_currency FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        if((float)$stmt->fetchColumn() < $total_cost) {
            $pdo->rollBack();
            return ['success' => false, 'message' => "Not enough currency to place buy order."];
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO market_orders (user_id, order_type, item_type, quantity, price) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $order_type, $item_type, $quantity, $price]);
    
    log_action($pdo, $user_id, 'place_order', "Placed a $order_type order for $quantity $item_type at $price currency each.");
    $pdo->commit();
    return ['success' => true, 'message' => 'Order placed successfully.'];
}


function matchMarketOrders($pdo, $item_type) {
    while (true) {
        $pdo->beginTransaction();
        
        $buy_order_stmt = $pdo->prepare("SELECT * FROM market_orders WHERE order_type = 'buy' AND item_type = ? ORDER BY price DESC, created_at ASC LIMIT 1 FOR UPDATE");
        $buy_order_stmt->execute([$item_type]);
        $buy_order = $buy_order_stmt->fetch();

        $sell_order_stmt = $pdo->prepare("SELECT * FROM market_orders WHERE order_type = 'sell' AND item_type = ? ORDER BY price ASC, created_at ASC LIMIT 1 FOR UPDATE");
        $sell_order_stmt->execute([$item_type]);
        $sell_order = $sell_order_stmt->fetch();

        if (!$buy_order || !$sell_order || (float)$buy_order['price'] < (float)$sell_order['price']) {
            $pdo->commit();
            break;
        }

        $trade_quantity = min($buy_order['quantity'], $sell_order['quantity']);
        $trade_price = (float)$sell_order['price'];
        $total_cost = $trade_quantity * $trade_price;
        
        $buyer_stmt = $pdo->prepare("SELECT id, username, nano_unearned_balance, nano_earned_balance FROM users WHERE id = ? FOR UPDATE");
        $buyer_stmt->execute([$buy_order['user_id']]);
        $buyer = $buyer_stmt->fetch();

        $seller_stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
        $seller_stmt->execute([$sell_order['user_id']]);
        $seller = $seller_stmt->fetch();

        if (((float)$buyer['nano_unearned_balance'] + (float)$buyer['nano_earned_balance']) < $total_cost) {
            log_action($pdo, $buyer['id'], 'market_fail', "Failed to buy $trade_quantity $item_type due to insufficient funds.");
            $pdo->commit();
            break; 
        }

        process_payment($pdo, $buyer['id'], $total_cost, "Bought $trade_quantity $item_type from market.");

        $net_income = apply_taxes($pdo, $seller['id'], $total_cost, 'nano_earned_balance', "market sale of $item_type");
        $pdo->prepare("UPDATE users SET nano_earned_balance = nano_earned_balance + ? WHERE id = ?")->execute([$net_income, $seller['id']]);
        log_transaction($pdo, $seller['id'], 'market_sale', $net_income, "Sold $trade_quantity $item_type on market.");

        $pdo->prepare("UPDATE users SET `$item_type` = `$item_type` + ? WHERE id = ?")->execute([$trade_quantity, $buyer['id']]);

        if ($buy_order['quantity'] == $trade_quantity) {
            $pdo->prepare("DELETE FROM market_orders WHERE id = ?")->execute([$buy_order['id']]);
        } else {
            $pdo->prepare("UPDATE market_orders SET quantity = quantity - ? WHERE id = ?")->execute([$trade_quantity, $buy_order['id']]);
        }

        if ($sell_order['quantity'] == $trade_quantity) {
            $pdo->prepare("DELETE FROM market_orders WHERE id = ?")->execute([$sell_order['id']]);
        } else {
            $pdo->prepare("UPDATE market_orders SET quantity = quantity - ? WHERE id = ?")->execute([$trade_quantity, $sell_order['id']]);
        }

        log_action($pdo, $buyer['id'], 'market_trade', "Bought $trade_quantity $item_type for $total_cost currency from {$seller['username']}.");
        log_action($pdo, $seller['id'], 'market_trade', "Sold $trade_quantity $item_type for $total_cost currency to {$buyer['username']}.");
        
        $pdo->commit();
    }
}


function get_history($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT description, timestamp FROM action_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 100");
    $stmt->execute([$user_id]);
    $logs = $stmt->fetchAll();
    return ['success' => true, 'data' => $logs];
}

function handle_get_tax_history($pdo) {
    $query = "SELECT u.username, a.description, a.timestamp 
              FROM action_logs a
              JOIN users u ON a.user_id = u.id
              WHERE a.action_type = 'tax_payment'
              ORDER BY a.timestamp DESC 
              LIMIT 200";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $data = $stmt->fetchAll();
    
    return ['success' => true, 'data' => $data];
}

function handle_get_government_data($pdo) {
    $stmt = $pdo->prepare("SELECT nano_earned_balance, nano_unearned_balance, wood, iron, stone FROM users WHERE id = ?");
    $stmt->execute([GOVERNMENT_USER_ID]);
    $data = $stmt->fetch();
    return ['success' => true, 'data' => $data];
}

function handle_get_distribution_data($pdo) {
    $stmt = $pdo->prepare("SELECT SUM(amount) as total_tax FROM transactions WHERE user_id = ? AND type = 'tax_income' AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute([GOVERNMENT_USER_ID]);
    $total = $stmt->fetchColumn();
    return ['success' => true, 'data' => ['total_tax_24h' => $total ?? 0]];
}

function handle_get_distribution_history($pdo) {
    $query = "SELECT u.username, a.description, a.timestamp 
              FROM action_logs a
              JOIN users u ON a.user_id = u.id
              WHERE a.action_type = 'tax_distribution'
              ORDER BY a.timestamp DESC 
              LIMIT 200";
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $logs = $stmt->fetchAll();

    $data = [];
    foreach ($logs as $log) {
        if (preg_match("/of ([\d\.]+) ([\w\s]+)\./", $log['description'], $matches)) {
            $data[] = [
                'username' => $log['username'],
                'amount' => $matches[1],
                'asset_type' => ucwords($matches[2]),
                'description' => $log['description'],
                'timestamp' => $log['timestamp']
            ];
        }
    }

    return ['success' => true, 'data' => $data];
}

function handle_get_harvesting_activity($pdo, $timeframe) {
    $where_clause = "";
    if ($timeframe === '24_hours') {
        $where_clause = "AND al.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    }

    $query = "SELECT u.username, COUNT(al.id) as action_count
              FROM users u
              LEFT JOIN action_logs al ON u.id = al.user_id AND al.action_type IN ('Harvest Wood', 'Harvest Iron', 'Harvest Stone') $where_clause
              WHERE u.id != ?
              GROUP BY u.id, u.username
              ORDER BY action_count DESC
              LIMIT 100";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([GOVERNMENT_USER_ID]);
    $players = $stmt->fetchAll();

    $ranked_players = [];
    foreach ($players as $index => $player) {
        $player['rank'] = $index + 1;
        $ranked_players[] = $player;
    }
    
    return ['success' => true, 'data' => $ranked_players];
}

function handle_get_leaderboard($pdo, $sort_by) {
    $select_clause = "u.username, ";
    $from_clause = "FROM users u";
    $where_clause = "WHERE u.id != " . GOVERNMENT_USER_ID;
    $order_by_clause = "";
    $group_by_clause = "";

    switch ($sort_by) {
        case 'military_power':
            $select_clause .= "(FLOOR(LEAST(u.soldiers, u.sword) * (1 + (b.level / 100)))) as score";
            $from_clause .= " LEFT JOIN buildings b ON u.id = b.user_id AND b.building_type = 'Barracks'";
            $order_by_clause = "ORDER BY score DESC, u.id ASC";
            break;
        case 'sword':
        case 'workers':
            $select_clause .= "u.$sort_by as score";
            $order_by_clause = "ORDER BY score DESC, u.id ASC";
            break;
        case 'prod_today':
        case 'prod_7_days':
        case 'prod_30_days':
        case 'prod_all_time':
            $select_clause .= "COUNT(al.id) as score";
            $from_clause .= " LEFT JOIN action_logs al ON u.id = al.user_id AND al.action_type IN ('Harvest Wood', 'Harvest Iron', 'Harvest Stone', 'job_completed')";
            if ($sort_by === 'prod_today') $where_clause .= " AND al.timestamp >= CURDATE()";
            if ($sort_by === 'prod_7_days') $where_clause .= " AND al.timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            if ($sort_by === 'prod_30_days') $where_clause .= " AND al.timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $group_by_clause = "GROUP BY u.id, u.username";
            $order_by_clause = "ORDER BY score DESC, u.id ASC";
            break;
        case 'harvest_total':
        case 'harvest_wood_total':
        case 'harvest_iron_total':
        case 'harvest_stone_total':
        case 'swords_produced_total':
            $select_clause .= "SUM(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.description, ' ', -2), ' ', 1) AS DECIMAL(10,4))) as score";
            $from_clause .= " JOIN action_logs al ON u.id = al.user_id";
            if($sort_by === 'harvest_wood_total') $where_clause .= " AND al.action_type = 'Harvest Wood'";
            if($sort_by === 'harvest_iron_total') $where_clause .= " AND al.action_type = 'Harvest Iron'";
            if($sort_by === 'harvest_stone_total') $where_clause .= " AND al.action_type = 'Harvest Stone'";
            if($sort_by === 'harvest_total') $where_clause .= " AND al.action_type IN ('Harvest Wood', 'Harvest Iron', 'Harvest Stone')";
            if($sort_by === 'swords_produced_total') $where_clause .= " AND al.action_type = 'job_completed'";
            $group_by_clause = "GROUP BY u.id, u.username";
            $order_by_clause = "ORDER BY score DESC, u.id ASC";
            break;
        case 'net_worth':
        default:
            $select_clause .= "(u.nano_unearned_balance + u.nano_earned_balance + (u.wood * 0.1) + (u.iron * 0.2) + (u.stone * 0.15) + (u.sword * 1.0)) as score";
            $order_by_clause = "ORDER BY score DESC, u.id ASC";
            break;
    }

    $query = "SELECT " . $select_clause . " " . $from_clause . " " . $where_clause . " " . $group_by_clause . " " . $order_by_clause . " LIMIT 100";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $players = $stmt->fetchAll();

    $ranked_players = [];
    foreach ($players as $index => $player) {
        $player['rank'] = $index + 1;
        $player['score'] = $player['score'] ?? 0;
        $ranked_players[] = $player;
    }
    
    return ['success' => true, 'data' => $ranked_players];
}

function handle_get_conquer_data($pdo, $user_id) {
    $pdo->exec("SET time_zone = '+03:00'");
    
    $my_power = get_army_power($pdo, $user_id);
    
    // Get players I occupy and calculate used power
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE occupied_by = ?");
    $stmt->execute([$user_id]);
    $occupied_players_raw = $stmt->fetchAll();

    $used_power = 0;
    $occupied_players = [];
    foreach ($occupied_players_raw as $p) {
        $power = get_army_power($pdo, $p['id']);
        $used_power += $power;
        $occupied_players[] = ['id' => $p['id'], 'username' => $p['username'], 'army_power' => $power];
    }
    
    $unused_power = $my_power - $used_power;

    // Get all potential targets
    $stmt = $pdo->prepare("SELECT id, username, occupied_by FROM users WHERE id NOT IN (?, ?)");
    $stmt->execute([$user_id, GOVERNMENT_USER_ID]);
    $all_other_players = $stmt->fetchAll();

    $potential_targets = [];
    foreach($all_other_players as $p) {
        $target_power = get_army_power($pdo, $p['id']);
        $target_data = ['id' => $p['id'], 'username' => $p['username'], 'army_power' => $target_power];

        if ($p['occupied_by'] !== null) {
            $target_data['status'] = 'Already Occupied';
        } elseif ($unused_power <= $target_power) {
            $target_data['status'] = 'Power Too Low';
        } else {
            // Check for protection
            $stmt = $pdo->prepare("SELECT * FROM occupation_history WHERE occupied_id = ? AND action = 'liberate' ORDER BY timestamp DESC LIMIT 1");
            $stmt->execute([$p['id']]);
            $last_liberation = $stmt->fetch();

            $is_protected = false;
            if ($last_liberation) {
                $now = new DateTime();
                $liberation_time = new DateTime($last_liberation['timestamp']);
                
                // General 14-day protection
                $protection_end_14 = (clone $liberation_time)->add(new DateInterval('P14D'));
                if ($now < $protection_end_14) {
                    $is_protected = true;
                    $target_data['status'] = 'protected';
                    $target_data['protection_end_time'] = $protection_end_14->format('Y-m-d H:i:s');
                }

                // Specific 30-day protection
                if ($last_liberation['occupier_id'] == $user_id) {
                    $protection_end_30 = (clone $liberation_time)->add(new DateInterval('P30D'));
                    if ($now < $protection_end_30) {
                        $is_protected = true;
                        $target_data['status'] = 'protected';
                        $target_data['protection_end_time'] = $protection_end_30->format('Y-m-d H:i:s');
                    }
                }
            }

            if (!$is_protected) {
                $target_data['status'] = 'conquerable';
            }
        }
        $potential_targets[] = $target_data;
    }

    return [
        'success' => true,
        'data' => [
            'total_power' => $my_power,
            'used_power' => $used_power,
            'unused_power' => $unused_power,
            'occupied_players' => $occupied_players,
            'potential_targets' => $potential_targets
        ]
    ];
}

function handle_conquer_player($pdo, $user_id, $target_id) {
    if ($user_id == $target_id) {
        return ['success' => false, 'message' => 'You cannot conquer yourself.'];
    }

    $pdo->beginTransaction();
    try {
        // Re-run all checks from get_conquer_data server-side to prevent manipulation
        $my_power = get_army_power($pdo, $user_id);
        
        $stmt_used = $pdo->prepare("SELECT id FROM users WHERE occupied_by = ?");
        $stmt_used->execute([$user_id]);
        $occupied_players_raw = $stmt_used->fetchAll();
        $used_power = 0;
        foreach ($occupied_players_raw as $p) {
            $used_power += get_army_power($pdo, $p['id']);
        }
        $unused_power = $my_power - $used_power;

        $stmt_target = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_target->execute([$target_id]);
        $target = $stmt_target->fetch();
        $target_power = get_army_power($pdo, $target_id);

        if (!$target || $target['id'] == GOVERNMENT_USER_ID) {
            throw new Exception("Invalid target.");
        }
        if ($target['occupied_by'] !== null) {
            throw new Exception("This player is already occupied.");
        }
        if ($unused_power <= $target_power) {
            throw new Exception("Your unused army power is too low to conquer this player.");
        }

        // Check protections
        $stmt_prot = $pdo->prepare("SELECT * FROM occupation_history WHERE occupied_id = ? AND action = 'liberate' ORDER BY timestamp DESC LIMIT 1");
        $stmt_prot->execute([$target_id]);
        $last_liberation = $stmt_prot->fetch();
        if ($last_liberation) {
            $now = new DateTime();
            $liberation_time = new DateTime($last_liberation['timestamp']);
            
            if ($now < (clone $liberation_time)->add(new DateInterval('P14D'))) {
                throw new Exception("This player is under a general protection period.");
            }
            if ($last_liberation['occupier_id'] == $user_id && $now < (clone $liberation_time)->add(new DateInterval('P30D'))) {
                throw new Exception("You cannot re-conquer this player so soon after liberating them.");
            }
        }
        
        // If all checks pass, conquer the player
        $stmt_update = $pdo->prepare("UPDATE users SET occupied_by = ? WHERE id = ?");
        $stmt_update->execute([$user_id, $target_id]);

        $stmt_log = $pdo->prepare("INSERT INTO occupation_history (occupier_id, occupied_id, action) VALUES (?, ?, 'conquer')");
        $stmt_log->execute([$user_id, $target_id]);

        log_action($pdo, $user_id, 'conquer', "You have conquered player {$target['username']}.");
        log_action($pdo, $target_id, 'conquered', "You have been conquered by another player.");
        
        $pdo->commit();
        return ['success' => true, 'message' => "You have successfully conquered {$target['username']}!"];

    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function handle_liberate_player($pdo, $user_id, $target_id) {
    $pdo->beginTransaction();
    try {
        $stmt_target = $pdo->prepare("SELECT username, occupied_by FROM users WHERE id = ?");
        $stmt_target->execute([$target_id]);
        $target = $stmt_target->fetch();

        if (!$target || $target['occupied_by'] != $user_id) {
            throw new Exception("You are not occupying this player.");
        }

        $stmt_update = $pdo->prepare("UPDATE users SET occupied_by = NULL WHERE id = ?");
        $stmt_update->execute([$target_id]);

        $stmt_log = $pdo->prepare("INSERT INTO occupation_history (occupier_id, occupied_id, action) VALUES (?, ?, 'liberate')");
        $stmt_log->execute([$user_id, $target_id]);

        log_action($pdo, $user_id, 'liberate', "You have liberated player {$target['username']}.");
        log_action($pdo, $target_id, 'liberated', "You have been liberated.");

        $pdo->commit();
        return ['success' => true, 'message' => "You have liberated {$target['username']}."];
    } catch(Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

?>
