<?php
// Project: Nano Empire Military Class
// Handles army power calculations and soldier recruitment logic. Uses legacy PHP syntax.

class Military {
    
    private $pdo;
    private $logger;
    private $resourceManager;

    /**
     * Military constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $resource_manager_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->resourceManager = $resource_manager_instance;
    }

    /**
     * Calculates the player's total military power.
     * Formula: min(soldiers, swords) * (1 + barracks_level / 100)
     * @param int $user_id
     * @return float
     */
    public function getArmyPower($user_id) {
        $stmt = $this->pdo->prepare("
            SELECT u.soldiers, u.sword, b.level as barracks_level 
            FROM users u 
            LEFT JOIN buildings b ON u.id = b.user_id AND b.building_type = 'Barracks' 
            WHERE u.id = ?
        ");
        $stmt->execute(array($user_id));
        $data = $stmt->fetch();
        if (!$data) return 0.0;
        
        $soldiers = (float)$data['soldiers'];
        $swords = (float)$data['sword'];
        $barracks_level = (int)$data['barracks_level'] ?? 0;
        
        // Base Power is the lesser value between the number of soldiers and the number of swords
        $base_power = min($soldiers, $swords);

        // Barracks Bonus is one plus the barracks level divided by one hundred
        $barracks_bonus = 1.0 + ((float)$barracks_level / 100.0);
        
        // Total Military Power is the base power multiplied by a barracks bonus
        $total_power = (float)$base_power * (float)$barracks_bonus;
        
        return $total_power;
    }

    /**
     * Calculates the dynamic resource cost to recruit the next soldier.
     * Formula: 1.001 to the power of the current number of soldiers for each resource.
     * @param int $current_soldiers
     * @return array Associative array of costs
     */
    public function getRecruitCost($current_soldiers) {
        $cost = pow(1.001, (int)$current_soldiers);
        return array(
            RESOURCE_WOOD => $cost,
            RESOURCE_IRON => $cost,
            RESOURCE_STONE => $cost,
        );
    }

    /**
     * Handles the recruitment of one soldier.
     * @param int $user_id
     * @return array API response.
     */
    public function recruitSoldiers($user_id) {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT soldiers FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute(array($user_id));
            $user_data = $stmt->fetch();
            $current_soldiers = (int)$user_data['soldiers'];

            $costs = $this->getRecruitCost($current_soldiers);

            // Check resource availability
            $user_resources_stmt = $this->pdo->prepare("SELECT wood, iron, stone FROM users WHERE id = ?");
            $user_resources_stmt->execute(array($user_id));
            $resources = $user_resources_stmt->fetch();

            if ((float)$resources[RESOURCE_WOOD] < $costs[RESOURCE_WOOD] || 
                (float)$resources[RESOURCE_IRON] < $costs[RESOURCE_IRON] || 
                (float)$resources[RESOURCE_STONE] < $costs[RESOURCE_STONE]) 
            {
                $this->pdo->rollBack();
                return array('success' => false, 'message' => 'Not enough resources to recruit a soldier.');
            }

            // Deduct resources
            $this->resourceManager->updateResource($user_id, RESOURCE_WOOD, -$costs[RESOURCE_WOOD]);
            $this->resourceManager->updateResource($user_id, RESOURCE_IRON, -$costs[RESOURCE_IRON]);
            $this->resourceManager->updateResource($user_id, RESOURCE_STONE, -$costs[RESOURCE_STONE]);
            
            // Add soldier
            $this->pdo->prepare("UPDATE users SET soldiers = soldiers + 1 WHERE id = ?")->execute(array($user_id));

            $this->logger->logAction($user_id, 'recruit', "Recruited 1 soldier for resources.");
            $this->pdo->commit();
            return array('success' => true, 'message' => "Successfully recruited 1 soldier.");
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Error recruiting soldiers: ' . $e->getMessage());
        }
    }
}
?>
