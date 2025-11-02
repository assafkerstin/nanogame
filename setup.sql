-- Drop tables in the correct order to respect foreign key constraints
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `market_orders`;
DROP TABLE IF EXISTS `job_offers`;
DROP TABLE IF EXISTS `occupation_history`;
DROP TABLE IF EXISTS `worker_tasks`;
DROP TABLE IF EXISTS `action_logs`;
DROP TABLE IF EXISTS `buildings`;
DROP TABLE IF EXISTS `users`;

-- Create the users table
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `nano_wallet_address` VARCHAR(65),
  `nano_unearned_balance` DOUBLE DEFAULT 0,
  `nano_earned_balance` DOUBLE DEFAULT 0,
  `wood` FLOAT DEFAULT 10,
  `iron` FLOAT DEFAULT 10,
  `stone` FLOAT DEFAULT 10,
  `sword` FLOAT DEFAULT 0,
  `soldiers` INT DEFAULT 0,
  `workers` INT DEFAULT 1,
  `occupied_by` INT DEFAULT NULL,
  `referrer_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`occupied_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`referrer_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Create the buildings table
CREATE TABLE `buildings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `building_type` VARCHAR(50) NOT NULL,
  `level` INT DEFAULT 0,
  UNIQUE(`user_id`, `building_type`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Create the action_logs table
CREATE TABLE `action_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `action_type` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Create the worker_tasks table
CREATE TABLE `worker_tasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `worker_id` INT NOT NULL,
  `task_type` VARCHAR(100) NOT NULL,
  `completion_time` TIMESTAMP NOT NULL,
  `job_offer_id` INT DEFAULT NULL,
  UNIQUE(`user_id`, `worker_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  -- job_offer_id does NOT need a foreign key here because the job offer might be cancelled and deleted,
  -- but it should be indexed for faster lookup.
  INDEX (`job_offer_id`)
) ENGINE=InnoDB;

-- Create the occupation_history table
CREATE TABLE `occupation_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `occupier_id` INT NOT NULL,
  `occupied_id` INT NOT NULL,
  `action` ENUM('conquer', 'liberate') NOT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`occupier_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`occupied_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Create the job_offers table
CREATE TABLE `job_offers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `employer_id` INT NOT NULL,
  `item_to_produce` VARCHAR(50) NOT NULL,
  `quantity` INT NOT NULL,
  `salary` DOUBLE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('open', 'taken', 'completed') DEFAULT 'open',
  FOREIGN KEY (`employer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Create the market_orders table
CREATE TABLE `market_orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_type` ENUM('buy', 'sell') NOT NULL,
  `item_type` VARCHAR(50) NOT NULL,
  `quantity` INT NOT NULL,
  `price` DOUBLE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Create the transactions table
CREATE TABLE `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT,
  `type` VARCHAR(50),
  `description` TEXT,
  `amount` DOUBLE,
  `currency` VARCHAR(20) DEFAULT 'Nano',
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Insert Government User (user_id = 1)
INSERT INTO `users` (`id`, `username`, `password`, `email`) VALUES (1, 'government', 'immutable', 'gov@nano.game');
