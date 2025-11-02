<?php
// Project: Nano Empire API Router (Controller)
// This is the single entry point. It loads all OOP components and dispatches requests.
// Uses legacy PHP syntax.

session_start();
header('Content-Type: application/json');

// --- File Includes (All files in root directory per Golden Rule) ---
require_once 'Config.php';
require_once 'Database.php';
require_once 'Logger.php';
require_once 'Resource.php';
require_once 'Military.php';
require_once 'User.php';
require_once 'Building.php';
require_once 'Task.php';
require_once 'Job.php';
require_once 'Market.php';
require_once 'Conquest.php';
require_once 'Leaderboard.php';

// --- Initialization ---
$pdo = Database::getConnection();
$response = array('success' => false, 'message' => 'Invalid action.');

// Initialize Core Manager Classes
$logger = new Logger($pdo);
$resourceManager = new Resource($pdo, $logger);
$militaryManager = new Military($pdo, $logger, $resourceManager);
$user = new User($pdo, $logger, $militaryManager);

// Initialize Feature Classes (Dependent on Core Managers)
$buildingManager = new Building($pdo, $logger, $resourceManager, $user);
$taskManager = new Task($pdo, $logger, $resourceManager, $user);
$jobManager = new Job($pdo, $logger, $resourceManager, $user, $taskManager);
$marketManager = new Market($pdo, $logger, $resourceManager, $user);
$conquestManager = new Conquest($pdo, $logger, $militaryManager, $user);
$leaderboardManager = new Leaderboard($pdo);


// --- Main API Router ---
$action = $_POST['action'] ?? '';

// Routes that do not require authentication
$public_actions = array('login', 'register', 'logout');
if (!in_array($action, $public_actions) && !$user->isLoggedIn()) {
    echo json_encode(array('success' => false, 'message' => 'Authentication required.', 'auth_error' => true));
    exit;
}

$user_id = $user->getUserId();

try {
    switch ($action) {
        case 'register':
            $response = $user->register($_POST['username'], $_POST['password'], $_POST['email']);
            break;
        case 'login':
            $response = $user->login($_POST['username'], $_POST['password']);
            break;
        case 'logout':
            session_destroy();
            $response = array('success' => true);
            break;
        case 'get_user_data':
            $response = $user->getUserData($user_id);
            break;
        case 'start_task':
            $response = $taskManager->startTask($user_id, $_POST['task_type'], $_POST['worker_id']);
            break;
        case 'complete_task':
            $response = $taskManager->completeTask($user_id);
            break;
        case 'upgrade_building':
            $response = $buildingManager->upgradeBuilding($user_id, $_POST['building_type']);
            break;
        case 'recruit_soldiers':
            $response = $militaryManager->recruitSoldiers($user_id);
            break;
        case 'post_job_offer':
            $response = $jobManager->postJobOffer($user_id, $_POST['item'], $_POST['quantity'], $_POST['salary']);
            break;
        case 'get_job_offers':
            $response = $jobManager->getJobOffers($user_id);
            break;
        case 'accept_job':
            $response = $jobManager->acceptJob($user_id, $_POST['job_id']);
            break;
        case 'place_market_order':
            $response = $marketManager->placeMarketOrder($user_id, $_POST['order_type'], $_POST['item_type'], $_POST['quantity'], $_POST['price']);
            if ($response['success']) {
                $marketManager->matchMarketOrders($_POST['item_type']);
            }
            break;
        case 'get_market_data':
            $response = $marketManager->getMarketData($_POST['item_type']);
            break;
        case 'get_history':
            $response = $leaderboardManager->getHistory($user_id);
            break;
        case 'get_tax_history':
            $response = $leaderboardManager->getTaxHistory();
            break;
        case 'get_government_data':
            $response = $leaderboardManager->getGovernmentData();
            break;
        case 'get_distribution_data':
            $response = $leaderboardManager->getDistributionData();
            break;
        case 'get_distribution_history':
            $response = $leaderboardManager->getDistributionHistory();
            break;
        case 'get_harvesting_activity':
            $response = $leaderboardManager->getHarvestingActivity($_POST['timeframe'] ?? '24_hours');
            break;
        case 'get_leaderboard':
            $response = $leaderboardManager->getLeaderboard($_POST['sort_by'] ?? 'net_worth');
            break;
        case 'get_conquer_data':
            $response = $conquestManager->getConquerData($user_id);
            break;
        case 'conquer_player':
            $response = $conquestManager->conquerPlayer($user_id, $_POST['target_id']);
            break;
        case 'liberate_player':
            $response = $conquestManager->liberatePlayer($user_id, $_POST['target_id']);
            break;
        default:
            $response = array('success' => false, 'message' => 'Unknown action.');
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    $response = array('success' => false, 'message' => 'An internal server error occurred: ' . $e->getMessage());
}

echo json_encode($response);
exit;
?>
