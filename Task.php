<?php
// Project: Nano Empire Task Class
// Handles worker assignment and task completion (harvesting and job completion). Uses legacy PHP syntax.

class Task {
    
    private $pdo;
    private $logger;
    private $resourceManager;
    private $user;

    /**
     * Task constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $resource_manager_instance, $user_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->resourceManager = $resource_manager_instance;
        $this->user = $user_instance;
    }

    /**
     * Calculates the production yield for a task type based on player productivity.
     * @param int $user_id
     * @param string $task_type (e.g., 'Harvest Wood')
     * @return float The calculated final yield (pre-tax).
     */
    private function calculateYield($user_id, $task_type) {
        // Fetch necessary data: action count for exp, Dormitory level for building bonus
        $resource_name_lower = strtolower(str_replace('Harvest ', '', $task_type));
        $data = $this->user->getProductionData($user_id, $task_type);
        
        $actions = $data[$resource_name_lower]['actions'];
        $dorm_level = $data['dormitory_level'];
        
        // Experience Bonus Calculation
        $exp_level = floor(sqrt((float)$actions));
        $exp_bonus = 1.0 + ((float)$exp_level / 100.0); // The experience bonus is "one plus the experience level divided by 100"

        // Building Bonus Calculation
        $building_bonus = 1.0 + ((float)$dorm_level / 100.0); // The building bonus is "one plus the Dormitory level divided by 100"

        // Final Yield: "base yield (1) multiplied by the experience bonus and then multiplied by the building bonus"
        $yield = 1.0 * (float)$exp_bonus * (float)$building_bonus;
        
        return $yield;
    }

    /**
     * Assigns an idle worker to a new task.
     * @param int $user_id
     * @param string $task_type
     * @param int $worker_id
     * @return array API response.
     */
    public function startTask($user_id, $task_type, $worker_id) {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ? AND worker_id = ?");
        $stmt->execute(array($user_id, $worker_id));
        if ($stmt->fetchColumn() > 0) {
            return array('success' => false, 'message' => 'Worker is already busy.');
        }
        
        $completion_time = date('Y-m-d H:i:s', time() + TASK_DURATION_SECONDS);
        $stmt = $this->pdo->prepare("INSERT INTO worker_tasks (user_id, worker_id, task_type, completion_time) VALUES (?, ?, ?, ?)");
        if ($stmt->execute(array($user_id, $worker_id, $task_type, $completion_time))) {
            return array('success' => true, 'message' => 'Task started.');
        }
        return array('success' => false, 'message' => 'Failed to start task.');
    }
    
    /**
     * Processes the oldest completed task for the user, granting rewards and logging the action.
     * @param int $user_id
     * @return array API response.
     */
    public function completeTask($user_id) {
        $this->pdo->beginTransaction();
        
        $stmt = $this->pdo->prepare("SELECT id, task_type, job_offer_id FROM worker_tasks WHERE user_id = ? AND completion_time <= NOW() ORDER BY completion_time ASC LIMIT 1 FOR UPDATE");
        $stmt->execute(array($user_id));
        $task = $stmt->fetch();

        if (!$task) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'No completed tasks found.');
        }
        
        $log_id = 0; // Initialize log ID for later update

        if ($task['job_offer_id']) { 
            // --- Handle Job Completion ---
            $job_stmt = $this->pdo->prepare("SELECT * FROM job_offers WHERE id = ? FOR UPDATE");
            $job_stmt->execute(array($task['job_offer_id']));
            $job = $job_stmt->fetch();
            
            if ($job && $job['status'] == 'taken') {
                // Transfer produced item (Sword) to employer
                $this->resourceManager->updateResource($job['employer_id'], $job['item_to_produce'], (float)$job['quantity']);
                
                // Process salary payment and taxes (gross salary is already in escrow)
                $net_salary = $this->resourceManager->applyTaxes($user_id, $job['salary'], CURRENCY_EARNED, "salary for job #" . $job['id']);
                
                // Credit net salary to worker
                $this->resourceManager->updateResource($user_id, CURRENCY_EARNED, $net_salary);
                $this->logger->logTransaction($user_id, 'job_payout', $net_salary, "Received net salary for job #" . $job['id'] . ".");
                
                // Mark job as completed
                $this->pdo->prepare("UPDATE job_offers SET status = 'completed' WHERE id = ?")->execute(array($job['id']));

                $this->logger->logAction($user_id, 'job_completed', "Completed job #{$job['id']} for {$job['quantity']} swords and earned a salary of " . number_format($net_salary, 8) . " currency.");
                $this->logger->logAction($job['employer_id'], 'job_production', "Your job #{$job['id']} was completed, you received {$job['quantity']} {$job['item_to_produce']}(s).");
            }

        } else if (strpos($task['task_type'], 'Harvest') !== false) {
            // --- Handle Harvest Task Completion ---
            $resource_type = strtolower(str_replace('Harvest ', '', $task['task_type']));
            
            // 1. Log action temporarily (required to correctly calculate yield/exp level if this is the N-th harvest)
            $this->logger->logAction($user_id, $task['task_type'], "Placeholder for yield calculation.");
            $log_id = $this->pdo->lastInsertId();

            // 2. Calculate Gross Yield
            $yield = $this->calculateYield($user_id, $task['task_type']);

            // 3. Apply Taxes & Distribution
            $net_yield = $this->resourceManager->applyTaxes($user_id, $yield, $resource_type, "harvesting $resource_type");
            
            // 4. Credit Net Yield to user
            $this->resourceManager->updateResource($user_id, $resource_type, $net_yield);

            // 5. Update log description with final figures
            $this->pdo->prepare("UPDATE action_logs SET description = ? WHERE id = ?")->execute(array("Completed harvesting, gained " . number_format($net_yield, 8) . " {$resource_type}.", $log_id));
        }

        // Remove task
        $this->pdo->prepare("DELETE FROM worker_tasks WHERE id = ?")->execute(array($task['id']));
        $this->pdo->commit();
        return array('success' => true, 'message' => 'Task completed successfully!');
    }
}
?>
