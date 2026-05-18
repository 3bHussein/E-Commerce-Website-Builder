<?php
/**
 * Complete E-Commerce Website Builder - XAMPP/MySQL Fixed Version
 * Fully working with MariaDB/MySQL
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Installation steps
$steps = ['welcome', 'database', 'account', 'install', 'finish'];
$currentStep = $_GET['step'] ?? 'welcome';
$error = '';
$success = '';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($currentStep) {
        case 'welcome':
            header('Location: ?step=database');
            exit;
            
        case 'database':
            // Test database connection
            try {
                $testPdo = new PDO(
                    "mysql:host={$_POST['db_host']};port={$_POST['db_port']};charset=utf8mb4",
                    $_POST['db_user'],
                    $_POST['db_pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
                
                $_SESSION['db_config'] = [
                    'host' => $_POST['db_host'],
                    'port' => $_POST['db_port'],
                    'name' => $_POST['db_name'],
                    'user' => $_POST['db_user'],
                    'pass' => $_POST['db_pass'],
                    'prefix' => $_POST['db_prefix']
                ];
                header('Location: ?step=account');
                exit;
            } catch (PDOException $e) {
                $error = "Database connection failed: " . $e->getMessage();
            }
            break;
            
        case 'account':
            if ($_POST['admin_pass'] !== $_POST['admin_pass_confirm']) {
                $error = "Passwords do not match!";
            } elseif (strlen($_POST['admin_pass']) < 8) {
                $error = "Password must be at least 8 characters!";
            } elseif (!filter_var($_POST['admin_email'], FILTER_VALIDATE_EMAIL)) {
                $error = "Invalid email address!";
            } else {
                $_SESSION['admin'] = [
                    'email' => $_POST['admin_email'],
                    'password' => password_hash($_POST['admin_pass'], PASSWORD_DEFAULT),
                    'first_name' => $_POST['first_name'],
                    'last_name' => $_POST['last_name']
                ];
                $_SESSION['shop'] = [
                    'name' => $_POST['shop_name'],
                    'url' => 'http://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\')
                ];
                header('Location: ?step=install');
                exit;
            }
            break;
    }
}

// Run installation
if ($currentStep === 'install' && !isset($_SESSION['installed'])) {
    $result = performInstallation();
    if ($result['success']) {
        $_SESSION['installed'] = true;
        $_SESSION['install_result'] = $result;
        header('Location: ?step=finish');
        exit;
    } else {
        $error = $result['message'];
    }
}

function performInstallation() {
    $db = $_SESSION['db_config'];
    $admin = $_SESSION['admin'];
    $shop = $_SESSION['shop'];
    
    try {
        // Create database connection WITHOUT specifying database name first
        $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
            $db['user'],
            $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create database if not exists - FIXED SYNTAX
        $dbName = $db['name'];
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");
        
        $prefix = $db['prefix'];
        
        // Drop existing tables if they exist (clean install)
        $tablesToDrop = ['users', 'categories', 'products', 'orders', 'settings', 'cart'];
        foreach ($tablesToDrop as $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$prefix}{$table}`");
            } catch (Exception $e) {
                // Ignore errors if table doesn't exist
            }
        }
        
        // Create tables with CORRECT SQL syntax
        $sql = "
        CREATE TABLE `{$prefix}users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `first_name` VARCHAR(100),
            `last_name` VARCHAR(100),
            `role` ENUM('admin', 'customer') DEFAULT 'customer',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE `{$prefix}categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `description` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE `{$prefix}products` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NOT NULL UNIQUE,
            `description` TEXT,
            `short_description` TEXT,
            `price` DECIMAL(10,2) NOT NULL,
            `compare_price` DECIMAL(10,2),
            `sku` VARCHAR(100) UNIQUE,
            `stock` INT DEFAULT 0,
            `image` VARCHAR(255),
            `featured` BOOLEAN DEFAULT FALSE,
            `active` BOOLEAN DEFAULT TRUE,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE `{$prefix}orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(50) NOT NULL UNIQUE,
            `user_id` INT,
            `customer_name` VARCHAR(255),
            `customer_email` VARCHAR(255),
            `total` DECIMAL(10,2) NOT NULL,
            `status` ENUM('pending', 'completed', 'cancelled') DEFAULT 'pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE `{$prefix}settings` (
            `key_name` VARCHAR(100) PRIMARY KEY,
            `value` TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        
        CREATE TABLE `{$prefix}cart` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `session_id` VARCHAR(255),
            `product_id` INT,
            `quantity` INT DEFAULT 1,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        // Execute each statement separately to avoid syntax issues
        $statements = explode(";\n", $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        
        // Insert admin user
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}users` (`email`, `password`, `first_name`, `last_name`, `role`) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute([$admin['email'], $admin['password'], $admin['first_name'], $admin['last_name']]);
        
        // Insert sample categories
        $categories = [
            ['Electronics', 'electronics', 'Latest gadgets and electronics'],
            ['Clothing', 'clothing', 'Fashion and accessories'],
            ['Home & Garden', 'home-garden', 'Home improvement supplies'],
            ['Books', 'books', 'Books and magazines']
        ];
        
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}categories` (`name`, `slug`, `description`) VALUES (?, ?, ?)");
        foreach ($categories as $cat) {
            $stmt->execute($cat);
        }
        
        // Insert sample products
        $products = [
            ['Smartphone X', 'smartphone-x', 'Latest smartphone with amazing features', 'High-end smartphone with 128GB storage', 699.99, 799.99, 'PHONE001', 50, 1],
            ['Laptop Pro', 'laptop-pro', 'Powerful laptop for professionals', '15-inch laptop with 16GB RAM and 512GB SSD', 1299.99, 1499.99, 'LAP001', 30, 1],
            ['Wireless Headphones', 'wireless-headphones', 'Noise-cancelling headphones', 'Premium wireless headphones with 30hr battery', 199.99, 249.99, 'HEAD001', 100, 1],
            ['Smart Watch', 'smart-watch', 'Track your fitness', 'Fitness tracker with heart rate monitor', 299.99, 349.99, 'WATCH001', 75, 0],
            ['Tablet', 'tablet', '10-inch tablet', 'Perfect for entertainment and work', 399.99, 499.99, 'TAB001', 40, 0]
        ];
        
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}products` (`name`, `slug`, `description`, `short_description`, `price`, `compare_price`, `sku`, `stock`, `featured`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($products as $prod) {
            $stmt->execute($prod);
        }
        
        // Insert settings
        $settings = [
            'shop_name' => $shop['name'],
            'shop_url' => $shop['url'],
            'shop_email' => $admin['email'],
            'currency' => 'USD',
            'currency_symbol' => '$',
            'tax_rate' => '0',
            'shipping_cost' => '10'
        ];
        
        $stmt = $pdo->prepare("INSERT INTO `{$prefix}settings` (`key_name`, `value`) VALUES (?, ?)");
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }
        
        // Create website files
        createWebsiteFiles($shop['url'], $db, $prefix);
        
        return ['success' => true, 'message' => 'Website created successfully!'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Installation Error: ' . $e->getMessage()];
    }
}

function createWebsiteFiles($baseUrl, $db, $prefix) {
    $dir = dirname(__FILE__);
    
    // Create config file
    $configContent = "<?php
// Database configuration
define('DB_HOST', '{$db['host']}');
define('DB_PORT', '{$db['port']}');
define('DB_NAME', '{$db['name']}');
define('DB_USER', '{$db['user']}');
define('DB_PASS', '{$db['pass']}');
define('DB_PREFIX', '{$prefix}');

// Shop configuration
define('SHOP_URL', '{$baseUrl}');
define('SHOP_NAME', '{$_SESSION['shop']['name']}');

session_start();

function getDB() {
    static \$pdo = null;
    if (\$pdo === null) {
        try {
            \$pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException \$e) {
            die('Database connection failed: ' . \$e->getMessage());
        }
    }
    return \$pdo;
}

function getSetting(\$key) {
    \$pdo = getDB();
    \$stmt = \$pdo->prepare('SELECT `value` FROM ' . DB_PREFIX . 'settings WHERE `key_name` = ?');
    \$stmt->execute([\$key]);
    return \$stmt->fetchColumn();
}

function isLoggedIn() {
    return isset(\$_SESSION['user_id']);
}
?>";
    file_put_contents("$dir/config.php", $configContent);
    
    // Create index.php
    $indexContent = '<?php
require_once "config.php";
$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "products WHERE featured = 1 AND active = 1 LIMIT 6");
$stmt->execute();
$featuredProducts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SHOP_NAME; ?> - Premium E-Commerce Store</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --primary: #DF0067; --dark: #251B5B; }
        .navbar { background: var(--dark); }
        .navbar-brand, .nav-link, .navbar-dark .navbar-nav .nav-link { color: white !important; }
        .hero { background: linear-gradient(135deg, var(--dark) 0%, #1a1245 100%); color: white; padding: 80px 0; margin-bottom: 50px; }
        .product-card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.3s; margin-bottom: 30px; }
        .product-card:hover { transform: translateY(-5px); }
        .product-price { color: var(--primary); font-size: 24px; font-weight: bold; }
        .btn-primary { background: var(--primary); border: none; }
        .btn-primary:hover { background: #b80052; }
        footer { background: var(--dark); color: white; padding: 40px 0; margin-top: 60px; }
        footer a { color: white; text-decoration: none; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-store"></i> <?php echo SHOP_NAME; ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="shop.php">Shop</a></li>
                    <li class="nav-item"><a class="nav-link" href="cart.php"><i class="fas fa-shopping-cart"></i> Cart</a></li>
                    <?php if(isLoggedIn()): ?>
                        <li class="nav-item"><a class="nav-link" href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="hero">
        <div class="container text-center">
            <h1 class="display-4 mb-4">Welcome to <?php echo SHOP_NAME; ?></h1>
            <p class="lead mb-4">Discover amazing products at unbeatable prices</p>
            <a href="shop.php" class="btn btn-primary btn-lg">Shop Now <i class="fas fa-arrow-right"></i></a>
        </div>
    </div>
    
    <div class="container">
        <h2 class="text-center mb-5">Featured Products</h2>
        <div class="row">
            <?php foreach($featuredProducts as $product): ?>
            <div class="col-md-4">
                <div class="card product-card">
                    <div class="card-body text-center">
                        <i class="fas fa-box fa-3x mb-3" style="color: var(--primary);"></i>
                        <h5 class="card-title"><?php echo htmlspecialchars($product["name"]); ?></h5>
                        <p class="card-text small"><?php echo htmlspecialchars(substr($product["short_description"], 0, 60)); ?></p>
                        <div class="product-price">$<?php echo number_format($product["price"], 2); ?></div>
                        <a href="product.php?id=<?php echo $product["id"]; ?>" class="btn btn-primary mt-3">View Details</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h5><?php echo SHOP_NAME; ?></h5>
                    <p>Your trusted e-commerce partner</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <h5>Contact</h5>
                    <p>Email: <?php echo getSetting("shop_email"); ?></p>
                </div>
            </div>
            <hr>
            <p class="text-center mb-0">&copy; 2024 <?php echo SHOP_NAME; ?>. All rights reserved.</p>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    file_put_contents("$dir/index.php", $indexContent);
    
    // Create shop.php
    $shopContent = '<?php
require_once "config.php";
$pdo = getDB();

$stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "products WHERE active = 1 ORDER BY created_at DESC");
$stmt->execute();
$products = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .product-card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 30px; transition: transform 0.3s; }
        .product-card:hover { transform: translateY(-5px); }
        .product-price { color: #DF0067; font-size: 20px; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-store"></i> <?php echo SHOP_NAME; ?></a>
            <div>
                <a href="cart.php" class="btn btn-outline-light me-2"><i class="fas fa-shopping-cart"></i> Cart</a>
                <a href="login.php" class="btn btn-outline-light">Login</a>
            </div>
        </div>
    </nav>
    
    <div class="container my-5">
        <h1 class="mb-4">All Products</h1>
        <div class="row">
            <?php foreach($products as $product): ?>
            <div class="col-md-3">
                <div class="card product-card">
                    <div class="card-body text-center">
                        <i class="fas fa-box fa-3x mb-3" style="color: #DF0067;"></i>
                        <h5><?php echo htmlspecialchars($product["name"]); ?></h5>
                        <p class="text-muted small"><?php echo htmlspecialchars(substr($product["short_description"], 0, 50)); ?></p>
                        <div class="product-price">$<?php echo number_format($product["price"], 2); ?></div>
                        <a href="product.php?id=<?php echo $product["id"]; ?>" class="btn btn-primary btn-sm mt-2">View Details</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>';
    file_put_contents("$dir/shop.php", $shopContent);
    
    // Create product.php
    $productContent = '<?php
require_once "config.php";
$pdo = getDB();

$id = $_GET["id"] ?? 0;
$stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "products WHERE id = ? AND active = 1");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header("Location: shop.php");
    exit;
}

// Add to cart logic
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $_SESSION["cart"][$id] = ($_SESSION["cart"][$id] ?? 0) + $_POST["quantity"];
    header("Location: cart.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product["name"]); ?> - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-store"></i> <?php echo SHOP_NAME; ?></a>
            <a href="shop.php" class="btn btn-outline-light">Continue Shopping</a>
        </div>
    </nav>
    
    <div class="container my-5">
        <div class="row">
            <div class="col-md-6">
                <div class="text-center bg-light p-5 rounded">
                    <i class="fas fa-box fa-5x" style="color: #DF0067;"></i>
                </div>
            </div>
            <div class="col-md-6">
                <h1><?php echo htmlspecialchars($product["name"]); ?></h1>
                <p class="lead"><?php echo htmlspecialchars($product["short_description"]); ?></p>
                <h2 class="text-primary">$<?php echo number_format($product["price"], 2); ?></h2>
                <?php if($product["compare_price"] > $product["price"]): ?>
                    <p class="text-muted"><s>$<?php echo number_format($product["compare_price"], 2); ?></s></p>
                <?php endif; ?>
                <p><strong>SKU:</strong> <?php echo $product["sku"]; ?></p>
                <p><strong>Stock:</strong> <?php echo $product["stock"]; ?> units</p>
                
                <form method="POST" class="mt-4">
                    <div class="row g-2">
                        <div class="col-auto">
                            <input type="number" name="quantity" value="1" min="1" class="form-control" style="width: 80px;">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-shopping-cart"></i> Add to Cart
                            </button>
                        </div>
                    </div>
                </form>
                
                <hr class="my-4">
                <h4>Description</h4>
                <p><?php echo nl2br(htmlspecialchars($product["description"])); ?></p>
            </div>
        </div>
    </div>
</body>
</html>';
    file_put_contents("$dir/product.php", $productContent);
    
    // Create cart.php
    $cartContent = '<?php
require_once "config.php";

$cart = $_SESSION["cart"] ?? [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["update"])) {
    $_SESSION["cart"] = $_POST["quantity"];
    header("Location: cart.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["checkout"])) {
    header("Location: checkout.php");
    exit;
}

$products = [];
$total = 0;

if (!empty($cart)) {
    $pdo = getDB();
    $ids = array_keys($cart);
     $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "products WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as &$product) {
        $product["quantity"] = $cart[$product["id"]];
        $product["subtotal"] = $product["price"] * $product["quantity"];
        $total += $product["subtotal"];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cart - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-store"></i> <?php echo SHOP_NAME; ?></a>
            <a href="shop.php" class="btn btn-outline-light">Continue Shopping</a>
        </div>
    </nav>
    
    <div class="container my-5">
        <h1 class="mb-4">Shopping Cart</h1>
        
        <?php if(empty($products)): ?>
            <div class="alert alert-info">
                Your cart is empty. <a href="shop.php">Start shopping</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <table class="table">
                    <thead>
                        <tr><th>Product</th><th>Price</th><th>Quantity</th><th>Subtotal</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($products as $product): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($product["name"]); ?></td>
                            <td>$<?php echo number_format($product["price"], 2); ?></td>
                            <td>
                                <input type="number" name="quantity[<?php echo $product["id"]; ?>]" 
                                       value="<?php echo $product["quantity"]; ?>" min="0" style="width: 70px;" class="form-control">
                            </td>
                            <td>$<?php echo number_format($product["subtotal"], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" class="text-end">Total:</th>
                            <th>$<?php echo number_format($total, 2); ?></th>
                        </tr>
                    </tfoot>
                </table>
                <div class="text-end">
                    <button type="submit" name="update" class="btn btn-secondary">Update Cart</button>
                    <button type="submit" name="checkout" class="btn btn-primary">Proceed to Checkout</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>';
    file_put_contents("$dir/cart.php", $cartContent);
    
    // Create login.php
    $loginContent = '<?php
require_once "config.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM " . DB_PREFIX . "users WHERE email = ?");
    $stmt->execute([$_POST["email"]]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($_POST["password"], $user["password"])) {
        $_SESSION["user_id"] = $user["id"];
        $_SESSION["user_name"] = $user["first_name"] . " " . $user["last_name"];
        $_SESSION["user_role"] = $user["role"];
        
        if ($user["role"] === "admin") {
            header("Location: admin.php");
        } else {
            header("Location: index.php");
        }
        exit;
    } else {
        $error = "Invalid email or password";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .login-container { max-width: 400px; margin: 80px auto; }
        .card { border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .btn-primary { background: #DF0067; border: none; }
        .btn-primary:hover { background: #b80052; }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <div class="login-container">
            <div class="text-center mb-4">
                <i class="fas fa-store fa-3x" style="color: #DF0067;"></i>
                <h2 class="mt-2"><?php echo SHOP_NAME; ?></h2>
                <p>Login to your account</p>
            </div>
            <div class="card">
                <div class="card-body">
                    <?php if(isset($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label>Email Address</label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                    <hr>
                    <p class="text-center mb-0">
                        <a href="index.php">Back to Store</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    file_put_contents("$dir/login.php", $loginContent);
    
    // Create logout.php
    file_put_contents("$dir/logout.php", '<?php session_start(); session_destroy(); header("Location: index.php"); exit; ?>');
    
    // Create admin.php
    $adminContent = '<?php
require_once "config.php";

if (!isset($_SESSION["user_role"]) || $_SESSION["user_role"] !== "admin") {
    header("Location: login.php");
    exit;
}

$pdo = getDB();

// Handle product addition
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["add_product"])) {
    $slug = strtolower(str_replace(" ", "-", $_POST["name"]));
    $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "products (`name`, `slug`, `description`, `short_description`, `price`, `compare_price`, `sku`, `stock`, `featured`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST["name"], $slug, $_POST["description"], $_POST["short_description"],
        $_POST["price"], $_POST["compare_price"], $_POST["sku"], $_POST["stock"],
        isset($_POST["featured"]) ? 1 : 0
    ]);
    $success = "Product added successfully!";
}

// Get statistics
$stats = [];
$stmt = $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "products");
$stats["products"] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "orders");
$stats["orders"] = $stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM " . DB_PREFIX . "users");
$stats["users"] = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT * FROM " . DB_PREFIX . "products ORDER BY created_at DESC LIMIT 5");
$recentProducts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="admin.php"><i class="fas fa-crown"></i> Admin Dashboard</a>
            <div>
                <span class="text-white me-3">Welcome, <?php echo htmlspecialchars($_SESSION["user_name"]); ?></span>
                <a href="logout.php" class="btn btn-danger btn-sm">Logout</a>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4">
        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-primary">
                    <div class="card-body">
                        <h5 class="card-title">Products</h5>
                        <h2><?php echo $stats["products"]; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-success">
                    <div class="card-body">
                        <h5 class="card-title">Orders</h5>
                        <h2><?php echo $stats["orders"]; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-info">
                    <div class="card-body">
                        <h5 class="card-title">Customers</h5>
                        <h2><?php echo $stats["users"]; ?></h2>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <h5>Recent Products</h5>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr><th>Name</th><th>Price</th><th>Stock</th><th>Featured</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach($recentProducts as $product): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($product["name"]); ?></td>
                                    <td>$<?php echo number_format($product["price"], 2); ?></td>
                                    <td><?php echo $product["stock"]; ?></td>
                                    <td><?php echo $product["featured"] ? "Yes" : "No"; ?></td>
                                    <td><a href="product.php?id=<?php echo $product["id"]; ?>" class="btn btn-sm btn-info">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5>Add New Product</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-2">
                                <input type="text" name="name" class="form-control form-control-sm" placeholder="Product Name" required>
                            </div>
                            <div class="mb-2">
                                <textarea name="short_description" class="form-control form-control-sm" rows="2" placeholder="Short Description"></textarea>
                            </div>
                            <div class="mb-2">
                                <textarea name="description" class="form-control form-control-sm" rows="3" placeholder="Full Description"></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <input type="number" step="0.01" name="price" class="form-control form-control-sm" placeholder="Price" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <input type="number" step="0.01" name="compare_price" class="form-control form-control-sm" placeholder="Compare Price">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <input type="text" name="sku" class="form-control form-control-sm" placeholder="SKU" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <input type="number" name="stock" class="form-control form-control-sm" placeholder="Stock" required>
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" name="featured" class="form-check-input">
                                <label class="form-check-label">Featured Product</label>
                            </div>
                            <button type="submit" name="add_product" class="btn btn-primary btn-sm w-100">Add Product</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>';
    file_put_contents("$dir/admin.php", $adminContent);
    
    // Create checkout.php
    $checkoutContent = '<?php
require_once "config.php";

if (empty($_SESSION["cart"])) {
    header("Location: cart.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $pdo = getDB();
    
    // Create order
    $orderNumber = "ORD-" . strtoupper(uniqid());
    $total = 0;
    
    foreach ($_SESSION["cart"] as $id => $qty) {
        $stmt = $pdo->prepare("SELECT price FROM " . DB_PREFIX . "products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        $total += $product["price"] * $qty;
    }
    
    $stmt = $pdo->prepare("INSERT INTO " . DB_PREFIX . "orders (`order_number`, `customer_name`, `customer_email`, `total`) VALUES (?, ?, ?, ?)");
    $stmt->execute([$orderNumber, $_POST["name"], $_POST["email"], $total]);
    
    // Clear cart
    unset($_SESSION["cart"]);
    
    header("Location: index.php?success=order");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo SHOP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <nav class="navbar navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-store"></i> <?php echo SHOP_NAME; ?></a>
            <a href="cart.php" class="btn btn-outline-light">Back to Cart</a>
        </div>
    </nav>
    
    <div class="container my-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h4>Checkout Information</h4>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label>Full Name</label>
                                <input type="text" name="name" class="form-control" required>
                            </div>
                            <div class="mb-3">
                                <label>Email Address</label>
                                <input type="email" name="email" class="form-control" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Place Order</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
    file_put_contents("$dir/checkout.php", $checkoutContent);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-Commerce Website Builder</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { max-width: 500px; width: 100%; }
        .card { background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); overflow: hidden; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .body { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; font-size: 14px; }
        input, select { width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 10px; font-size: 14px; transition: all 0.3s; }
        input:focus, select:focus { outline: none; border-color: #667eea; }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; border-radius: 10px; font-size: 16px; font-weight: 600; cursor: pointer; transition: transform 0.2s; }
        button:hover { transform: translateY(-2px); }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; }
        .success { background: #efe; color: #3c3; padding: 12px; border-radius: 10px; margin-bottom: 20px; }
        .steps { display: flex; justify-content: space-between; margin-bottom: 30px; }
        .step { flex: 1; text-align: center; position: relative; }
        .step.active .step-circle { background: #667eea; color: white; border-color: #667eea; }
        .step.completed .step-circle { background: #4caf50; color: white; border-color: #4caf50; }
        .step-circle { width: 35px; height: 35px; border-radius: 50%; background: white; border: 2px solid #ddd; display: inline-flex; align-items: center; justify-content: center; font-weight: bold; margin-bottom: 8px; }
        .step-label { font-size: 11px; color: #666; }
        hr { margin: 20px 0; border: none; border-top: 1px solid #eee; }
        .finish-info { background: #f0fdf4; border: 2px solid #4caf50; border-radius: 10px; padding: 20px; margin-top: 20px; }
        .finish-info ul { margin-top: 10px; margin-left: 20px; }
        .finish-info li { margin: 10px 0; }
        a { color: #667eea; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1><i class="fas fa-store"></i> E-Commerce Builder</h1>
                <p>Create your online store in minutes</p>
            </div>
            <div class="body">
                <?php if($error): ?>
                    <div class="error">❌ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if($currentStep !== 'finish'): ?>
                <div class="steps">
                    <div class="step <?php echo $currentStep == 'welcome' ? 'active' : (in_array($currentStep, ['database','account','install','finish']) ? 'completed' : ''); ?>">
                        <div class="step-circle">1</div>
                        <div class="step-label">Welcome</div>
                    </div>
                    <div class="step <?php echo $currentStep == 'database' ? 'active' : (in_array($currentStep, ['account','install','finish']) ? 'completed' : ''); ?>">
                        <div class="step-circle">2</div>
                        <div class="step-label">Database</div>
                    </div>
                    <div class="step <?php echo $currentStep == 'account' ? 'active' : (in_array($currentStep, ['install','finish']) ? 'completed' : ''); ?>">
                        <div class="step-circle">3</div>
                        <div class="step-label">Account</div>
                    </div>
                    <div class="step <?php echo $currentStep == 'install' ? 'active' : ($currentStep == 'finish' ? 'completed' : ''); ?>">
                        <div class="step-circle">4</div>
                        <div class="step-label">Install</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if($currentStep == 'welcome'): ?>
                    <div style="text-align: center;">
                        <p style="margin-bottom: 30px; color: #666;">This installer will create a complete working e-commerce website with:</p>
                        <ul style="text-align: left; margin-bottom: 30px; color: #666;">
                            <li>✅ Product catalog</li>
                            <li>✅ Shopping cart</li>
                            <li>✅ Admin dashboard</li>
                            <li>✅ User authentication</li>
                            <li>✅ MySQL database</li>
                        </ul>
                        <form method="POST">
                            <button type="submit" name="start">Start Installation →</button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <?php if($currentStep == 'database'): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Database Host</label>
                            <input type="text" name="db_host" value="localhost" required>
                        </div>
                        <div class="form-group">
                            <label>Database Port</label>
                            <input type="text" name="db_port" value="3306" required>
                        </div>
                        <div class="form-group">
                            <label>Database Name</label>
                            <input type="text" name="db_name" placeholder="e.g., ecommerce_db" required>
                        </div>
                        <div class="form-group">
                            <label>Database Username</label>
                            <input type="text" name="db_user" value="root" required>
                        </div>
                        <div class="form-group">
                            <label>Database Password</label>
                            <input type="password" name="db_pass" placeholder="Leave empty if no password">
                        </div>
                        <div class="form-group">
                            <label>Table Prefix</label>
                            <input type="text" name="db_prefix" value="ec_">
                        </div>
                        <button type="submit">Next Step →</button>
                    </form>
                <?php endif; ?>
                
                <?php if($currentStep == 'account'): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Shop Name</label>
                            <input type="text" name="shop_name" placeholder="My Awesome Store" required>
                        </div>
                        <div class="form-group">
                            <label>Admin Email</label>
                            <input type="email" name="admin_email" placeholder="admin@example.com" required>
                        </div>
                        <div class="form-group">
                            <label>Admin Password (min 8 chars)</label>
                            <input type="password" name="admin_pass" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label>Confirm Password</label>
                            <input type="password" name="admin_pass_confirm" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label>First Name</label>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-group">
                            <label>Last Name</label>
                            <input type="text" name="last_name" required>
                        </div>
                        <button type="submit">Install Website →</button>
                    </form>
                <?php endif; ?>
                
                <?php if($currentStep == 'install'): ?>
                    <div style="text-align: center;">
                        <p>Installing your e-commerce website...</p>
                        <div style="margin: 20px 0;">
                            <div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                        </div>
                        <p style="color: #666;">Please wait while we set up your store...</p>
                    </div>
                    <style>
                        @keyframes spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                    </style>
                    <meta http-equiv="refresh" content="2">
                <?php endif; ?>
                
                <?php if($currentStep == 'finish' && isset($_SESSION['install_result'])): ?>
                    <div class="finish-info">
                        <h3 style="color: #4caf50; margin-bottom: 15px;">✅ Installation Complete!</h3>
                        <p>Your e-commerce website has been successfully created!</p>
                        <ul>
                            <li><strong>Frontend Store:</strong> <a href="<?php echo $_SESSION['shop']['url']; ?>/index.php" target="_blank"><?php echo $_SESSION['shop']['url']; ?>/index.php</a></li>
                            <li><strong>Admin Panel:</strong> <a href="<?php echo $_SESSION['shop']['url']; ?>/admin.php" target="_blank"><?php echo $_SESSION['shop']['url']; ?>/admin.php</a></li>
                            <li><strong>Login Page:</strong> <a href="<?php echo $_SESSION['shop']['url']; ?>/login.php" target="_blank"><?php echo $_SESSION['shop']['url']; ?>/login.php</a></li>
                        </ul>
                        <hr>
                        <p><strong>Admin Credentials:</strong><br>
                        Email: <?php echo $_SESSION['admin']['email']; ?><br>
                        Password: (the password you entered)</p>
                        <hr>
                        <p style="color: #ff9800;"><strong>⚠️ Important:</strong> For security, please delete the installer.php file!</p>
                        <div style="margin-top: 20px; text-align: center;">
                            <a href="<?php echo $_SESSION['shop']['url']; ?>/index.php" style="display: inline-block; padding: 12px 24px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; text-decoration: none;">Go to Your Store →</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>