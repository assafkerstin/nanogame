<?php
// Project: Nano Empire Configuration File
// Stores global constants for database and game mechanics. Uses legacy PHP syntax.

// --- Database Configuration ---
define('DB_HOST', 'clujnapocaguide.eu');
define('DB_NAME', 'clujnapo_nano');
define('DB_USER', 'clujnapo_nano');
define('DB_PASS', 'Nano2000!');

// --- User IDs ---
define('GOVERNMENT_USER_ID', 1);

// --- Game Constants ---
define('TASK_DURATION_SECONDS', 3);
define('ACTIVE_PLAYER_WINDOW', '7 DAY'); // Used for tax distribution eligibility

// --- Economic Constants ---
define('GOVERNMENT_TAX_RATE', 0.25);
define('REFERRAL_BONUS_RATE', 0.05);
define('OCCUPATION_TAX_RATE', 0.10);

// --- Resource Names (Column mapping) ---
define('RESOURCE_WOOD', 'wood');
define('RESOURCE_IRON', 'iron');
define('RESOURCE_STONE', 'stone');
define('RESOURCE_SWORD', 'sword');
define('CURRENCY_UNEARNED', 'nano_unearned_balance');
define('CURRENCY_EARNED', 'nano_earned_balance');

// --- Action/Task Types for Logging ---
define('RESOURCE_WOOD_HARVEST', 'Harvest Wood');
define('RESOURCE_IRON_HARVEST', 'Harvest Iron');
define('RESOURCE_STONE_HARVEST', 'Harvest Stone');

// --- Military/Conquest Constants (in days) ---
define('PROTECTION_PERIOD_GENERAL', '14 DAY'); 
define('PROTECTION_PERIOD_SPECIFIC', '30 DAY'); 

?>
