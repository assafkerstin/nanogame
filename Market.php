<?php
// Project: Nano Empire Market Class
// Handles market operations, including order placement and matching engine logic. Uses legacy PHP syntax.

class Market {
    
    private $pdo;
    private $logger;
    private $resourceManager;
    private $user;

    /**
     * Market constructor.
     */
    public function __construct($pdo_connection, $logger_instance, $resource_manager_instance, $user_instance) {
        $this->pdo = $pdo_connection;
        $this->logger = $logger_instance;
        $this->resourceManager = $resource_manager_instance;
        $this->user = $user_instance;
    }

    /**
     * Retrieves the current buy and sell orders for a specific item, grouped by price.
     * @param string $item_type
     * @return array API response.
     */
    public function getMarketData($item_type) {
        $buy_stmt = $this->pdo->prepare("SELECT price, SUM(quantity) as quantity FROM market_orders WHERE order_type = 'buy' AND item_type = ? GROUP BY price ORDER BY price DESC");
        $buy_stmt->execute(array($item_type));
        $buys = $buy_stmt->fetchAll();

        $sell_stmt = $this->pdo->prepare("SELECT price, SUM(quantity) as quantity FROM market_orders WHERE order_type = 'sell' AND item_type = ? GROUP BY price ORDER BY price ASC");
        $sell_stmt->execute(array($item_type));
        $sells = $sell_stmt->fetchAll();

        return array('success' => true, 'data' => array('buys' => $buys, 'sells' => $sells));
    }

    /**
     * Places a new buy or sell order on the market.
     * @param int $user_id
     * @param string $order_type 'buy' or 'sell'
     * @param string $item_type
     * @param int $quantity
     * @param float $price
     * @return array API response.
     */
    public function placeMarketOrder($user_id, $order_type, $item_type, $quantity, $price) {
        $quantity = floor((float)$quantity); // Quantity must be an integer
        $price = (float)$price;

        if ($quantity <= 0 || $price <= 0) {
            return array('success' => false, 'message' => 'Invalid quantity or price.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($order_type == 'sell') {
                // Seller locks the resource being sold
                $stmt = $this->pdo->prepare("SELECT `$item_type` FROM users WHERE id = ? FOR UPDATE");
                $stmt->execute(array($user_id));
                $balance = $stmt->fetchColumn();
                
                if ((float)$balance < $quantity) {
                    $this->pdo->rollBack();
                    return array('success' => false, 'message' => "Not enough $item_type to place sell order.");
                }
                
                // Deduct resource balance (moves to escrow/locked state implicitly)
                $this->resourceManager->updateResource($user_id, $item_type, -$quantity);
                
            } else { // 'buy' order
                // Buyer locks the total currency needed for the order
                $total_cost = $quantity * $price;
                
                // Check if user has enough currency (unearned + earned)
                $stmt = $this->pdo->prepare("SELECT (nano_unearned_balance + nano_earned_balance) as total_currency FROM users WHERE id = ?");
                $stmt->execute(array($user_id));
                if((float)$stmt->fetchColumn() < $total_cost) {
                    $this->pdo->rollBack();
                    return array('success' => false, 'message' => "Not enough currency to place buy order.");
                }
                
                // Deduct currency for escrow (uses two-tier system)
                $payment_success = $this->resourceManager->processPayment($user_id, $total_cost, "Escrow for $quantity $item_type buy order.");

                if (!$payment_success) {
                     // Should be redundant due to the check above but kept as final safeguard
                    $this->pdo->rollBack();
                    return array('success' => false, 'message' => "Payment processing failed during escrow for buy order.");
                }
            }
            
            // Insert the order
            $stmt = $this->pdo->prepare("INSERT INTO market_orders (user_id, order_type, item_type, quantity, price) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute(array($user_id, $order_type, $item_type, $quantity, $price));
            
            $this->logger->logAction($user_id, 'place_order', "Placed a $order_type order for $quantity $item_type at " . number_format($price, 8) . " currency each.");
            $this->pdo->commit();
            return array('success' => true, 'message' => 'Order placed successfully.');

        } catch (Exception $e) {
            $this->pdo->rollBack();
            return array('success' => false, 'message' => 'Error placing order: ' . $e->getMessage());
        }
    }

    /**
     * The core market matching engine. Runs iteratively until no more trades can be made.
     * Note: This function manages its own internal transactions.
     * @param string $item_type
     */
    public function matchMarketOrders($item_type) {
        while (true) {
            $this->pdo->beginTransaction();
            
            // 1. Find the best Buy Order (Highest Bid, Oldest First)
            $buy_order_stmt = $this->pdo->prepare("SELECT * FROM market_orders WHERE order_type = 'buy' AND item_type = ? ORDER BY price DESC, created_at ASC LIMIT 1 FOR UPDATE");
            $buy_order_stmt->execute(array($item_type));
            $buy_order = $buy_order_stmt->fetch();

            // 2. Find the best Sell Order (Lowest Ask, Oldest First)
            $sell_order_stmt = $this->pdo->prepare("SELECT * FROM market_orders WHERE order_type = 'sell' AND item_type = ? ORDER BY price ASC, created_at ASC LIMIT 1 FOR UPDATE");
            $sell_order_stmt->execute(array($item_type));
            $sell_order = $sell_order_stmt->fetch();

            // Check if match is possible (A buy order exists, a sell order exists, and Bid >= Ask)
            if (!$buy_order || !$sell_order || (float)$buy_order['price'] < (float)$sell_order['price']) {
                $this->pdo->commit();
                break; // No more trades possible
            }

            // Match details
            $trade_quantity = min($buy_order['quantity'], $sell_order['quantity']);
            // Trade price is the price of the oldest, lowest sell order (the Ask price)
            $trade_price = (float)$sell_order['price'];
            $total_cost = (float)$
