<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function shop_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function shop_body(): array
{
    if (! empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function shop_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $storageDir = __DIR__.'/storage';
    if (! is_dir($storageDir)) {
        mkdir($storageDir, 0777, true);
    }

    $pdo = new PDO('sqlite:'.$storageDir.'/shop.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('CREATE TABLE IF NOT EXISTS brands (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, sku TEXT NOT NULL, price REAL NOT NULL, brand_id INTEGER, in_stock INTEGER NOT NULL, description TEXT, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS variants (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, size TEXT NOT NULL, color TEXT NOT NULL, stock INTEGER NOT NULL, price_modifier REAL NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS specs (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, weight TEXT, dimensions TEXT, material TEXT)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS product_category (product_id INTEGER NOT NULL, category_id INTEGER NOT NULL)');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO brands (name) VALUES ('Acme Corp'), ('Globex Inc')");
        $pdo->exec("INSERT INTO categories (name) VALUES ('Clothing'), ('Electronics'), ('Home & Garden')");

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $pdo->prepare('INSERT INTO products (name, sku, price, brand_id, in_stock, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 5; $i++) {
            $stmt->execute([
                "Product {$i}",
                "SKU-{$i}-001",
                19.99 + ($i * 5),
                ($i % 2) + 1,
                1,
                "Description for product {$i}",
                $now,
            ]);
        }

        $pdo->exec('INSERT INTO variants (product_id, size, color, stock, price_modifier) VALUES (1, "S", "Red", 10, 0)');
        $pdo->exec('INSERT INTO variants (product_id, size, color, stock, price_modifier) VALUES (1, "M", "Blue", 20, 2)');
        $pdo->exec('INSERT INTO specs (product_id, weight, dimensions, material) VALUES (1, "250g", "10x5x3cm", "Cotton")');
        $pdo->exec('INSERT INTO product_category (product_id, category_id) VALUES (1, 1), (1, 2), (2, 3)');
    }

    return $pdo;
}

$db = shop_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health' || $path === '/api/health') {
    shop_json(['ok' => true, 'service' => 'shop-api']);
}

if ($method === 'GET' && $path === '/api/brands') {
    $rows = $db->query('SELECT id, name FROM brands ORDER BY id')->fetchAll();
    shop_json(['results' => $rows]);
}

if ($method === 'GET' && $path === '/api/categories') {
    $rows = $db->query('SELECT id, name FROM categories ORDER BY id')->fetchAll();
    shop_json(['results' => $rows]);
}

if ($method === 'GET' && preg_match('#^/api/products/(\d+)/variants$#', $path, $m) === 1) {
    $stmt = $db->prepare('SELECT id, size, color, stock, price_modifier FROM variants WHERE product_id = ? ORDER BY id');
    $stmt->execute([(int) $m[1]]);
    shop_json(['results' => $stmt->fetchAll()]);
}

if ($method === 'GET' && preg_match('#^/api/products/(\d+)/specs$#', $path, $m) === 1) {
    $stmt = $db->prepare('SELECT weight, dimensions, material FROM specs WHERE product_id = ? LIMIT 1');
    $stmt->execute([(int) $m[1]]);
    $row = $stmt->fetch();
    shop_json(['results' => $row ?: []]);
}

if ($method === 'POST' && preg_match('#^/api/products/(\d+)/categories$#', $path, $m) === 1) {
    shop_json(['success' => true, 'product_id' => (int) $m[1]]);
}

if ($method === 'GET' && $path === '/api/products') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = max(1, (int) ($_GET['page_size'] ?? ($_GET['per_page'] ?? 10)));

    $where = [];
    $bindings = [];

    // Search: ?search=term — matches name or sku
    if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
        $where[] = '(name LIKE :search_n OR sku LIKE :search_s)';
        $bindings[':search_n'] = "%{$search}%";
        $bindings[':search_s'] = "%{$search}%";
    }

    // Filters: ?filter[brand_id]=1&filter[in_stock]=1
    $filters = $_GET['filter'] ?? [];
    if (is_array($filters)) {
        $allowedFilters = ['brand_id', 'in_stock'];
        $fi = 0;
        foreach ($filters as $field => $value) {
            if (in_array($field, $allowedFilters, true) && $value !== '') {
                $ph = ":f{$fi}";
                $where[] = "{$field} = {$ph}";
                $bindings[$ph] = $value;
                $fi++;
            }
        }
    }

    $whereSql = $where !== [] ? 'WHERE '.implode(' AND ', $where) : '';

    // Sorting: ?sort=-price (prefix - for desc)
    $orderBy = 'id';
    $orderDir = 'DESC';
    if (($sort = trim((string) ($_GET['sort'] ?? ''))) !== '') {
        $desc = str_starts_with($sort, '-');
        $col = ltrim($sort, '-');
        $allowedSort = ['id', 'name', 'sku', 'price', 'created_at'];
        if (in_array($col, $allowedSort, true)) {
            $orderBy = $col;
            $orderDir = $desc ? 'DESC' : 'ASC';
        }
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM products {$whereSql}");
    foreach ($bindings as $ph => $val) {
        $countStmt->bindValue($ph, $val);
    }
    $countStmt->execute();
    $count = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $pageSize;

    $sql = "SELECT id, name, sku, price, brand_id, in_stock, description, created_at FROM products {$whereSql} ORDER BY {$orderBy} {$orderDir} LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($bindings as $ph => $val) {
        $stmt->bindValue($ph, $val);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    shop_json([
        'results' => $rows,
        'count' => $count,
        'next' => $offset + $pageSize < $count
            ? '/api/products?'.http_build_query([...$_GET, 'page' => $page + 1])
            : null,
        'previous' => $page > 1
            ? '/api/products?'.http_build_query([...$_GET, 'page' => $page - 1])
            : null,
    ]);
}

if ($method === 'GET' && preg_match('#^/api/products/(\d+)$#', $path, $m) === 1) {
    $stmt = $db->prepare('SELECT id, name, sku, price, brand_id, in_stock, description, created_at FROM products WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    $row = $stmt->fetch();
    if ($row === false) {
        shop_json(['detail' => 'Not found'], 404);
    }

    shop_json($row);
}

if ($method === 'POST' && $path === '/api/products') {
    $body = shop_body();
    $name = trim((string) ($body['name'] ?? ''));
    $price = (float) ($body['price'] ?? 0);

    if ($name === '' || $price <= 0) {
        $errors = [];
        if ($name === '') {
            $errors[] = ['field' => 'name', 'message' => 'This field is required.'];
        }
        if ($price <= 0) {
            $errors[] = ['field' => 'price', 'message' => 'Ensure this value is greater than 0.'];
        }
        shop_json(['detail' => 'Validation Error', 'errors' => $errors], 422);
    }

    $sku = (string) ($body['sku'] ?? ('SKU-'.random_int(1000, 9999)));
    $brandId = (int) ($body['brand_id'] ?? 1);
    $inStock = (int) ((bool) ($body['in_stock'] ?? true));
    $description = (string) ($body['description'] ?? '');
    $createdAt = gmdate('Y-m-d\TH:i:s\Z');

    $stmt = $db->prepare('INSERT INTO products (name, sku, price, brand_id, in_stock, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$name, $sku, $price, $brandId, $inStock, $description, $createdAt]);

    $id = (int) $db->lastInsertId();
    $item = $db->query("SELECT id, name, sku, price, brand_id, in_stock, description, created_at FROM products WHERE id = {$id}")->fetch();
    shop_json($item, 201);
}

if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/api/products/(\d+)$#', $path, $m) === 1) {
    $id = (int) $m[1];
    $body = shop_body();
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();

    if ($current === false) {
        shop_json(['detail' => 'Not found'], 404);
    }

    $name = (string) ($body['name'] ?? $current['name']);
    $sku = (string) ($body['sku'] ?? $current['sku']);
    $price = (float) ($body['price'] ?? $current['price']);
    $brandId = (int) ($body['brand_id'] ?? $current['brand_id']);
    $inStock = (int) (isset($body['in_stock']) ? (bool) $body['in_stock'] : (bool) $current['in_stock']);
    $description = (string) ($body['description'] ?? $current['description']);

    $upd = $db->prepare('UPDATE products SET name = ?, sku = ?, price = ?, brand_id = ?, in_stock = ?, description = ? WHERE id = ?');
    $upd->execute([$name, $sku, $price, $brandId, $inStock, $description, $id]);

    $item = $db->query("SELECT id, name, sku, price, brand_id, in_stock, description, created_at FROM products WHERE id = {$id}")->fetch();
    shop_json($item);
}

if ($method === 'DELETE' && preg_match('#^/api/products/(\d+)$#', $path, $m) === 1) {
    $stmt = $db->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    http_response_code(204);
    exit;
}

shop_json(['detail' => 'Not found', 'path' => $path], 404);
