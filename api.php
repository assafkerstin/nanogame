<?php
session_start();
header('Content-Type: application/json');

// --- Global Constants and Class Includes ---
// As per Golden Rule 6, all files are required from the same root folder.
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
try {
    $pdo = Database::getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'message' => 'Database connection failed.'));
    exit;
}

$action = $_POST['action'] ?? '';
$response = array('success' => false, 'message' => 'Invalid action.');

// Routes that do not require authentication
$public_actions = array('login', 'register', 'logout');
if (!in_array($action, $public_actions)) {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(array('success' => false, 'message' => 'Authentication required.', 'auth_error' => true));
        exit;
    }
}

$user_id = $_SESSION['user_id'] ?? 0;

// Initialize Core Services (Dependency Injection)
$logger = new Logger($pdo);
$resourceManager = new Resource($pdo, $logger);
$militaryManager = new Military($pdo, $logger, $resourceManager);

// Core Data/Logic handlers depend on lower-level services
$user = new User($pdo, $logger, $militaryManager);
$building = new Building($pdo, $logger, $resourceManager, $user);
$task = new Task($pdo, $logger, $resourceManager, $user);
$job = new Job($pdo, $logger, $resourceManager, $task, $user); // Added $task dependency
$market = new Market($pdo, $logger, $resourceManager);
$conquest = new Conquest($pdo, $logger, $militaryManager);
$leaderboard = new Leaderboard($pdo, $militaryManager);


// --- Main API Router (Dispatch) ---
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
            $response = $task->startTask($user_id, $_POST['task_type'], $_POST['worker_id']);
            break;
        case 'complete_task':
            $response = $task->completeTask($user_id);
            break;
        case 'upgrade_building':
            $response = $building->upgradeBuilding($user_id, $_POST['building_type']);
            break;
        case 'recruit_soldiers':
            $response = $militaryManager->recruitSoldiers($user_id);
            break;
        case 'post_job_offer':
            $response = $job->postJobOffer($user_id, $_POST['item'], $_POST['quantity'], $_POST['salary']);
            break;
        case 'get_job_offers':
            $response = $job->getJobOffers($user_id);
            break;
        case 'accept_job':
            $response = $job->acceptJob($user_id, $_POST['job_id']);
            break;
        case 'place_market_order':
            $response = $market->placeMarketOrder($user_id, $_POST['order_type'], $_POST['item_type'], $_POST['quantity'], $_POST['price']);
            if ($response['success']) {
                $market->matchMarketOrders($_POST['item_type']);
            }
            break;
        case 'get_market_data':
            $response = $market->getMarketData($_POST['item_type']);
            break;
        case 'get_history':
            $response = $leaderboard->getHistory($user_id);
            break;
        case 'get_tax_history':
            $response = $leaderboard->getTaxHistory();
            break;
        case 'get_government_data':
            $response = $leaderboard->getGovernmentData();
            break;
        case 'get_distribution_data':
            $response = $leaderboard->getDistributionData();
            break;
        case 'get_distribution_history':
            $response = $leaderboard->getDistributionHistory();
            break;
        case 'get_harvesting_activity':
            $response = $leaderboard->getHarvestingActivity($_POST['timeframe'] ?? '24_hours');
            break;
        case 'get_leaderboard':
            $response = $leaderboard->getLeaderboard($_POST['sort_by'] ?? 'net_worth');
            break;
        case 'get_conquer_data':
            $response = $conquest->getConquerData($user_id);
            break;
        case 'conquer_player':
            $response = $conquest->conquerPlayer($user_id, $_POST['target_id']);
            break;
        case 'liberate_player':
            $response = $conquest->liberatePlayer($user_id, $_POST['target_id']);
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
