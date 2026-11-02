<?php

declare(strict_types=1);

header('Content-Type: application/json');
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = parse_url($requestUri, PHP_URL_PATH);
if ($path === '/health') {
    echo json_encode(['ok' => true]);
    exit;
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer link-pagination-token') {
    http_response_code(401);
    echo json_encode(['message' => 'Server credentials required']);
    exit;
}
if (is_string($path) && preg_match('#^/owners(?:/(\d+))?$#', $path, $match) === 1) {
    $names = [1 => 'Technology', 2 => 'Science', 3 => 'Design'];
    $owners = array_map(static fn (int $id): array => [
        'id' => $id, 'name' => $names[$id] ?? 'Related category '.$id,
    ], range(1, 26));
    if (isset($match[1])) {
        $owner = $owners[(int) $match[1] - 1] ?? null;
        http_response_code($owner === null ? 404 : 200);
        echo json_encode(['data' => $owner]);
    } else {
        $search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
        $owners = array_values(array_filter($owners, static fn (array $owner): bool => str_contains($owner['name'], $search)));
        echo json_encode(['data' => array_slice($owners, 0, 25), 'total' => count($owners), 'per_page' => 25, 'current_page' => 1, 'last_page' => (int) ceil(count($owners) / 25)]);
    }
    exit;
}
if (! in_array($path, ['/simple', '/numbered'], true)) {
    http_response_code(404);
    exit;
}

$filters = is_array($_GET['filter'] ?? null) ? $_GET['filter'] : [];
$search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
$rows = [];
for ($id = 1; $id <= 11; $id++) {
    $item = ['id' => $id, 'title' => ($id === 11 ? 'Other item ' : 'Link item ').$id, 'status' => $id % 2 === 1 ? 'published' : 'draft'];
    if (isset($filters['status']) && $filters['status'] !== $item['status']) {
        continue;
    }
    if ($search !== '' && ! str_contains($item['title'], $search)) {
        continue;
    }
    $rows[] = $item;
}
if (($_GET['sort'] ?? '') === '-id') {
    $rows = array_reverse($rows);
}
$page = max(1, is_numeric($_GET['page'] ?? null) ? (int) $_GET['page'] : 1);
$lastPage = max(1, (int) ceil(count($rows) / 2));
$pages = ['first' => 1];
if ($page > 1) {
    $pages['prev'] = $page - 1;
}
if ($page < $lastPage) {
    $pages['next'] = $page + 1;
}
if ($path === '/numbered') {
    $pages['last'] = $lastPage;
    header('X-Total-Count: '.count($rows));
}
$links = [];
$port = is_string($_SERVER['SERVER_PORT'] ?? null) ? $_SERVER['SERVER_PORT'] : '';
foreach ($pages as $rel => $number) {
    $links[] = '<http://127.0.0.1:'.$port.$path.'?page='.$number.'&api_key=upstream-only>; rel="'.$rel.'"';
}
header('Link: '.implode(', ', $links));
echo json_encode(['data' => array_slice($rows, ($page - 1) * 2, 2)]);
