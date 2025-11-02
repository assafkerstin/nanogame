<?php
// Project: Nano Empire Database Connection Class
// Manages the single PDO instance. Uses legacy PHP syntax.

class Database {

    private static $pdo = null;

    /**
     * Establishes a PDO connection or returns the existing one.
     * Uses static property to ensure a single connection instance across all files (Singleton-like pattern).
     * @return PDO
     */
    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                $db_host = DB_HOST;
                $db_name = DB_NAME;
                $db_user = DB_USER;
                $db_pass = DB_PASS;
                
                $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                
                // Set the default time zone for MySQL queries to ensure NOW() is consistent
                $pdo->exec("SET time_zone = '+03:00'");
                
                self::$pdo = $pdo;
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(array('success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()));
                exit;
            }
        }
        return self::$pdo;
    }
}
?>
