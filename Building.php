<?php
// Project: Nano Empire Building Class
// Handles building upgrades and associated effects. Uses legacy PHP syntax.

class Building {
    
    private $pdo;
    private $logger;
    private $resourceManager;
    private $user;

    /**
     * Building constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $resource_manager_instance, $user_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->resourceManager = $resource_manager_instance;
        $this->user = $user_instance;
    }

    /**
     * Handles the logic for upgrading a specified building.
     * @param int $user_id
     * @param string $building_type
     * @return array API response.
     */
    public function upgradeBuilding($user_id, $building_type) {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare("SELECT level FROM buildings WHERE user_id = ? AND building_type = ? FOR UPDATE");
            $stmt->execute(array($user_id, $building_type));
            $building = $stmt->fetch();
            
            if (!$building) {
                $this->pdo->rollBack();
                throw new Exception('Building not found.');
            }
            $current_level = (int)$building['level'];
            $next_level = $current_level + 1;

            // Cost calculation: "two to the power of the building's current level"
            $cost = pow(2, $current_level);

            $stmt = $this->pdo->prepare("SELECT wood, iron, stone FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute(array($user_id));
            $resources = $stmt->fetch();

            if ((float)$resources[RESOURCE_WOOD] < $cost || (float)$resources[RESOURCE_IRON] < $cost || (float)$resources[RESOURCE_STONE] < $cost) {
                $this->pdo->rollBack();
                throw new Exception('Not enough resources. Required: ' . $cost . ' of each.');
            }

            // Deduct resources
            $this->resourceManager->updateResource($user_id, RESOURCE_WOOD, -$cost);
            $this->resourceManager->updateResource($user_id, RESOURCE_IRON, -$cost);
            $this->resourceManager->updateResource($user_id, RESOURCE_STONE, -$cost);

            // Update building level
            $stmt = $this->pdo->prepare("UPDATE buildings SET level = ? WHERE user_id = ? AND building_type = ?");
            $stmt->execute(array($next_level, $user_id, $building_type));
            
            // Apply special effects
            if ($building_type == 'Dormitory' && ($next_level % 5 == 0)) {
                // Dormitory effect: Increases worker capacity by 1 every 5 levels
                $this->pdo->prepare("UPDATE users SET workers = workers + 1 WHERE id = ?")->execute(array($user_id));
                $this->logger->logAction($user_id, 'new_worker', "Gained a new worker from Dormitory reaching level $next_level.");
            }

            $this->logger->logAction($user_id, 'upgrade_building', "Upgraded $building_type to level $next_level.");
            $this->pdo->commit();
            return array('success' => true, 'message' => "$building_type upgraded to level $next_level.");

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Upgrade failed: ' . $e->getMessage());
        }
    }
}
?>
