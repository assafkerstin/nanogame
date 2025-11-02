<?php
// Project: Nano Empire Conquest Class
// Implements military actions (conquer/liberate) and complex protection rules. Uses legacy PHP syntax.

class Conquest {
    
    private $pdo;
    private $logger;
    private $militaryManager;
    private $user;

    /**
     * Conquest constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $military_manager_instance, $user_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->militaryManager = $military_manager_instance;
        $this->user = $user_instance;
    }

    /**
     * Retrieves all data needed for the Conquer page: player power breakdown, occupied list, and potential targets.
     * @param int $user_id
     * @return array API response.
     */
    public function getConquerData($user_id) {
        $my_power = $this->militaryManager->getArmyPower($user_id);
        
        // 1. Get players I occupy and calculate used power
        $stmt = $this->pdo->prepare("SELECT id, username FROM users WHERE occupied_by = ?");
        $stmt->execute(array($user_id));
        $occupied_players_raw = $stmt->fetchAll();

        $used_power = 0.0;
        $occupied_players = array();
        foreach ($occupied_players_raw as $p) {
            $power = $this->militaryManager->getArmyPower($p['id']);
            $used_power += $power;
            $occupied_players[] = array('id' => $p['id'], 'username' => $p['username'], 'army_power' => $power);
        }
        
        $unused_power = $my_power - $used_power;

        // 2. Get all potential targets (excluding myself and government)
        $stmt = $this->pdo->prepare("SELECT id, username, occupied_by FROM users WHERE id NOT IN (?, ?)");
        $stmt->execute(array($user_id, GOVERNMENT_USER_ID));
        $all_other_players = $stmt->fetchAll();

        $potential_targets = array();
        $now = time();

        foreach($all_other_players as $p) {
            $target_power = $this->militaryManager->getArmyPower($p['id']);
            $target_data = array('id' => $p['id'], 'username' => $p['username'], 'army_power' => $target_power);

            $target_data['is_my_target'] = false; // Add flag for frontend clarity
            if ($p['occupied_by'] == $user_id) {
                // Skip if already occupied by me (they are in the 'occupied_players' list above)
                continue;
            } elseif ($p['occupied_by'] !== null) {
                $target_data['status'] = 'Already Occupied';
            } elseif ($unused_power <= $target_power) {
                $target_data['status'] = 'Power Too Low (' . number_format($target_power - $unused_power, 8) . ' needed)';
            } else {
                // Check for protection
                $stmt_prot = $this->pdo->prepare("SELECT * FROM occupation_history WHERE occupied_id = ? AND action = 'liberate' ORDER BY timestamp DESC LIMIT 1");
                $stmt_prot->execute(array($p['id']));
                $last_liberation = $stmt_prot->fetch();

                $is_protected = false;
                $protection_end_time = null;

                if ($last_liberation) {
                    $liberation_timestamp = strtotime($last_liberation['timestamp']);
                    
                    // General 14-day protection
                    $protection_end_14 = $liberation_timestamp + (14 * 86400); // 14 days in seconds
                    if ($now < $protection_end_14) {
                        $is_protected = true;
                        $protection_end_time = date('Y-m-d H:i:s', $protection_end_14);
                    }

                    // Specific 30-day protection (only if I was the liberator)
                    if ((int)$last_liberation['occupier_id'] == $user_id) {
                        $protection_end_30 = $liberation_timestamp + (30 * 86400); // 30 days in seconds
                        if ($now < $protection_end_30) {
                            $is_protected = true;
                            $protection_end_time = date('Y-m-d H:i:s', $protection_end_30);
                        }
                    }
                }

                if ($is_protected) {
                    $target_data['status'] = 'protected';
                    $target_data['protection_end_time'] = $protection_end_time;
                } else {
                    $target_data['status'] = 'conquerable';
                }
            }
            $potential_targets[] = $target_data;
        }

        return array(
            'success' => true,
            'data' => array(
                'total_power' => $my_power,
                'used_power' => $used_power,
                'unused_power' => $unused_power,
                'occupied_players' => $occupied_players,
                'potential_targets' => $potential_targets
            )
        );
    }

    /**
     * Attempts to conquer the specified player.
     * @param int $user_id The conquering player's ID.
     * @param int $target_id The player to be conquered.
     * @return array API response.
     */
    public function conquerPlayer($user_id, $target_id) {
        if ($user_id == $target_id) {
            return array('success' => false, 'message' => 'You cannot conquer yourself.');
        }

        $this->pdo->beginTransaction();
        try {
            // Get necessary data and lock rows
            $my_power = $this->militaryManager->getArmyPower($user_id);
            $target = $this->user->getUserRow($target_id, true); // Lock target row
            $target_power = $this->militaryManager->getArmyPower($target_id);

            // Check Used Power
            $used_power = 0.0;
            $stmt_used = $this->pdo->prepare("SELECT id FROM users WHERE occupied_by = ?");
            $stmt_used->execute(array($user_id));
            $occupied_players_raw = $stmt_used->fetchAll();
            foreach ($occupied_players_raw as $p) {
                $used_power += $this->militaryManager->getArmyPower($p['id']);
            }
            $unused_power = $my_power - $used_power;

            // Validation Checks
            if (!$target || $target['id'] == GOVERNMENT_USER_ID) {
                throw new Exception("Invalid target.");
            }
            if ($target['occupied_by'] !== null) {
                throw new Exception("This player is already occupied.");
            }
            if ($unused_power <= $target_power) {
                throw new Exception("Your unused army power (" . number_format($unused_power, 8) . ") is too low to conquer this player (Target Power: " . number_format($target_power, 8) . ").");
            }

            // Check protection rules (server-side check)
            $stmt_prot = $this->pdo->prepare("SELECT * FROM occupation_history WHERE occupied_id = ? AND action = 'liberate' ORDER BY timestamp DESC LIMIT 1");
            $stmt_prot->execute(array($target_id));
            $last_liberation = $stmt_prot->fetch();
            $now = time();

            if ($last_liberation) {
                $liberation_timestamp = strtotime($last_liberation['timestamp']);
                $protection_end_14 = $liberation_timestamp + (14 * 86400); // 14 days
                $protection_end_30 = $liberation_timestamp + (30 * 86400); // 30 days
                
                if ($now < $protection_end_14) {
                    throw new Exception("This player is under a general 14-day protection period.");
                }
                if ((int)$last_liberation['occupier_id'] == $user_id && $now < $protection_end_30) {
                    throw new Exception("You cannot re-conquer this player so soon after liberating them (30-day period).");
                }
            }
            
            // Execute Conquest
            $stmt_update = $this->pdo->prepare("UPDATE users SET occupied_by = ? WHERE id = ?");
            $stmt_update->execute(array($user_id, $target_id));

            $stmt_log = $this->pdo->prepare("INSERT INTO occupation_history (occupier_id, occupied_id, action) VALUES (?, ?, 'conquer')");
            $stmt_log->execute(array($user_id, $target_id));

            $this->logger->logAction($user_id, 'conquer', "You have conquered player {$target['username']}.");
            $this->logger->logAction($target_id, 'conquered', "You have been conquered by player #{$user_id}.");
            
            $this->pdo->commit();
            return array('success' => true, 'message' => "Victory! You have successfully conquered {$target['username']}!");

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Liberates a player currently occupied by the user.
     * @param int $user_id The liberating player's ID.
     * @param int $target_id The player to be liberated.
     * @return array API response.
     */
    public function liberatePlayer($user_id, $target_id) {
        $this->pdo->beginTransaction();
        try {
            $stmt_target = $this->pdo->prepare("SELECT username, occupied_by FROM users WHERE id = ? FOR UPDATE");
            $stmt_target->execute(array($target_id));
            $target = $stmt_target->fetch();

            if (!$target || $target['occupied_by'] != $user_id) {
                throw new Exception("You are not currently occupying this player.");
            }

            // Remove occupation status
            $stmt_update = $this->pdo->prepare("UPDATE users SET occupied_by = NULL WHERE id = ?");
            $stmt_update->execute(array($target_id));

            // Log liberation and start the protection period
            $stmt_log = $this->pdo->prepare("INSERT INTO occupation_history (occupier_id, occupied_id, action) VALUES (?, ?, 'liberate')");
            $stmt_log->execute(array($user_id, $target_id));

            $this->logger->logAction($user_id, 'liberate', "You have liberated player {$target['username']}.");
            $this->logger->logAction($target_id, 'liberated', "You have been liberated by player #{$user_id}.");

            $this->pdo->commit();
            return array('success' => true, 'message' => "You have liberated {$target['username']}. They are now under a 14-day general protection period.");
        } catch(Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
}
?>
