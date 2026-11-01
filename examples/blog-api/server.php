<?php

declare(strict_types=1);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function blog_json(mixed $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function blog_body(): array
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

function blog_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $storageDir = __DIR__.'/storage';
    if (! is_dir($storageDir)) {
        mkdir($storageDir, 0777, true);
    }

    $pdo = new PDO('sqlite:'.$storageDir.'/blog.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('CREATE TABLE IF NOT EXISTS categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, slug TEXT NOT NULL, body TEXT, category_id INTEGER, status TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS comments (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL, author_name TEXT NOT NULL, body TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS post_tag (post_id INTEGER NOT NULL, tag_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS authors (id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER NOT NULL UNIQUE, name TEXT NOT NULL, email TEXT NOT NULL, bio TEXT)');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO categories (name) VALUES ('Technology'), ('Science'), ('Design')");
        $pdo->exec("INSERT INTO tags (name) VALUES ('PHP'), ('Laravel'), ('MoonShine')");

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $pdo->prepare('INSERT INTO posts (title, slug, body, category_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 6; $i++) {
            $stmt->execute([
                "Post {$i}",
                "post-{$i}",
                "Body for post {$i}",
                ($i % 3) + 1,
                $i % 2 === 0 ? 'draft' : 'published',
                $now,
                $now,
            ]);
        }

        $pdo->exec("INSERT INTO comments (post_id, author_name, body, created_at) VALUES (1, 'Alice', 'Great post', '{$now}')");
        $pdo->exec("INSERT INTO comments (post_id, author_name, body, created_at) VALUES (1, 'Bob', 'Thanks', '{$now}')");
        $pdo->exec('INSERT INTO post_tag (post_id, tag_id) VALUES (1, 1), (1, 2), (2, 3)');
        $pdo->exec("INSERT INTO authors (post_id, name, email, bio) VALUES (1, 'John Doe', 'john@example.com', 'Senior tech writer')");
    }

    return $pdo;
}

$db = blog_db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health' || $path === '/v1/health') {
    blog_json(['ok' => true, 'service' => 'blog-api']);
}

if ($method === 'GET' && $path === '/v1/categories') {
    $rows = $db->query('SELECT id, name FROM categories ORDER BY id')->fetchAll();
    blog_json(['data' => $rows]);
}

if ($method === 'GET' && $path === '/v1/tags') {
    $rows = $db->query('SELECT id, name FROM tags ORDER BY id')->fetchAll();
    blog_json(['data' => $rows]);
}

// Authors standalone list — lets the admin expose Author as a `main` resource
// (CRUD in /blog-admin/resource/author-resource/...) alongside its use as a
// `has_one` target of Post. Returns Laravel paginator shape.
if ($method === 'GET' && $path === '/v1/authors') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, (int) ($_GET['per_page'] ?? 10));
    $total = (int) $db->query('SELECT COUNT(*) FROM authors')->fetchColumn();
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare('SELECT id, post_id, name, email, bio FROM authors ORDER BY id ASC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $lastPage = max(1, (int) ceil($total / $perPage));
    $basePath = '/v1/authors';

    blog_json([
        'data' => $rows,
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'last_page' => $lastPage,
        'from' => $total === 0 ? null : ($offset + 1),
        'to' => min($offset + $perPage, $total),
        'path' => $basePath,
        'first_page_url' => $basePath.'?page=1',
        'last_page_url' => $basePath.'?page='.$lastPage,
        'prev_page_url' => $page > 1 ? $basePath.'?page='.($page - 1) : null,
        'next_page_url' => $page < $lastPage ? $basePath.'?page='.($page + 1) : null,
    ]);
}

// Single author CRUD by id — GET/PUT/DELETE /v1/authors/{id}
if (preg_match('#^/v1/authors/(\d+)$#', $path, $m) === 1) {
    $id = (int) $m[1];

    if ($method === 'GET') {
        $row = $db->query("SELECT id, post_id, name, email, bio FROM authors WHERE id = {$id}")->fetch();
        if (! $row) {
            blog_json(['message' => 'Author not found'], 404);
        }
        blog_json(['data' => $row]);
    }

    if ($method === 'PUT') {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $stmt = $db->prepare('UPDATE authors SET name = ?, email = ?, bio = ? WHERE id = ?');
        $stmt->execute([$body['name'] ?? '', $body['email'] ?? '', $body['bio'] ?? null, $id]);
        $row = $db->query("SELECT id, post_id, name, email, bio FROM authors WHERE id = {$id}")->fetch();
        blog_json(['data' => $row]);
    }

    if ($method === 'DELETE') {
        $db->prepare('DELETE FROM authors WHERE id = ?')->execute([$id]);
        blog_json(['deleted' => true]);
    }
}

if ($method === 'GET' && preg_match('#^/v1/posts/(\d+)/comments$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $sort = (string) ($_GET['sort'] ?? 'id');
    $direction = str_starts_with($sort, '-') ? 'DESC' : 'ASC';
    $sortField = ltrim($sort, '-');
    $allowedSortFields = ['id', 'author_name', 'body', 'created_at'];

    if (! in_array($sortField, $allowedSortFields, true)) {
        $sortField = 'id';
    }

    $sql = "SELECT id, author_name, body, created_at FROM comments WHERE post_id = ? ORDER BY {$sortField} {$direction}, id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute([$postId]);
    blog_json(['data' => $stmt->fetchAll()]);
}

if (preg_match('#^/v1/posts/(\d+)/author$#', $path, $m) === 1) {
    $postId = (int) $m[1];

    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT id, post_id, name, email, bio FROM authors WHERE post_id = ?');
        $stmt->execute([$postId]);
        $row = $stmt->fetch();
        if (! $row) {
            blog_json(['message' => 'Author not found', 'path' => $path], 404);
        }
        blog_json(['data' => $row]);
    }

    $input = blog_body();

    if ($method === 'POST') {
        $exists = (int) $db->query("SELECT COUNT(*) FROM authors WHERE post_id = {$postId}")->fetchColumn();
        if ($exists > 0) {
            blog_json(['message' => 'Author already exists for this post'], 409);
        }
        $stmt = $db->prepare('INSERT INTO authors (post_id, name, email, bio) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $postId,
            (string) ($input['name'] ?? ''),
            (string) ($input['email'] ?? ''),
            (string) ($input['bio'] ?? ''),
        ]);
        $row = $db->query("SELECT id, post_id, name, email, bio FROM authors WHERE post_id = {$postId}")->fetch();
        blog_json(['data' => $row], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $exists = (int) $db->query("SELECT COUNT(*) FROM authors WHERE post_id = {$postId}")->fetchColumn();
        if ($exists === 0) {
            $stmt = $db->prepare('INSERT INTO authors (post_id, name, email, bio) VALUES (?, ?, ?, ?)');
            $stmt->execute([
                $postId,
                (string) ($input['name'] ?? ''),
                (string) ($input['email'] ?? ''),
                (string) ($input['bio'] ?? ''),
            ]);
        } else {
            $stmt = $db->prepare('UPDATE authors SET name = ?, email = ?, bio = ? WHERE post_id = ?');
            $stmt->execute([
                (string) ($input['name'] ?? ''),
                (string) ($input['email'] ?? ''),
                (string) ($input['bio'] ?? ''),
                $postId,
            ]);
        }
        $row = $db->query("SELECT id, post_id, name, email, bio FROM authors WHERE post_id = {$postId}")->fetch();
        blog_json(['data' => $row]);
    }

    if ($method === 'DELETE') {
        $stmt = $db->prepare('DELETE FROM authors WHERE post_id = ?');
        $stmt->execute([$postId]);
        blog_json(['deleted' => true], 200);
    }
}

if ($method === 'GET' && $path === '/v1/posts') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = max(1, (int) ($_GET['per_page'] ?? 10));

    $where = [];
    $bindings = [];

    // Search: ?search=term — matches title or slug
    if (($search = trim((string) ($_GET['search'] ?? ''))) !== '') {
        $where[] = '(title LIKE :search_t OR slug LIKE :search_s)';
        $bindings[':search_t'] = "%{$search}%";
        $bindings[':search_s'] = "%{$search}%";
    }

    // Filters: ?filter[status]=published&filter[category_id]=2
    $filters = $_GET['filter'] ?? [];
    if (is_array($filters)) {
        $allowedFilters = ['status', 'category_id'];
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

    // Sorting: ?sort=-title (prefix - for desc)
    $orderBy = 'id';
    $orderDir = 'ASC';
    if (($sort = trim((string) ($_GET['sort'] ?? ''))) !== '') {
        $desc = str_starts_with($sort, '-');
        $col = ltrim($sort, '-');
        $allowedSort = ['id', 'title', 'slug', 'status', 'created_at', 'updated_at'];
        if (in_array($col, $allowedSort, true)) {
            $orderBy = $col;
            $orderDir = $desc ? 'DESC' : 'ASC';
        }
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM posts {$whereSql}");
    foreach ($bindings as $ph => $val) {
        $countStmt->bindValue($ph, $val);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;

    $sql = "SELECT id, title, slug, body, category_id, status, created_at, updated_at FROM posts {$whereSql} ORDER BY {$orderBy} {$orderDir} LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    foreach ($bindings as $ph => $val) {
        $stmt->bindValue($ph, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $lastPage = (int) ceil($total / $perPage);
    $basePath = '/v1/posts';

    blog_json([
        'data' => $rows,
        'current_page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'last_page' => $lastPage,
        'from' => $total === 0 ? null : ($offset + 1),
        'to' => min($offset + $perPage, $total),
        'path' => $basePath,
        'links' => [],
        'first_page_url' => $basePath.'?page=1',
        'last_page_url' => $basePath.'?page='.max(1, $lastPage),
        'prev_page_url' => $page > 1 ? $basePath.'?page='.($page - 1) : null,
        'next_page_url' => $page < $lastPage ? $basePath.'?page='.($page + 1) : null,
    ]);
}

if ($method === 'GET' && preg_match('#^/v1/posts/(\d+)$#', $path, $m) === 1) {
    $stmt = $db->prepare('SELECT id, title, slug, body, category_id, status, created_at, updated_at FROM posts WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    $row = $stmt->fetch();
    if ($row === false) {
        blog_json(['message' => 'Post not found'], 404);
    }

    blog_json($row);
}

if ($method === 'POST' && $path === '/v1/posts') {
    $body = blog_body();
    if (($body['title'] ?? '') === '') {
        blog_json([
            'message' => 'Validation failed',
            'errors' => ['title' => ['The title field is required.']],
        ], 422);
    }

    $title = (string) $body['title'];
    $slug = (string) ($body['slug'] ?? strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-')));
    $postBody = (string) ($body['body'] ?? '');
    $categoryId = (int) ($body['category_id'] ?? 1);
    $status = (string) ($body['status'] ?? 'draft');
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $stmt = $db->prepare('INSERT INTO posts (title, slug, body, category_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$title, $slug, $postBody, $categoryId, $status, $now, $now]);

    $id = (int) $db->lastInsertId();
    $item = $db->query("SELECT id, title, slug, body, category_id, status, created_at, updated_at FROM posts WHERE id = {$id}")->fetch();
    blog_json($item, 201);
}

if (($method === 'PUT' || $method === 'PATCH') && preg_match('#^/v1/posts/(\d+)$#', $path, $m) === 1) {
    $id = (int) $m[1];
    $body = blog_body();
    $stmt = $db->prepare('SELECT * FROM posts WHERE id = ?');
    $stmt->execute([$id]);
    $current = $stmt->fetch();
    if ($current === false) {
        blog_json(['message' => 'Post not found'], 404);
    }

    $title = (string) ($body['title'] ?? $current['title']);
    $slug = (string) ($body['slug'] ?? $current['slug']);
    $postBody = (string) ($body['body'] ?? $current['body']);
    $categoryId = (int) ($body['category_id'] ?? $current['category_id']);
    $status = (string) ($body['status'] ?? $current['status']);
    $now = gmdate('Y-m-d\TH:i:s\Z');

    $upd = $db->prepare('UPDATE posts SET title = ?, slug = ?, body = ?, category_id = ?, status = ?, updated_at = ? WHERE id = ?');
    $upd->execute([$title, $slug, $postBody, $categoryId, $status, $now, $id]);

    $item = $db->query("SELECT id, title, slug, body, category_id, status, created_at, updated_at FROM posts WHERE id = {$id}")->fetch();
    blog_json($item);
}

if ($method === 'DELETE' && preg_match('#^/v1/posts/(\d+)$#', $path, $m) === 1) {
    $stmt = $db->prepare('DELETE FROM posts WHERE id = ?');
    $stmt->execute([(int) $m[1]]);
    http_response_code(204);
    exit;
}

// GET /v1/posts/{id}/tags — current tags for a post
if ($method === 'GET' && preg_match('#^/v1/posts/(\d+)/tags$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $stmt = $db->prepare('SELECT t.id, t.name FROM tags t JOIN post_tag pt ON pt.tag_id = t.id WHERE pt.post_id = ? ORDER BY t.id');
    $stmt->execute([$postId]);
    blog_json(['data' => $stmt->fetchAll()]);
}

// PUT /v1/posts/{id}/tags — sync tags (replace all)
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/v1/posts/(\d+)/tags$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $body = blog_body();
    $ids = $body['ids'] ?? [];

    $db->prepare('DELETE FROM post_tag WHERE post_id = ?')->execute([$postId]);

    if (is_array($ids)) {
        $ins = $db->prepare('INSERT INTO post_tag (post_id, tag_id) VALUES (?, ?)');
        foreach ($ids as $tagId) {
            $ins->execute([$postId, (int) $tagId]);
        }
    }

    blog_json(['success' => true, 'post_id' => $postId, 'synced' => count((array) $ids)]);
}

// POST /v1/posts/{id}/comments — create comment
if ($method === 'POST' && preg_match('#^/v1/posts/(\d+)/comments$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $body = blog_body();
    $authorName = trim((string) ($body['author_name'] ?? ''));
    $commentBody = trim((string) ($body['body'] ?? ''));

    if ($authorName === '' || $commentBody === '') {
        $errors = [];
        if ($authorName === '') {
            $errors['author_name'] = ['This field is required.'];
        }
        if ($commentBody === '') {
            $errors['body'] = ['This field is required.'];
        }
        blog_json(['message' => 'Validation failed', 'errors' => $errors], 422);
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $db->prepare('INSERT INTO comments (post_id, author_name, body, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$postId, $authorName, $commentBody, $now]);

    $id = (int) $db->lastInsertId();
    $row = $db->query("SELECT id, post_id, author_name, body, created_at FROM comments WHERE id = {$id}")->fetch();
    blog_json($row, 201);
}

// PUT /v1/posts/{id}/comments/{commentId} — update comment
if (($method === 'PUT' || $method === 'POST') && preg_match('#^/v1/posts/(\d+)/comments/(\d+)$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $commentId = (int) $m[2];
    $body = blog_body();
    $authorName = trim((string) ($body['author_name'] ?? ''));
    $commentBody = trim((string) ($body['body'] ?? ''));

    if ($authorName === '' || $commentBody === '') {
        $errors = [];
        if ($authorName === '') {
            $errors['author_name'] = ['This field is required.'];
        }
        if ($commentBody === '') {
            $errors['body'] = ['This field is required.'];
        }
        blog_json(['message' => 'Validation failed', 'errors' => $errors], 422);
    }

    $existsStmt = $db->prepare('SELECT id FROM comments WHERE id = ? AND post_id = ?');
    $existsStmt->execute([$commentId, $postId]);
    $exists = $existsStmt->fetch();
    if (! $exists) {
        blog_json(['message' => 'Comment not found'], 404);
    }

    $upd = $db->prepare('UPDATE comments SET author_name = ?, body = ? WHERE id = ? AND post_id = ?');
    $upd->execute([$authorName, $commentBody, $commentId, $postId]);

    $rowStmt = $db->prepare('SELECT id, post_id, author_name, body, created_at FROM comments WHERE id = ?');
    $rowStmt->execute([$commentId]);
    $row = $rowStmt->fetch();
    blog_json($row);
}

// DELETE /v1/posts/{id}/comments/{commentId} — delete comment
if ($method === 'DELETE' && preg_match('#^/v1/posts/(\d+)/comments/(\d+)$#', $path, $m) === 1) {
    $postId = (int) $m[1];
    $commentId = (int) $m[2];

    $stmt = $db->prepare('DELETE FROM comments WHERE id = ? AND post_id = ?');
    $stmt->execute([$commentId, $postId]);

    if ($stmt->rowCount() === 0) {
        blog_json(['message' => 'Comment not found'], 404);
    }

    http_response_code(204);
    exit;
}

blog_json(['message' => 'Not found', 'path' => $path], 404);
