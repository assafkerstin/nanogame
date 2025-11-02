<?php
// Project: Nano Empire User Class
// Handles authentication, registration, and aggregates all necessary data for the frontend.
// Uses legacy PHP syntax.

class User {
    
    private $pdo;
    private $logger;
    private $militaryManager;
    private $userId = null;
    private $userData = null;

    /**
     * User constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $military_manager_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->militaryManager = $military_manager_instance;
        
        if (isset($_SESSION['user_id'])) {
            $this->userId = (int)$_SESSION['user_id'];
        }
    }

    /**
     * Checks if a user session is active.
     * @return bool
     */
    public function isLoggedIn() {
        return $this->userId !== null;
    }
    
    /**
     * Gets the authenticated user ID.
     * @return int|null
     */
    public function getUserId() {
        return $this->userId;
    }

    /**
     * Retrieves a single user row from the database.
     * @param int $user_id
     * @param bool $for_update Lock the row if true (for transactions)
     * @return array|false
     */
    public function getUserRow($user_id, $for_update = false) {
        $lock = $for_update ? " FOR UPDATE" : "";
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?" . $lock);
        $stmt->execute(array($user_id));
        return $stmt->fetch();
    }

    /**
     * Handles account registration, initializing starting resources and buildings.
     * @param string $username
     * @param string $password
     * @param string $email
     * @return array API response.
     */
    public function register($username, $password, $email) {
        if (empty($username) || empty($password) || empty($email)) {
            return array('success' => false, 'message' => 'All fields are required.');
        }

        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute(array($username, $email));
        if ($stmt->fetch()) {
            return array('success' => false, 'message' => 'Username or email already exists.');
        }

        $this->pdo->beginTransaction();
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
            
            if (!$stmt->execute(array($username, $hashed_password, $email))) {
                 throw new Exception("Registration query failed.");
            }
            $user_id = $this->pdo->lastInsertId();
            
            // Initialize buildings
            $this->pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Dormitory', 1)")->execute(array($user_id));
            $this->pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Barracks', 0)")->execute(array($user_id));
            $this->pdo->prepare("INSERT INTO buildings (user_id, building_type, level) VALUES (?, 'Blacksmith', 0)")->execute(array($user_id));
            
            $this->logger->logAction($user_id, 'register', 'User account created and fiefdom established.');
            $this->pdo->commit();
            return array('success' => true, 'message' => 'Registration successful. You can now log in.');

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Registration failed: ' . $e->getMessage());
        }
    }

    /**
     * Handles user login and session creation.
     * @param string $username
     * @param string $password
     * @return array API response.
     */
    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute(array($username));
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $this->userId = (int)$user['id'];
            $this->logger->logAction($this->userId, 'login', 'User logged in successfully.');
            return array('success' => true);
        }
        return array('success' => false, 'message' => 'Invalid username or password.');
    }

    /**
     * Retrieves production data (actions, dormitory level) needed for yield calculation.
     * This is separate from getUserData to allow other classes (like Task) to fetch data efficiently.
     * @param int $user_id
     * @param string $task_type Optional specific task type to analyze (e.g., 'Harvest Wood')
     * @return array
     */
    public function getProductionData($user_id, $task_type = null) {
        $data = array();
        
        // 1. Get action counts for EXP calculation
        $action_counts_stmt = $this->pdo->prepare("SELECT action_type, COUNT(*) as count FROM action_logs WHERE user_id = ? AND action_type IN ('" . RESOURCE_WOOD_HARVEST . "', '" . RESOURCE_IRON_HARVEST . "', '" . RESOURCE_STONE_HARVEST . "', 'job_completed') GROUP BY action_type");
        $action_counts_stmt->execute(array($user_id));
        $counts = array_column($action_counts_stmt->fetchAll(), 'count', 'action_type');
        
        // 2. Get Dormitory Level for building bonus
        $dorm_stmt = $this->pdo->prepare("SELECT level FROM buildings WHERE user_id = ? AND building_type = 'Dormitory'");
        $dorm_stmt->execute(array($user_id));
        $dorm_level = (int)$dorm_stmt->fetchColumn() ?: 0;
        $building_bonus_multiplier = 1.0 + ((float)$dorm_level / 100.0);
        
        $data['dormitory_level'] = $dorm_level;
        $data['dormitory_multiplier'] = $building_bonus_multiplier;

        $task_types = array(RESOURCE_WOOD_HARVEST => RESOURCE_WOOD, RESOURCE_IRON_HARVEST => RESOURCE_IRON, RESOURCE_STONE_HARVEST => RESOURCE_STONE);

        foreach($task_types as $task_name => $item_name) {
            $item_name_lower = strtolower($item_name);
            $actions = $counts[$task_name] ?? 0;
            $exp_level = floor(sqrt((float)$actions));
            $exp_bonus_multiplier = 1.0 + ((float)$exp_level / 100.0);
            
            $data[$item_name_lower] = array(
                'actions' => $actions,
                'exp_level' => $exp_level,
                'current_bonus_perc' => $exp_level,
                'actions_for_next_level' => pow($exp_level + 1, 2), // Next level squared
                'next_level_bonus_perc' => $exp_level + 1,
                'total_yield' => 1.0 * $exp_bonus_multiplier * $building_bonus_multiplier // Base yield (1) * EXP * Building
            );
        }
        
        return $data;
    }

    /**
     * Fetches the complete, aggregated game state for the frontend upon login or refresh.
     * @param int $user_id
     * @return array API response.
     */
    public function getUserData($user_id) {
        $this->pdo->exec("SET time_zone = '+03:00'");
        
        $data = $this->getUserRow($user_id);
        if (!$data) {
            session_destroy();
            return array('success' => false, 'message' => 'User not found.', 'auth_error' => true);
        }
        unset($data['password']); // Never send password hash

        // 1. Buildings
        $stmt = $this->pdo->prepare("SELECT building_type, level FROM buildings WHERE user_id = ?");
        $stmt->execute(array($user_id));
        $data['buildings'] = array();
        foreach ($stmt->fetchAll() as $building) {
            $data['buildings'][$building['building_type']] = (int)$building['level'];
        }

        // 2. Worker Tasks
        $stmt = $this->pdo->prepare("SELECT worker_id, task_type, completion_time FROM worker_tasks WHERE user_id = ?");
        $stmt->execute(array($user_id));
        $data['worker_tasks'] = $stmt->fetchAll();

        // 3. Military Power Breakdown
        $barracks_level = $data['buildings']['Barracks'] ?? 0;
        $soldiers = (float)$data['soldiers'];
        $swords = (float)$data['sword'];
        $base_power = min($soldiers, $swords);
        $total_power = $this->militaryManager->getArmyPower($user_id);

        $data['military_power_breakdown'] = array(
            'base' => $base_power,
            'bonus_perc' => (int)$barracks_level, // Barracks level is the bonus percentage
            'total' => $total_power
        );
        $data['soldiers'] = (int)$data['soldiers'];
        $data['military_power'] = $total_power;
        $data['soldier_recruit_cost'] = $this->militaryManager->getRecruitCost((int)$data['soldiers']);
        
        // 4. Server Constants
        $data['task_duration_seconds'] = TASK_DURATION_SECONDS;

        // 5. Productivity Details
        $prod_data = $this->getProductionData($user_id);
        $data['productivity'] = array();
        
        $task_types = array(RESOURCE_WOOD, RESOURCE_IRON, RESOURCE_STONE);
        foreach($task_types as $resource_name) {
             $data['productivity'][$resource_name] = $prod_data[strtolower($resource_name)];
             // Add building bonus to all resources (Dormitory is universal)
             $data['productivity'][$resource_name]['building_bonus_perc'] = $prod_data['dormitory_level'];
        }
        
        // 6. Production Summary
        $prod_actions = array(RESOURCE_WOOD_HARVEST, RESOURCE_IRON_HARVEST, RESOURCE_STONE_HARVEST, 'job_completed');
        $stmt = $this->pdo->prepare("SELECT action_type, description FROM action_logs WHERE user_id = ? AND action_type IN ('" . implode("','", $prod_actions) . "')");
        $stmt->execute(array($user_id));
        $logs = $stmt->fetchAll();
        
        $data['prod_summary'] = array(
            'total_actions' => count($logs),
            'total_harvested' => 0.0,
            'total_swords_produced' => 0
        );

        foreach ($logs as $log) {
            if (strpos($log['action_type'], 'Harvest') !== false) {
                // Parse harvested amount: "Completed harvesting, gained X resource."
                if (preg_match("/gained ([\d\.]+) \w+/", $log['description'], $matches)) {
                    $data['prod_summary']['total_harvested'] += (float)$matches[1];
                }
            } elseif ($log['action_type'] === 'job_completed') {
                // Parse produced sword amount: "Completed job #X for Y swords"
                if (preg_match("/for (\d+) swords/", $log['description'], $matches)) {
                    $data['prod_summary']['total_swords_produced'] += (int)$matches[1];
                }
            }
        }
        
        // 7. 24-Hour Activity for Dashboard
        $prod_actions_str = "'" . implode("','", $prod_actions) . "'";
        $stmt_all = $this->pdo->prepare("SELECT COUNT(*) FROM action_logs WHERE action_type IN (" . $prod_actions_str . ") AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt_all->execute();
        $all_users_actions = (int)$stmt_all->fetchColumn();

        $stmt_user = $this->pdo->prepare("SELECT COUNT(*) FROM action_logs WHERE user_id = ? AND action_type IN (" . $prod_actions_str . ") AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt_user->execute(array($user_id));
        $current_user_actions = (int)$stmt_user->fetchColumn();

        $data['activity_24h'] = array(
            'all_users_actions' => $all_users_actions,
            'user_share' => ($all_users_actions > 0) ? ((float)$current_user_actions / (float)$all_users_actions) * 100.0 : 0.0
        );

        // Convert key resources to floats for consistent output formatting
        $data[RESOURCE_WOOD] = (float)$data[RESOURCE_WOOD];
        $data[RESOURCE_IRON] = (float)$data[RESOURCE_IRON];
        $data[RESOURCE_STONE] = (float)$data[RESOURCE_STONE];
        $data[RESOURCE_SWORD] = (float)$data[RESOURCE_SWORD];
        $data[CURRENCY_EARNED] = (float)$data[CURRENCY_EARNED];
        $data[CURRENCY_UNEARNED] = (float)$data[CURRENCY_UNEARNED];

        return array('success' => true, 'data' => $data);
    }
}
?>
