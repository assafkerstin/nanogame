<?php
// Project: Nano Empire Leaderboard Class
// Handles all complex reporting and data views: Leaderboard, Tax History, Distribution, and Harvesting Activity.
// Uses legacy PHP syntax.

class Leaderboard {
    
    private $pdo;

    /**
     * Leaderboard constructor.
     */
    public function __construct($pdo_connection) {
        $this->pdo = $pdo_connection;
    }

    /**
     * Fetches the last 100 entries from the user's action log.
     * @param int $user_id
     * @return array API response.
     */
    public function getHistory($user_id) {
        $stmt = $this->pdo->prepare("SELECT description, timestamp FROM action_logs WHERE user_id = ? ORDER BY timestamp DESC LIMIT 100");
        $stmt->execute(array($user_id));
        $logs = $stmt->fetchAll();
        return array('success' => true, 'data' => $logs);
    }

    /**
     * Retrieves a global history of tax payment events.
     * @return array API response.
     */
    public function getTaxHistory() {
        $query = "SELECT u.username, a.description, a.timestamp 
                  FROM action_logs a
                  JOIN users u ON a.user_id = u.id
                  WHERE a.action_type = 'tax_payment'
                  ORDER BY a.timestamp DESC 
                  LIMIT 200";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        $data = $stmt->fetchAll();
        
        return array('success' => true, 'data' => $data);
    }

    /**
     * Fetches the current balances for the government account.
     * @return array API response.
     */
    public function getGovernmentData() {
        $stmt = $this->pdo->prepare("SELECT nano_earned_balance, nano_unearned_balance, wood, iron, stone FROM users WHERE id = ?");
        $stmt->execute(array(GOVERNMENT_USER_ID));
        $data = $stmt->fetch();
        return array('success' => true, 'data' => $data);
    }

    /**
     * Calculates the total tax revenue collected by the government in the last 24 hours.
     * @return array API response.
     */
    public function getDistributionData() {
        $stmt = $this->pdo->prepare("SELECT SUM(amount) as total_tax FROM transactions WHERE user_id = ? AND type = 'tax_income' AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute(array(GOVERNMENT_USER_ID));
        $total = $stmt->fetchColumn();
        return array('success' => true, 'data' => array('total_tax_24h' => $total ?? 0.0));
    }

    /**
     * Retrieves a history of all tax distribution events paid to users.
     * @return array API response.
     */
    public function getDistributionHistory() {
        $query = "SELECT u.username, a.description, a.timestamp 
                  FROM action_logs a
                  JOIN users u ON a.user_id = u.id
                  WHERE a.action_type = 'tax_distribution'
                  ORDER BY a.timestamp DESC 
                  LIMIT 200";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $data = array();
        foreach ($logs as $log) {
            // Extract amount and asset type using regex from description: "Received a tax distribution of X resource."
            if (preg_match("/of ([\d\.]+) ([\w\s]+)\./", $log['description'], $matches)) {
                $data[] = array(
                    'username' => $log['username'],
                    'amount' => $matches[1],
                    'asset_type' => ucwords($matches[2]),
                    'description' => $log['description'],
                    'timestamp' => $log['timestamp']
                );
            }
        }
        return array('success' => true, 'data' => $data);
    }

    /**
     * Ranks users by the total number of harvesting actions in a specified timeframe.
     * @param string $timeframe '24_hours' or 'all_time'
     * @return array API response.
     */
    public function getHarvestingActivity($timeframe) {
        $where_clause = "";
        if ($timeframe === '24_hours') {
            $where_clause = "AND al.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        }
        // Exclude job_completed, only count harvesting actions
        $harvest_actions = "'" . RESOURCE_WOOD_HARVEST . "','" . RESOURCE_IRON_HARVEST . "','" . RESOURCE_STONE_HARVEST . "'";

        $query = "SELECT u.username, COUNT(al.id) as action_count
                  FROM users u
                  LEFT JOIN action_logs al ON u.id = al.user_id AND al.action_type IN ($harvest_actions) $where_clause
                  WHERE u.id != ?
                  GROUP BY u.id, u.username
                  ORDER BY action_count DESC
                  LIMIT 100";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(array(GOVERNMENT_USER_ID));
        $players = $stmt->fetchAll();

        $ranked_players = array();
        foreach ($players as $index => $player) {
            $player['rank'] = $index + 1;
            $player['action_count'] = (int)$player['action_count'];
            $ranked_players[] = $player;
        }
        
        return array('success' => true, 'data' => $ranked_players);
    }

    /**
     * Retrieves top players ranked by the specified criterion.
     * @param string $sort_by
     * @return array API response.
     */
    public function getLeaderboard($sort_by) {
        $select_clause = "u.username, ";
        $from_clause = "FROM users u";
        $where_clause = "WHERE u.id != " . GOVERNMENT_USER_ID;
        $order_by_clause = "";
        $group_by_clause = "";

        switch ($sort_by) {
            case 'military_power':
                // Formula: FLOOR(LEAST(soldiers, sword) * (1 + (barracks_level / 100)))
                $select_clause .= "(FLOOR(LEAST(u.soldiers, u.sword) * (1 + (b.level / 100)))) as score";
                $from_clause .= " LEFT JOIN buildings b ON u.id = b.user_id AND b.building_type = 'Barracks'";
                $order_by_clause = "ORDER BY score DESC, u.id ASC";
                break;
            case 'sword':
            case 'workers':
                $select_clause .= "u." . $sort_by . " as score";
                $order_by_clause = "ORDER BY score DESC, u.id ASC";
                break;
            case 'prod_today':
            case 'prod_7_days':
            case 'prod_30_days':
            case 'prod_all_time':
                $select_clause .= "COUNT(al.id) as score";
                // Include all productive actions: harvesting and completing jobs
                $prod_actions_str = "'" . RESOURCE_WOOD_HARVEST . "','" . RESOURCE_IRON_HARVEST . "','" . RESOURCE_STONE_HARVEST . "','job_completed'";
                $from_clause .= " LEFT JOIN action_logs al ON u.id = al.user_id AND al.action_type IN (" . $prod_actions_str . ")";
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
                // Note: The total harvested value is parsed from the description field.
                $select_clause .= "SUM(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(al.description, ' ', -2), ' ', 1) AS DECIMAL(10,8))) as score";
                $from_clause .= " JOIN action_logs al ON u.id = al.user_id";
                if($sort_by === 'harvest_wood_total') $where_clause .= " AND al.action_type = '" . RESOURCE_WOOD_HARVEST . "'";
                if($sort_by === 'harvest_iron_total') $where_clause .= " AND al.action_type = '" . RESOURCE_IRON_HARVEST . "'";
                if($sort_by === 'harvest_stone_total') $where_clause .= " AND al.action_type = '" . RESOURCE_STONE_HARVEST . "'";
                if($sort_by === 'harvest_total') $where_clause .= " AND al.action_type IN ('" . RESOURCE_WOOD_HARVEST . "','" . RESOURCE_IRON_HARVEST . "','" . RESOURCE_STONE_HARVEST . "')";
                if($sort_by === 'swords_produced_total') $where_clause .= " AND al.action_type = 'job_completed'";
                $group_by_clause = "GROUP BY u.id, u.username";
                $order_by_clause = "ORDER BY score DESC, u.id ASC";
                break;
            case 'net_worth':
            default:
                // Net Worth Formula (using mock values for resource prices): Nano/Credits + (Wood * 0.1) + (Iron * 0.2) + (Stone * 0.15) + (Sword * 1.0)
                $select_clause .= "(u.nano_unearned_balance + u.nano_earned_balance + (u.wood * 0.1) + (u.iron * 0.2) + (u.stone * 0.15) + (u.sword * 1.0)) as score";
                $order_by_clause = "ORDER BY score DESC, u.id ASC";
                break;
        }

        $query = "SELECT " . $select_clause . " " . $from_clause . " " . $where_clause . " " . $group_by_clause . " " . $order_by_clause . " LIMIT 100";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute();
        $players = $stmt->fetchAll();

        $ranked_players = array();
        foreach ($players as $index => $player) {
            $player['rank'] = $index + 1;
            $player['score'] = $player['score'] ?? 0.0;
            $ranked_players[] = $player;
        }
        
        return array('success' => true, 'data' => $ranked_players);
    }
}
?>
