<?php
session_start();

// ==============================================
// CONEXÃO COM O BANCO DE DADOS
// ==============================================
$host = 'localhost';
$dbname = 'mercado_livre_clone';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // Cria o usuário admin se ele não existir
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['admin@admin.com']);
    $admin_exists = $stmt->fetch();

    if (!$admin_exists) {
        $hashed_password = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        $stmt->execute(['Admin', 'admin@admin.com', $hashed_password]);
    }

} catch (PDOException $e) {
    die("<div style='padding:20px;background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:5px;margin:20px;'>
        <h2>Erro de conexão com o banco de dados</h2>
        <p><strong>Mensagem:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
        <p>Verifique se o seu servidor MySQL está rodando e se as tabelas 'users', 'products', 'orders' e 'order_items' existem.</p></div>");
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatPrice($price) {
    return 'R$ ' . number_format($price, 2, ',', '.');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCartCount() {
    return isset($_SESSION['cart']) ? array_sum($_SESSION['cart']) : 0;
}

function getProductImage($image) {
    // Retorna a URL completa da imagem, seja ela local ou um link externo
    if (filter_var($image, FILTER_VALIDATE_URL)) {
        return $image;
    }
    return $image ? "assets/images/products/$image" : "https://via.placeholder.com/300x300?text=Produto";
}

// ==============================================
// PROCESSAMENTO DE FORMULÁRIOS E ROTAS
// ==============================================

// Login
if (isset($_POST['login'])) {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = "E-mail ou senha incorretos!";
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Registro
if (isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $register_error = "As senhas não coincidem.";
    } else {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $hashed_password]);
            header("Location: ".$_SERVER['PHP_SELF']."?login=1&registration_success=1");
            exit;
        } catch (PDOException $e) {
            $register_error = "Erro ao cadastrar: E-mail já em uso ou erro no banco de dados.";
        }
    }
}

// Adicionar ao carrinho
if (isset($_GET['add_to_cart'])) {
    $product_id = (int)$_GET['add_to_cart'];
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + 1;
    header("Location: ".$_SERVER['PHP_SELF']);
    exit;
}

// Remover do carrinho
if (isset($_GET['remove_from_cart'])) {
    $product_id = (int)$_GET['remove_from_cart'];
    if (isset($_SESSION['cart'][$product_id])) {
        unset($_SESSION['cart'][$product_id]);
    }
    header("Location: ".$_SERVER['PHP_SELF']."?cart=1");
    exit;
}

// Finalizar compra (Processamento do pagamento)
if (isset($_POST['process_payment'])) {
    if (!isLoggedIn()) {
        header("Location: ".$_SERVER['PHP_SELF']."?login_required=1");
        exit;
    }
    if (empty($_SESSION['cart'])) {
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    $payment_method = $_POST['payment_method'];
    $shipping_address = $_POST['shipping_address'];
    $payment_success = false;
    $payment_message = "";
    $installments = ($payment_method == 'credit_card' && isset($_POST['installments'])) ? (int)$_POST['installments'] : null;


    if ($payment_method == 'credit_card') {
        $card_number = $_POST['card_number'];
        $card_name = $_POST['card_name'];
        $card_expiry = $_POST['card_expiry'];
        $card_cvv = $_POST['card_cvv'];
        if (empty($card_number) || empty($card_name) || empty($card_expiry) || empty($card_cvv)) {
            $payment_message = "Por favor, preencha todos os dados do cartão.";
        } else {
            if (strlen($card_number) < 16) {
                $payment_message = "Número do cartão inválido.";
            } else {
                $payment_success = true;
            }
        }
    } elseif ($payment_method == 'pix') {
        $payment_success = true;
    }

    if ($payment_success) {
        try {
            $pdo->beginTransaction();
            $total = 0;
            $product_ids = array_keys($_SESSION['cart']);
            $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
            $stmt = $pdo->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");
            $stmt->execute($product_ids);
            
            while ($product = $stmt->fetch()) {
                $total += $product['price'] * $_SESSION['cart'][$product['id']];
            }
            
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, total, payment_method, shipping_address, installments) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_id'], $total, $payment_method, $shipping_address, $installments]);
            $order_id = $pdo->lastInsertId();
            
            foreach ($_SESSION['cart'] as $product_id => $quantity) {
                $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
                $stmt->execute([$product_id]);
                $price = $stmt->fetchColumn();
                
                $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
                $stmt->execute([$order_id, $product_id, $quantity, $price]);
            }
            
            $pdo->commit();
            unset($_SESSION['cart']);
            header("Location: ".$_SERVER['PHP_SELF']."?order_success=1&order_id=$order_id");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $checkout_error = "Erro ao finalizar compra: " . $e->getMessage();
        }
    } else {
        $checkout_error = $payment_message;
    }
}

// ==============================================
// FUNÇÕES DO PAINEL ADMIN
// ==============================================

// Adicionar produto (agora com URL da imagem)
function handleAddProduct($pdo) {
    if (!isLoggedIn() || $_SESSION['user_id'] != 1) return "Acesso negado.";
    if (empty($_POST['name']) || empty($_POST['price']) || empty($_POST['cost_price']) || empty($_POST['image_url'])) return "Nome, preço, custo e URL da imagem são obrigatórios.";
    
    $name = $_POST['name'];
    $price = $_POST['price'];
    $cost_price = $_POST['cost_price'];
    $old_price = !empty($_POST['old_price']) ? $_POST['old_price'] : null;
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $image_url = $_POST['image_url'];

    $stmt = $pdo->prepare("INSERT INTO products (name, description, price, cost_price, old_price, image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$name, $description, $price, $cost_price, $old_price, $image_url]);
    return "Produto adicionado com sucesso!";
}

// Editar produto (agora com URL da imagem)
function handleEditProduct($pdo) {
    if (!isLoggedIn() || $_SESSION['user_id'] != 1) return "Acesso negado.";
    if (empty($_POST['id']) || empty($_POST['name']) || empty($_POST['price']) || empty($_POST['cost_price']) || empty($_POST['image_url'])) return "ID, nome, preço, custo e URL da imagem são obrigatórios.";

    $id = $_POST['id'];
    $name = $_POST['name'];
    $price = $_POST['price'];
    $cost_price = $_POST['cost_price'];
    $old_price = !empty($_POST['old_price']) ? $_POST['old_price'] : null;
    $description = !empty($_POST['description']) ? $_POST['description'] : null;
    $image_url = $_POST['image_url'];

    $stmt = $pdo->prepare("UPDATE products SET name = ?, description = ?, price = ?, cost_price = ?, old_price = ?, image = ? WHERE id = ?");
    $stmt->execute([$name, $description, $price, $cost_price, $old_price, $image_url, $id]);
    return "Produto atualizado com sucesso!";
}

// Deletar produto
function handleDeleteProduct($pdo) {
    if (!isLoggedIn() || $_SESSION['user_id'] != 1) return "Acesso negado.";
    if (empty($_POST['id'])) return "ID do produto não especificado.";

    $id = $_POST['id'];
    
    try {
        $pdo->beginTransaction();

        // 1. Excluir os itens do pedido que se referem a este produto
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE product_id = ?");
        $stmt->execute([$id]);

        // 2. Excluir o produto da tabela 'products'
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);

        $pdo->commit();
        return "Produto deletado com sucesso!";

    } catch (Exception $e) {
        $pdo->rollBack();
        return "Erro ao deletar o produto: " . $e->getMessage();
    }
}


// Confirmar venda
function handleConfirmSale($pdo) {
    if (!isLoggedIn() || $_SESSION['user_id'] != 1) return "Acesso negado.";
    if (empty($_POST['order_id'])) return "ID do pedido não especificado.";
    $stmt = $pdo->prepare("UPDATE orders SET status = 'confirmed' WHERE id = ?");
    $stmt->execute([$_POST['order_id']]);
    return "Venda confirmada com sucesso!";
}

// Cancelar venda
function handleCancelSale($pdo) {
    if (!isLoggedIn() || $_SESSION['user_id'] != 1) return "Acesso negado.";
    if (empty($_POST['order_id'])) return "ID do pedido não especificado.";
    $stmt = $pdo->prepare("UPDATE orders SET status = 'canceled' WHERE id = ?");
    $stmt->execute([$_POST['order_id']]);
    return "Venda cancelada com sucesso!";
}

if (isset($_POST['add_product'])) {
    $admin_message = handleAddProduct($pdo);
} elseif (isset($_POST['edit_product'])) {
    $admin_message = handleEditProduct($pdo);
} elseif (isset($_POST['delete_product'])) {
    $admin_message = handleDeleteProduct($pdo);
} elseif (isset($_POST['confirm_sale'])) {
    $admin_message = handleConfirmSale($pdo);
} elseif (isset($_POST['cancel_sale'])) {
    $admin_message = handleCancelSale($pdo);
}

// ==============================================
// BUSCA DE DADOS E RELATÓRIOS DO PAINEL ADMIN
// ==============================================

$products = $pdo->query("SELECT * FROM products ORDER BY RAND() LIMIT 8")->fetchAll();
$all_products = $pdo->query("SELECT * FROM products ORDER BY created_at DESC")->fetchAll();
$all_orders = $pdo->query("SELECT o.*, u.name as user_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC")->fetchAll();

$confirmed_sales_total = $pdo->query("SELECT SUM(total) FROM orders WHERE status = 'confirmed'")->fetchColumn();
$canceled_sales_total = $pdo->query("SELECT SUM(total) FROM orders WHERE status = 'canceled'")->fetchColumn();
$pending_sales_total = $pdo->query("SELECT SUM(total) FROM orders WHERE status = 'pending'")->fetchColumn();

// Cálculo de Lucro Bruto
$gross_profit = $pdo->query("SELECT SUM((oi.price - p.cost_price) * oi.quantity) FROM order_items oi JOIN products p ON oi.product_id = p.id JOIN orders o ON oi.order_id = o.id WHERE o.status = 'confirmed'")->fetchColumn();

$cart_products = [];
$cart_total = 0;
if (!empty($_SESSION['cart'])) {
    $product_ids = array_keys($_SESSION['cart']);
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id IN ($placeholders)");
    $stmt->execute($product_ids);
    $cart_products = $stmt->fetchAll();
    
    foreach ($cart_products as $product) {
        $cart_total += $product['price'] * $_SESSION['cart'][$product['id']];
    }
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mercado Livre Clone</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #fff159;
            --secondary-color: #3483fa;
            --accent-color: #00a650;
            --danger-color: #f44336;
            --text-color: #333;
            --light-text-color: #666;
            --background-color: #f5f5f5;
            --card-background: #fff;
            --border-color: #eee;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Proxima Nova', -apple-system, 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: var(--secondary-color);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px;
        }

        /* HEADER */
        .header {
            background-color: var(--primary-color);
            padding: 10px 0;
            box-shadow: 0 1px 0 rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .header-container {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .logo {
            margin-right: 20px;
        }

        .logo img {
            height: 34px;
        }

        .search-box {
            flex-grow: 1;
            margin: 0 20px;
        }

        .search-form {
            display: flex;
        }

        .search-input {
            flex-grow: 1;
            padding: 10px 15px;
            border: none;
            border-radius: 4px 0 0 4px;
            font-size: 16px;
        }

        .search-button {
            background: var(--card-background);
            border: none;
            padding: 0 15px;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
        }

        .user-nav {
            display: flex;
            gap: 15px;
        }

        .nav-link {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 12px;
            color: var(--text-color);
            position: relative;
        }

        .nav-link i {
            font-size: 20px;
            margin-bottom: 2px;
        }

        .cart-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: var(--secondary-color);
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* MENU CATEGORIAS */
        .categories-nav {
            background: var(--card-background);
            padding: 10px 0;
            box-shadow: 0 1px 1px rgba(0,0,0,0.1);
        }

        .categories-list {
            display: flex;
            justify-content: space-around;
            list-style: none;
        }

        .categories-list li a {
            display: flex;
            flex-direction: column;
            align-items: center;
            font-size: 12px;
            color: var(--text-color);
            padding: 5px 10px;
        }

        .categories-list li i {
            font-size: 24px;
            margin-bottom: 5px;
            color: var(--secondary-color);
        }

        /* BANNER */
        .banner {
            margin: 20px 0;
        }

        .banner img {
            width: 100%;
            border-radius: 4px;
        }

        /* PRODUTOS */
        .section-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
        }

        .section-title h2, .section-title h3 {
            font-size: 24px;
            font-weight: 600;
        }

        .see-all {
            color: var(--secondary-color);
            font-size: 14px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .product-card {
            background: var(--card-background);
            border-radius: 4px;
            overflow: hidden;
            box-shadow: 0 1px 1px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .product-image-container {
            height: 200px;
            padding: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .product-image {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .product-info {
            padding: 15px;
            border-top: 1px solid var(--border-color);
        }

        .product-title {
            font-size: 14px;
            margin-bottom: 10px;
            height: 40px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .product-price {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .old-price {
            font-size: 14px;
            color: var(--light-text-color);
            text-decoration: line-through;
            margin-right: 5px;
        }

        .discount {
            color: var(--accent-color);
            font-size: 14px;
        }

        .installment {
            display: block;
            font-size: 12px;
            color: var(--accent-color);
            margin-bottom: 10px;
        }

        .add-to-cart {
            width: 100%;
            padding: 8px;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
            text-align: center;
            display: inline-block;
        }

        .add-to-cart:hover {
            background: #2968c8;
        }
        
        /* Containers */
        .cart-container, .auth-container, .admin-container, .checkout-container, .product-details-container {
            background: var(--card-background);
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,0.1);
            margin: 30px auto;
            max-width: 800px;
        }
        .admin-container {
            max-width: 1000px;
        }

        /* Tabelas */
        .cart-table, .admin-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cart-table th, .cart-table td, .admin-table th, .admin-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .cart-table th, .admin-table th {
            background: #f8f9fa;
        }

        .cart-product {
            display: flex;
            align-items: center;
        }

        .cart-product-image {
            width: 60px;
            margin-right: 15px;
        }

        .cart-remove {
            color: var(--danger-color);
            cursor: pointer;
        }
        
        /* Botões e Formulários */
        .btn {
            display: block;
            width: 100%;
            padding: 12px;
            background: var(--secondary-color);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #2968c8;
        }
        
        .btn-green { background: var(--accent-color); }
        .btn-green:hover { background: #00873e; }
        .btn-red { background: var(--danger-color); }
        .btn-red:hover { background: #d32f2f; }
        .btn-small { padding: 5px 10px; font-size: 12px; }
        
        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        
        .alert {
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .admin-action-buttons {
            display: flex;
            gap: 5px;
        }
        
        /* Dashboard Metrics */
        .dashboard-metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .metric {
            background: var(--card-background);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.2s;
        }
        .metric:hover {
            transform: translateY(-3px);
        }
        
        .metric h3 {
            font-size: 14px;
            color: var(--light-text-color);
            margin-bottom: 10px;
        }
        
        .metric .value {
            font-size: 28px;
            font-weight: bold;
        }
        
        /* FOOTER */
        .footer-menu {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            padding: 40px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .menu-column {
            flex: 1 1 200px;
            margin-bottom: 20px;
        }

        .menu-column h3 {
            font-size: 16px;
            margin-bottom: 15px;
        }
        
        .menu-item {
            list-style: none;
            margin-bottom: 8px;
        }

        .menu-item a {
            font-size: 14px;
            color: var(--light-text-color);
        }
        
        .footer-bottom {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
        }
        
        .footer-icons {
            display: flex;
            gap: 15px;
            font-size: 24px;
            color: var(--light-text-color);
            margin-bottom: 15px;
        }

        .footer-icons a {
            color: var(--light-text-color);
            transition: color 0.2s;
        }

        .footer-icons a:hover {
            color: var(--secondary-color);
        }

        .copyright {
            font-size: 12px;
            color: var(--light-text-color);
        }

        /* Product Detail Page */
        .product-details-container {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
        }
        .product-image-large {
            flex: 1 1 400px;
            max-width: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border-right: 1px solid var(--border-color);
        }
        .product-image-large img {
            max-width: 100%;
            height: auto;
        }
        .product-details-info {
            flex: 1 1 300px;
        }
        .product-details-info h1 {
            font-size: 30px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .product-details-description {
            margin-top: 20px;
            color: var(--light-text-color);
            line-height: 1.8;
        }
        .installment-info {
            font-size: 16px;
            color: var(--accent-color);
            font-weight: 600;
            margin-bottom: 15px;
        }

        /* Responsividade */
        @media (max-width: 768px) {
            .header-container { flex-wrap: wrap; }
            .logo { order: 1; width: 100%; text-align: center; margin-bottom: 10px; }
            .search-box { order: 3; width: 100%; margin: 10px 0; }
            .user-nav { order: 2; margin-left: auto; }
            .categories-list { flex-wrap: wrap; }
            .categories-list li { width: 25%; margin-bottom: 10px; }
            .products-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .admin-container { padding: 10px; }
            .admin-table th, .admin-table td { font-size: 12px; padding: 8px; }
            .product-details-container { flex-direction: column; }
            .product-image-large { max-width: 100%; border-right: none; }
        }
        
        @media (max-width: 480px) {
            .products-grid { grid-template-columns: repeat(2, 1fr); }
            .cart-table th, .admin-table th { display: none; }
            .cart-table td, .admin-table td { display: block; padding: 8px; text-align: right; }
            .cart-table td:before, .admin-table td:before { content: attr(data-label); font-weight: bold; display: inline-block; width: 80px; text-align: left; }
            .footer-menu { flex-direction: column; }
            .menu-column { flex: 1 1 100%; }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-container">
                <a href="?" class="logo"><img src="https://http2.mlstatic.com/frontend-assets/ml-web-navigation/ui-navigation/5.21.22/mercadolibre/logo__large_plus.png" alt="Mercado Livre"></a>
                <div class="search-box">
                    <form class="search-form"><input type="text" placeholder="Buscar produtos, marcas e mais..." class="search-input"><button type="submit" class="search-button"><i class="fas fa-search"></i></button></form>
                </div>
                <nav class="user-nav">
                    <?php if (isLoggedIn()): ?>
                        <a href="?" class="nav-link"><i class="fas fa-user"></i><span><?= htmlspecialchars($_SESSION['user_name']) ?></span></a>
                        <?php if ($_SESSION['user_id'] == 1): ?>
                            <a href="?page=admin" class="nav-link"><i class="fas fa-tools"></i><span>Admin</span></a>
                        <?php endif; ?>
                        <a href="?logout=1" class="nav-link"><i class="fas fa-sign-out-alt"></i><span>Sair</span></a>
                        <a href="?cart=1" class="nav-link"><i class="fas fa-shopping-cart"></i><span>Carrinho</span><?php if (getCartCount() > 0): ?><span class="cart-count"><?= getCartCount() ?></span><?php endif; ?></a>
                    <?php else: ?>
                        <a href="?login=1" class="nav-link"><i class="fas fa-sign-in-alt"></i><span>Entrar</span></a>
                        <a href="?register=1" class="nav-link"><i class="fas fa-user-plus"></i><span>Cadastrar</span></a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </header>
    <nav class="categories-nav">
        <div class="container">
            <ul class="categories-list">
                <li><a href="#"><i class="fas fa-laptop"></i> Tecnologia</a></li>
                <li><a href="#"><i class="fas fa-tshirt"></i> Moda</a></li>
                <li><a href="#"><i class="fas fa-home"></i> Casa</a></li>
                <li><a href="#"><i class="fas fa-gamepad"></i> Games</a></li>
                <li><a href="#"><i class="fas fa-mobile-alt"></i> Celulares</a></li>
                <li><a href="#"><i class="fas fa-utensils"></i> Supermercado</a></li>
                <li><a href="#"><i class="fas fa-tools"></i> Ferramentas</a></li>
            </ul>
        </div>
    </nav>
    <main class="container">
        <?php if (isset($_GET['login_required']) && $_GET['login_required'] == 1): ?>
            <div class="alert alert-danger">Você precisa estar logado para finalizar a compra. <a href="?login=1">Clique aqui para fazer login</a>.</div>
        <?php endif; ?>
        <?php if (isset($_GET['order_success']) && $_GET['order_success'] == 1): ?>
            <div class="alert alert-success"><h3>Compra realizada com sucesso!</h3><p>Seu pedido #<?= htmlspecialchars($_GET['order_id']) ?> foi confirmado.</p><p>Obrigado por comprar conosco!</p></div>
        <?php endif; ?>
        <?php if (isset($checkout_error)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($checkout_error) ?></div>
        <?php endif; ?>

        <?php if (isset($_GET['cart'])): ?>
            <div class="cart-container">
                <h2>Seu Carrinho</h2>
                <?php if (empty($cart_products)): ?>
                    <p>Seu carrinho está vazio</p><a href="?" class="btn">Continuar comprando</a>
                <?php else: ?>
                    <table class="cart-table">
                        <thead><tr><th>Produto</th><th>Preço</th><th>Quantidade</th><th>Subtotal</th><th>Ação</th></tr></thead>
                        <tbody>
                            <?php foreach ($cart_products as $product): ?>
                                <tr>
                                    <td data-label="Produto"><div class="cart-product"><img src="<?= getProductImage($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="cart-product-image"><span><?= htmlspecialchars($product['name']) ?></span></div></td>
                                    <td data-label="Preço"><?= formatPrice($product['price']) ?></td>
                                    <td data-label="Quantidade"><?= $_SESSION['cart'][$product['id']] ?></td>
                                    <td data-label="Subtotal"><?= formatPrice($product['price'] * $_SESSION['cart'][$product['id']]) ?></td>
                                    <td data-label="Ação"><a href="?remove_from_cart=<?= $product['id'] ?>" class="cart-remove"><i class="fas fa-trash"></i></a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="cart-total">Total: <?= formatPrice($cart_total) ?></div>
                    <a href="?checkout_page=1" class="checkout-button"><i class="fas fa-credit-card"></i> Finalizar Compra</a>
                <?php endif; ?>
            </div>
        <?php elseif (isset($_GET['checkout_page'])): ?>
            <div class="checkout-container">
                <h2>Checkout</h2>
                <?php if (!isLoggedIn()): ?>
                    <div class="alert alert-danger">Você precisa estar logado para finalizar a compra. <a href="?login=1">Clique aqui para fazer login</a>.</div>
                <?php elseif (empty($cart_products)): ?>
                    <div class="alert alert-danger">Seu carrinho está vazio. Adicione produtos para continuar.</div>
                    <a href="?" class="btn">Voltar para a loja</a>
                <?php else: ?>
                    <form method="post" action="?">
                        <h3>Informações de Envio</h3>
                        <div class="form-group"><label for="shipping_address">Endereço Completo</label><textarea id="shipping_address" name="shipping_address" class="form-control" rows="3" required></textarea></div>
                        <div class="form-group"><label>Nome</label><input type="text" class="form-control" value="<?= htmlspecialchars($_SESSION['user_name']) ?>" disabled></div>
                        <div class="form-group"><label>Email</label><input type="email" class="form-control" value="<?= htmlspecialchars($_SESSION['user_email']) ?>" disabled></div>

                        <h3>Total do Pedido: <?= formatPrice($cart_total) ?></h3>

                        <div class="payment-options">
                            <h3>Forma de Pagamento</h3>
                            <label><input type="radio" name="payment_method" value="credit_card" required checked>Cartão de Crédito</label>
                            <label><input type="radio" name="payment_method" value="pix" required>Pix</label>
                        </div>

                        <div id="credit-card-fields" class="payment-fields">
                            <div class="form-group"><label for="card_number">Número do Cartão</label><input type="text" id="card_number" name="card_number" class="form-control" required></div>
                            <div class="form-group"><label for="card_name">Nome no Cartão</label><input type="text" id="card_name" name="card_name" class="form-control" required></div>
                            <div class="form-group"><label for="card_expiry">Validade (MM/AA)</label><input type="text" id="card_expiry" name="card_expiry" class="form-control" placeholder="MM/AA" required></div>
                            <div class="form-group"><label for="card_cvv">CVV</label><input type="text" id="card_cvv" name="card_cvv" class="form-control" required></div>
                            <div class="form-group">
                                <label for="installments">Parcelamento</label>
                                <select id="installments" name="installments" class="form-control">
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?= $i ?>">
                                            <?= $i ?>x de <?= formatPrice($cart_total / $i) ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p id="installment-text" class="installment-info" style="margin-top: 10px;">
                                    Em até 10x de <?= formatPrice($cart_total / 10) ?> sem juros.
                                </p>
                            </div>
                        </div>

                        <div id="pix-info" class="pix-info" style="display:none;">
                            <p>Envie o valor de **<?= formatPrice($cart_total) ?>** para a chave Pix:</p>
                            <p style="font-size: 1.2em; font-weight: bold; color: var(--accent-color);">47988482384</p>
                            <p>Sua compra será processada assim que o pagamento for confirmado.</p>
                        </div>
                        
                        <button type="submit" name="process_payment" class="checkout-button"><i class="fas fa-lock"></i> Pagar Agora</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php elseif (isset($_GET['login'])): ?>
            <div class="auth-container">
                <h2 class="auth-title">Login</h2>
                <?php if (isset($login_error)): ?><div class="alert alert-danger"><?= htmlspecialchars($login_error) ?></div><?php endif; ?>
                <?php if (isset($_GET['registration_success'])): ?><div class="alert alert-success">Cadastro realizado com sucesso! Faça login para continuar.</div><?php endif; ?>
                <form method="post" action="?">
                    <div class="form-group"><label for="email">E-mail</label><input type="email" id="email" name="email" class="form-control" required></div>
                    <div class="form-group"><label for="password">Senha</label><input type="password" id="password" name="password" class="form-control" required></div>
                    <button type="submit" name="login" class="btn">Entrar</button>
                </form>
                <p style="text-align:center;margin-top:15px;">Não tem uma conta? <a href="?register=1">Cadastre-se</a></p>
            </div>
        <?php elseif (isset($_GET['register'])): ?>
            <div class="auth-container">
                <h2 class="auth-title">Criar Conta</h2>
                <?php if (isset($register_error)): ?><div class="alert alert-danger"><?= htmlspecialchars($register_error) ?></div><?php endif; ?>
                <form method="post" action="?">
                    <div class="form-group"><label for="name">Nome Completo</label><input type="text" id="name" name="name" class="form-control" required></div>
                    <div class="form-group"><label for="email">E-mail</label><input type="email" id="email" name="email" class="form-control" required></div>
                    <div class="form-group"><label for="password">Senha</label><input type="password" id="password" name="password" class="form-control" required></div>
                    <div class="form-group"><label for="confirm_password">Confirmar Senha</label><input type="password" id="confirm_password" name="confirm_password" class="form-control" required></div>
                    <button type="submit" name="register" class="btn">Cadastrar</button>
                </form>
                <p style="text-align:center;margin-top:15px;">Já tem uma conta? <a href="?login=1">Faça login</a></p>
            </div>
        <?php elseif (isset($_GET['page']) && $_GET['page'] == 'admin' && isLoggedIn() && $_SESSION['user_id'] == 1): ?>
            <div class="admin-container">
                <h2 class="auth-title">Painel Administrativo</h2>
                <?php if (isset($admin_message)): ?><div class="alert <?= strpos($admin_message, 'sucesso') !== false ? 'alert-success' : 'alert-danger' ?>"><?= htmlspecialchars($admin_message) ?></div><?php endif; ?>

                <div class="section-title">
                    <h3>Dashboard de Vendas</h3>
                </div>
                <div class="dashboard-metrics">
                    <div class="metric">
                        <h3>Lucro Bruto</h3>
                        <p class="value" style="color: var(--accent-color);"><?= formatPrice($gross_profit) ?></p>
                    </div>
                    <div class="metric">
                        <h3>Vendas Confirmadas</h3>
                        <p class="value" style="color: var(--secondary-color);"><?= formatPrice($confirmed_sales_total) ?></p>
                    </div>
                    <div class="metric">
                        <h3>Vendas Pendentes</h3>
                        <p class="value" style="color: #ff9900;"><?= formatPrice($pending_sales_total) ?></p>
                    </div>
                    <div class="metric">
                        <h3>Vendas Canceladas</h3>
                        <p class="value" style="color: var(--danger-color);"><?= formatPrice($canceled_sales_total) ?></p>
                    </div>
                </div>

                <div class="section-title">
                    <h3>Gerenciar Produtos</h3>
                    <a href="?page=admin&section=add_product" class="btn btn-green btn-small" style="width: auto;">+ Adicionar Produto</a>
                </div>

                <?php if (isset($_GET['section']) && $_GET['section'] == 'add_product'): ?>
                    <form method="post" action="?page=admin">
                        <div class="form-group"><label for="name">Nome do Produto</label><input type="text" id="name" name="name" class="form-control" required></div>
                        <div class="form-group"><label for="description">Descrição</label><textarea id="description" name="description" class="form-control"></textarea></div>
                        <div class="form-group"><label for="price">Preço de Venda</label><input type="number" step="0.01" id="price" name="price" class="form-control" required></div>
                        <div class="form-group"><label for="cost_price">Preço de Custo</label><input type="number" step="0.01" id="cost_price" name="cost_price" class="form-control" required></div>
                        <div class="form-group"><label for="old_price">Preço Antigo (Opcional)</label><input type="number" step="0.01" id="old_price" name="old_price" class="form-control"></div>
                        <div class="form-group"><label for="image_url">Link da Imagem do Produto</label><input type="text" id="image_url" name="image_url" class="form-control" placeholder="Cole a URL da imagem aqui" required></div>
                        <button type="submit" name="add_product" class="btn btn-green">Adicionar Produto</button>
                    </form>
                <?php elseif (isset($_GET['section']) && $_GET['section'] == 'edit_product' && isset($_GET['id'])): ?>
                    <?php
                    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
                    $stmt->execute([$_GET['id']]);
                    $product_to_edit = $stmt->fetch();
                    if (!$product_to_edit) {
                        echo "<div class='alert alert-danger'>Produto não encontrado.</div>";
                    } else { ?>
                    <form method="post" action="?page=admin">
                        <input type="hidden" name="id" value="<?= $product_to_edit['id'] ?>">
                        <div class="form-group"><label for="name">Nome do Produto</label><input type="text" id="name" name="name" class="form-control" value="<?= htmlspecialchars($product_to_edit['name']) ?>" required></div>
                        <div class="form-group"><label for="description">Descrição</label><textarea id="description" name="description" class="form-control"><?= htmlspecialchars($product_to_edit['description'] ?? '') ?></textarea></div>
                        <div class="form-group"><label for="price">Preço de Venda</label><input type="number" step="0.01" id="price" name="price" class="form-control" value="<?= htmlspecialchars($product_to_edit['price']) ?>" required></div>
                        <div class="form-group"><label for="cost_price">Preço de Custo</label><input type="number" step="0.01" id="cost_price" name="cost_price" class="form-control" value="<?= htmlspecialchars($product_to_edit['cost_price'] ?? '') ?>" required></div>
                        <div class="form-group"><label for="old_price">Preço Antigo (Opcional)</label><input type="number" step="0.01" id="old_price" name="old_price" class="form-control" value="<?= htmlspecialchars($product_to_edit['old_price'] ?? '') ?>"></div>
                        <div class="form-group">
                            <label>Imagem Atual</label>
                            <img src="<?= getProductImage($product_to_edit['image']) ?>" alt="<?= htmlspecialchars($product_to_edit['name']) ?>" style="max-width: 150px; display: block; margin-bottom: 10px;">
                            <label for="image_url">Novo Link da Imagem</label>
                            <input type="text" id="image_url" name="image_url" class="form-control" value="<?= htmlspecialchars($product_to_edit['image'] ?? '') ?>" placeholder="Cole a URL da imagem aqui" required>
                        </div>
                        <button type="submit" name="edit_product" class="btn btn-green">Atualizar Produto</button>
                        <a href="?page=admin" class="btn" style="background-color: #555; margin-top: 10px;">Cancelar</a>
                    </form>
                    <?php } ?>
                <?php else: ?>
                    <table class="admin-table">
                        <thead><tr><th>ID</th><th>Imagem</th><th>Nome</th><th>Preço</th><th>Ações</th></tr></thead>
                        <tbody>
                            <?php foreach ($all_products as $product): ?>
                                <tr>
                                    <td><?= htmlspecialchars($product['id']) ?></td>
                                    <td data-label="Imagem"><img src="<?= getProductImage($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" style="width: 50px; height: 50px; object-fit: cover;"></td>
                                    <td data-label="Nome"><?= htmlspecialchars($product['name']) ?></td>
                                    <td data-label="Preço"><?= formatPrice($product['price']) ?></td>
                                    <td data-label="Ações">
                                        <div class="admin-action-buttons">
                                            <a href="?page=admin&section=edit_product&id=<?= $product['id'] ?>" class="btn btn-small">Editar</a>
                                            <form method="post" action="?page=admin" onsubmit="return confirm('Tem certeza que deseja deletar este produto?');">
                                                <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                                <button type="submit" name="delete_product" class="btn btn-small btn-red">Deletar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="section-title" style="margin-top: 40px;"><h3>Gerenciar Pedidos</h3></div>
                <table class="admin-table">
                    <thead><tr><th>ID Pedido</th><th>Cliente</th><th>Total</th><th>Status</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($all_orders as $order): ?>
                            <tr>
                                <td><?= htmlspecialchars($order['id']) ?></td>
                                <td data-label="Cliente"><?= htmlspecialchars($order['user_name']) ?></td>
                                <td data-label="Total"><?= formatPrice($order['total']) ?></td>
                                <td data-label="Status"><?= htmlspecialchars(ucfirst($order['status'])) ?></td>
                                <td data-label="Ações">
                                    <div class="admin-action-buttons">
                                        <?php if ($order['status'] == 'pending'): ?>
                                            <form method="post" action="?page=admin">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <button type="submit" name="confirm_sale" class="btn btn-small btn-green">Confirmar</button>
                                            </form>
                                            <form method="post" action="?page=admin">
                                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                <button type="submit" name="cancel_sale" class="btn btn-small btn-red">Cancelar</button>
                                            </form>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif (isset($_GET['product_id'])): ?>
            <?php
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$_GET['product_id']]);
            $product_details = $stmt->fetch();
            if (!$product_details) {
                echo "<div class='alert alert-danger'>Produto não encontrado.</div>";
            } else { ?>
            <div class="product-details-container">
                <div class="product-image-large">
                    <img src="<?= getProductImage($product_details['image']) ?>" alt="<?= htmlspecialchars($product_details['name']) ?>">
                </div>
                <div class="product-details-info">
                    <h1><?= htmlspecialchars($product_details['name']) ?></h1>
                    <?php if ($product_details['old_price'] > 0): ?>
                        <div><span class="old-price"><?= formatPrice($product_details['old_price']) ?></span><span class="discount"><?= round(100 - ($product_details['price'] / $product_details['old_price'] * 100)) ?>% OFF</span></div>
                    <?php endif; ?>
                    <div class="product-price"><?= formatPrice($product_details['price']) ?></div>
                    <?php if ($product_details['price'] > 100): ?><span class="installment">em até 10x de <?= formatPrice($product_details['price'] / 10) ?> sem juros</span><?php endif; ?>
                    <a href="?add_to_cart=<?= $product_details['id'] ?>" class="add-to-cart" style="margin-top: 20px;">
                        <i class="fas fa-cart-plus"></i> Adicionar ao Carrinho
                    </a>
                    <div class="product-details-description">
                        <h3>Descrição do Produto</h3>
                        <p><?= nl2br(htmlspecialchars($product_details['description'] ?? 'Sem descrição disponível.')) ?></p>
                    </div>
                </div>
            </div>
            <?php } ?>
        <?php else: ?>
            <div class="banner"><img src="https://http2.mlstatic.com/D_NQ_863733-MLA53759498636_022023-OO.webp" alt="Ofertas Especiais"></div>
            <div class="section-title"><h2>Ofertas do dia</h2><a href="#" class="see-all">Ver todas</a></div>
            <div class="products-grid">
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <a href="?product_id=<?= $product['id'] ?>">
                            <div class="product-image-container"><img src="<?= getProductImage($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-image"></div>
                            <div class="product-info">
                                <h3 class="product-title"><?= htmlspecialchars($product['name']) ?></h3>
                                <?php if ($product['old_price'] > 0): ?>
                                    <div><span class="old-price"><?= formatPrice($product['old_price']) ?></span><span class="discount"><?= round(100 - ($product['price'] / $product['old_price'] * 100)) ?>% OFF</span></div>
                                <?php endif; ?>
                                <div class="product-price"><?= formatPrice($product['price']) ?></div>
                                <?php if ($product['price'] > 100): ?><span class="installment">em até 10x de <?= formatPrice($product['price'] / 10) ?> sem juros</span><?php endif; ?>
                            </div>
                        </a>
                        <a href="?add_to_cart=<?= $product['id'] ?>" class="add-to-cart"><i class="fas fa-cart-plus"></i> Adicionar</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <footer class="footer">
        <div class="container">
            <div class="footer-menu">
                <div class="menu-column">
                    <h3>Sobre nós</h3>
                    <ul>
                        <li class="menu-item"><a href="#">Quem somos</a></li>
                        <li class="menu-item"><a href="#">Trabalhe conosco</a></li>
                        <li class="menu-item"><a href="#">Sustentabilidade</a></li>
                    </ul>
                </div>
                <div class="menu-column">
                    <h3>Ajuda</h3>
                    <ul>
                        <li class="menu-item"><a href="#">Perguntas frequentes</a></li>
                        <li class="menu-item"><a href="#">Termos e condições</a></li>
                        <li class="menu-item"><a href="#">Política de privacidade</a></li>
                    </ul>
                </div>
                <div class="menu-column">
                    <h3>Pagamento</h3>
                    <div class="footer-icons">
                        <i class="fab fa-cc-visa"></i>
                        <i class="fab fa-cc-mastercard"></i>
                        <i class="fab fa-cc-paypal"></i>
                        <i class="fas fa-barcode"></i>
                    </div>
                </div>
                <div class="menu-column">
                    <h3>Siga-nos</h3>
                    <div class="footer-icons">
                        <a href="#"><i class="fab fa-facebook"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <p class="copyright">&copy; 2024 Mercado Livre Clone. Todos os direitos reservados.</p>
            </div>
        </div>
    </footer>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const radioButtons = document.querySelectorAll('input[name="payment_method"]');
            const creditCardFields = document.getElementById('credit-card-fields');
            const pixInfo = document.getElementById('pix-info');
            const form = document.querySelector('.checkout-container form');
            
            function togglePaymentFields() {
                if (!form) return;
                const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
                if (paymentMethod && paymentMethod.value === 'credit_card') {
                    creditCardFields.style.display = 'block';
                    pixInfo.style.display = 'none';
                    creditCardFields.querySelectorAll('input').forEach(input => input.setAttribute('required', 'required'));
                } else {
                    creditCardFields.style.display = 'none';
                    pixInfo.style.display = 'block';
                    creditCardFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
                }
            }
            
            function updateInstallmentText() {
                const total = <?= json_encode($cart_total) ?>;
                const installmentsSelect = document.getElementById('installments');
                const installmentText = document.getElementById('installment-text');
                const selectedInstallments = parseInt(installmentsSelect.value);
                const installmentPrice = total / selectedInstallments;
                
                installmentText.textContent = `${selectedInstallments}x de R$ ${installmentPrice.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} sem juros`;
            }

            if (form) {
                radioButtons.forEach(radio => {
                    radio.addEventListener('change', togglePaymentFields);
                });
                
                const installmentsSelect = document.getElementById('installments');
                if (installmentsSelect) {
                    installmentsSelect.addEventListener('change', updateInstallmentText);
                }
                
                togglePaymentFields();
                if (installmentsSelect) {
                    updateInstallmentText();
                }
            }
        });
    </script>
</body>
</html>