<?php
// Project: Nano Empire Logger Class
// Handles action logs and transaction logs. Uses legacy PHP syntax.

class Logger {
    
    private $pdo;

    /**
     * Logger constructor.
     */
    public function __construct($pdo_connection) {
        $this->pdo = $pdo_connection;
    }

    /**
     * Inserts an entry into the action_logs table.
     * @param int $user_id
     * @param string $action_type
     * @param string $description
     */
    public function logAction($user_id, $action_type, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO action_logs (user_id, action_type, description) VALUES (?, ?, ?)");
        $stmt->execute(array($user_id, $action_type, $description));
    }

    /**
     * Inserts an entry into the transactions table.
     * @param int $user_id
     * @param string $type
     * @param float $amount
     * @param string $description
     */
    public function logTransaction($user_id, $type, $amount, $description) {
        $stmt = $this->pdo->prepare("INSERT INTO transactions (user_id, type, amount, description) VALUES (?, ?, ?, ?)");
        // Only log if amount is non-zero
        if ((float)$amount != 0) {
            $stmt->execute(array($user_id, $type, $amount, $description));
        }
    }
}
?>
