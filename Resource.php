<?php
// Project: Nano Empire Resource Class
// Manages resource updates, payment processing, taxation, and distribution. Uses legacy PHP syntax.

class Resource {
    
    private $pdo;
    private $logger;

    /**
     * Resource constructor.
     */
    public function __construct($pdo_connection, $logger_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
    }

    /**
     * Generic method to update a user's resource balance.
     * @param int $user_id
     * @param string $resource_type Column name (e.g., 'wood', 'nano_earned_balance')
     * @param float $amount Can be positive (credit) or negative (debit)
     */
    public function updateResource($user_id, $resource_type, $amount) {
        $stmt = $this->pdo->prepare("UPDATE users SET `$resource_type` = `$resource_type` + ? WHERE id = ?");
        $stmt->execute(array($amount, $user_id));
    }

    /**
     * Processes a payment, deducting from unearned currency first, then earned currency.
     * NOTE: Must be called within an existing transaction.
     * @param int $user_id
     * @param float $total_cost
     * @param string $description For logging
     * @return bool True on success, false if funds are insufficient.
     */
    public function processPayment($user_id, $total_cost, $description) {
        $stmt = $this->pdo->prepare("SELECT nano_unearned_balance, nano_earned_balance FROM users WHERE id = ? FOR UPDATE");
        $stmt->execute(array($user_id));
        $user_balances = $stmt->fetch();

        $total_available = (float)$user_balances[CURRENCY_UNEARNED] + (float)$user_balances[CURRENCY_EARNED];

        if ($total_available < $total_cost) {
            return false;
        }

        $unearned_payment = min($total_cost, (float)$user_balances[CURRENCY_UNEARNED]);
        $earned_payment = $total_cost - $unearned_payment;
        
        $this->pdo->prepare("UPDATE users SET 
            `" . CURRENCY_UNEARNED . "` = `" . CURRENCY_UNEARNED . "` - ?, 
            `" . CURRENCY_EARNED . "` = `" . CURRENCY_EARNED . "` - ? 
            WHERE id = ?")
            ->execute(array($unearned_payment, $earned_payment, $user_id));

        $this->logger->logTransaction($user_id, 'payment', -$total_cost, $description);
        return true;
    }

    /**
     * Applies taxes and distributions to a gross income amount (resource or currency).
     * NOTE: Must be called within an existing transaction.
     * @param int $user_id The player earning the income.
     * @param float $gross_income
     * @param string $resource_type The resource type (e.g., 'wood', 'nano_earned_balance')
     * @param string $source_description
     * @return float The net income remaining for the user.
     */
    public function applyTaxes($user_id, $gross_income, $resource_type, $source_description) {
        $user_stmt = $this->pdo->prepare("SELECT username, occupied_by, referrer_id FROM users WHERE id = ?");
        $user_stmt->execute(array($user_id));
        $user = $user_stmt->fetch();
        
        $net_income = $gross_income;
        
        // --- 1. Government Tax (25%) ---
        $gov_tax = $gross_income * GOVERNMENT_TAX_RATE;
        $net_income = (float)$net_income - (float)$gov_tax;
        
        // Transfer to Government temporarily
        $this->updateResource(GOVERNMENT_USER_ID, $resource_type, $gov_tax);
        $this->logger->logAction($user_id, 'tax_payment', "Paid $gov_tax $resource_type in government tax from $source_description.");
        if ($resource_type === CURRENCY_EARNED) {
            $this->logger->logTransaction($user_id, 'tax', -$gov_tax, "Government tax on $source_description.");
            $this->logger->logTransaction(GOVERNMENT_USER_ID, 'tax_income', $gov_tax, "Tax revenue from user #{$user_id} ({$user['username']}).");
        }

        $referral_bonus = 0.0;
        // --- 2. Referral Bonus (5% of gross, paid FROM government's tax share) ---
        if ($user['referrer_id'] > 0) {
            $referral_bonus = $gross_income * REFERRAL_BONUS_RATE;
            
            // Deduct from Government
            $this->updateResource(GOVERNMENT_USER_ID, $resource_type, -$referral_bonus);
            // Credit to Referrer
            $this->updateResource($user['referrer_id'], $resource_type, $referral_bonus);
            
            $this->logger->logAction($user['referrer_id'], 'referral_bonus', "Received $referral_bonus $resource_type as referral bonus from user {$user['username']}.");
            if ($resource_type === CURRENCY_EARNED) {
                $this->logger->logTransaction(GOVERNMENT_USER_ID, 'referral_payout', -$referral_bonus, "Referral bonus paid for user #{$user_id}.");
                $this->logger->logTransaction($user['referrer_id'], 'referral_income', $referral_bonus, "Referral bonus from user #{$user_id}.");
            }
        }

        // --- 3. Occupation Tax (10% of gross, deducted from net income) ---
        if ($user['occupied_by'] !== null) {
            $occupation_tax = $gross_income * OCCUPATION_TAX_RATE;
            $net_income = (float)$net_income - (float)$occupation_tax;
            
            // Credit to Occupier
            $this->updateResource($user['occupied_by'], $resource_type, $occupation_tax);
            
            $this->logger->logAction($user['occupied_by'], 'occupation_tax', "Received $occupation_tax $resource_type as occupation tax from user {$user['username']}.");
            $this->logger->logAction($user_id, 'tax_payment', "Paid $occupation_tax $resource_type in occupation tax.");
            if ($resource_type === CURRENCY_EARNED) {
                $this->logger->logTransaction($user_id, 'tax', -$occupation_tax, "Occupation tax paid.");
                $this->logger->logTransaction($user['occupied_by'], 'tax_income', $occupation_tax, "Occupation tax from user #{$user_id}.");
            }
        }

        // --- 4. Government Distribution of Net Tax Revenue ---
        if ($gov_tax > 0) {
            $net_gov_income = $gov_tax - $referral_bonus;

            if ($net_gov_income > 0) {
                $time_window = ACTIVE_PLAYER_WINDOW;
                // Find all eligible users (active in the last 7 days)
                $stmt = $this->pdo->prepare("SELECT DISTINCT user_id FROM action_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $time_window) AND action_type IN ('" . RESOURCE_WOOD_HARVEST . "', '" . RESOURCE_IRON_HARVEST . "', '" . RESOURCE_STONE_HARVEST . "', 'job_completed') AND user_id NOT IN (?, ?)");
                $stmt->execute(array(GOVERNMENT_USER_ID, $user_id));
                $eligible_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $num_eligible_users = count($eligible_users);

                if ($num_eligible_users > 0) {
                    $distribution_share = $net_gov_income / $num_eligible_users;

                    // Remove the distributed amount from the government's balance
                    $this->updateResource(GOVERNMENT_USER_ID, $resource_type, -$net_gov_income);
                    if ($resource_type === CURRENCY_EARNED) {
                        $this->logger->logTransaction(GOVERNMENT_USER_ID, 'distribution_payout', -$net_gov_income, "Distributed tax revenue to {$num_eligible_users} players.");
                    }

                    // Distribute the share to each eligible player
                    foreach ($eligible_users as $recipient_id) {
                        $this->updateResource($recipient_id, $resource_type, $distribution_share);
                        
                        $asset_name = str_replace(array(CURRENCY_EARNED, CURRENCY_UNEARNED, '_'), array('', '', ' '), $resource_type);
                        $dist_desc = "Received a tax distribution of " . number_format($distribution_share, 8) . " {$asset_name}.";
                        $this->logger->logAction($recipient_id, 'tax_distribution', $dist_desc);
                        if ($resource_type === CURRENCY_EARNED) {
                            $this->logger->logTransaction($recipient_id, 'distribution_income', $distribution_share, "Received tax distribution from government.");
                        }
                    }
                }
            }
        }

        return $net_income;
    }
}
?>
