<?php
// Project: Nano Empire Job Class
// Manages posting, listing, and accepting job market offers. Uses legacy PHP syntax.

class Job {
    
    private $pdo;
    private $logger;
    private $resourceManager;
    private $user;
    private $taskManager;

    /**
     * Job constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $resource_manager_instance, $user_instance, $task_manager_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->resourceManager = $resource_manager_instance;
        $this->user = $user_instance;
        $this->taskManager = $task_manager_instance;
    }

    /**
     * Retrieves all open job offers excluding those posted by the current user.
     * @param int $user_id
     * @return array API response.
     */
    public function getJobOffers($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT j.*, u.username as employer_username 
            FROM job_offers j 
            JOIN users u ON j.employer_id = u.id 
            WHERE j.status = 'open' AND j.employer_id != ? 
            ORDER BY j.created_at DESC
        ");
        $stmt->execute(array($user_id));
        $jobs = $stmt->fetchAll();
        return array('success' => true, 'data' => $jobs);
    }

    /**
     * Posts a new job offer, transferring cost (iron + salary) into escrow.
     * @param int $user_id
     * @param string $item
     * @param int $quantity
     * @param float $salary_per_item
     * @return array API response.
     */
    public function postJobOffer($user_id, $item, $quantity, $salary_per_item) {
        $quantity = (int)$quantity;
        $salary_per_item = (float)$salary_per_item;

        if ($item !== RESOURCE_SWORD || $quantity <= 0 || $salary_per_item <= 0) {
            return array('success' => false, 'message' => 'Invalid job offer details.');
        }

        $total_salary = (float)$quantity * $salary_per_item;
        $iron_cost = (float)$quantity;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT iron FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute(array($user_id));
            $user_resources = $stmt->fetch();

            if ((float)$user_resources[RESOURCE_IRON] < $iron_cost) {
                $this->pdo->rollBack();
                return array('success' => false, 'message' => 'Not enough iron to post job (1 iron per sword).');
            }
            
            // 1. Deduct resources (Iron)
            $this->resourceManager->updateResource($user_id, RESOURCE_IRON, -$iron_cost);
            
            // 2. Deduct Currency (Salary) - uses two-tier payment logic
            $payment_success = $this->resourceManager->processPayment($user_id, $total_salary, "Escrow for posting job to produce $quantity $item.");

            if (!$payment_success) {
                // If currency deduction fails, re-credit the iron before rolling back
                $this->resourceManager->updateResource($user_id, RESOURCE_IRON, $iron_cost);
                $this->pdo->rollBack();
                return array('success' => false, 'message' => 'Not enough currency to cover the total salary.');
            }

            // 3. Insert job offer (salary funds and iron are effectively in escrow by being deducted)
            $stmt = $this->pdo->prepare("INSERT INTO job_offers (employer_id, item_to_produce, quantity, salary) VALUES (?, ?, ?, ?)");
            $stmt->execute(array($user_id, $item, $quantity, $total_salary));

            $this->logger->logAction($user_id, 'post_job', "Posted a job to produce $quantity $item for a total salary of " . number_format($total_salary, 8) . " currency.");
            $this->pdo->commit();
            return array('success' => true, 'message' => 'Job offer posted successfully and funds secured.');
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Error posting job: ' . $e->getMessage());
        }
    }

    /**
     * Accepts an open job and assigns the first available worker to the task.
     * @param int $user_id The worker's ID.
     * @param int $job_id
     * @return array API response.
     */
    public function acceptJob($user_id, $job_id) {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT workers FROM users WHERE id = ?");
            $stmt->execute(array($user_id));
            $total_workers = (int)$stmt->fetchColumn();

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ?");
            $stmt->execute(array($user_id));
            $busy_workers = (int)$stmt->fetchColumn();

            if ($busy_workers >= $total_workers) {
                $this->pdo->rollBack();
                return array('success' => false, 'message' => 'All your workers are currently busy.');
            }

            // Find the first available worker ID
            $worker_id = 0;
            for ($i = 1; $i <= $total_workers; $i++) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM worker_tasks WHERE user_id = ? AND worker_id = ?");
                $stmt->execute(array($user_id, $i));
                if ((int)$stmt->fetchColumn() == 0) {
                    $worker_id = $i;
                    break;
                }
            }
            if ($worker_id === 0) { // Should not happen if $busy_workers < $total_workers, but as a safeguard
                 $this->pdo->rollBack();
                 return array('success' => false, 'message' => 'Internal error: Could not find an idle worker.');
            }

            // Lock and check job status
            $stmt = $this->pdo->prepare("SELECT * FROM job_offers WHERE id = ? AND status = 'open' FOR UPDATE");
            $stmt->execute(array($job_id));
            $job = $stmt->fetch();

            if (!$job) {
                $this->pdo->rollBack();
                return array('success' => false, 'message' => 'This job is no longer available or already taken.');
            }

            // Update job status to 'taken'
            $this->pdo->prepare("UPDATE job_offers SET status = 'taken' WHERE id = ?")->execute(array($job_id));

            // Assign worker to job task
            $completion_time = date('Y-m-d H:i:s', time() + TASK_DURATION_SECONDS);
            $stmt = $this->pdo->prepare("INSERT INTO worker_tasks (user_id, worker_id, task_type, completion_time, job_offer_id) VALUES (?, ?, 'Work on Job', ?, ?)");
            $stmt->execute(array($user_id, $worker_id, $completion_time, $job_id));

            $this->logger->logAction($user_id, 'accept_job', "Accepted job #{$job_id} to produce {$job['quantity']} {$job['item_to_produce']}(s).");
            $this->pdo->commit();
            return array('success' => true, 'message' => "Job accepted! Worker #{$worker_id} has started the task.");

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Error accepting job: ' . $e->getMessage());
        }
    }
}
?>
