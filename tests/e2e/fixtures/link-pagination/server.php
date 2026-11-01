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
