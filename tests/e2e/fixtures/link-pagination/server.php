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
if (is_string($path) && preg_match('#^/save(?:/7(/tags)?)?$#', $path, $match) === 1) {
    $statePath = __DIR__.'/save-state.json';
    $state = ['id' => 7, 'title' => 'Original', 'tag_ids' => [1]];
    $saved = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    if (is_array($saved) && is_string($saved['title'] ?? null) && is_array($saved['tag_ids'] ?? null)) {
        $state['title'] = $saved['title'];
        $state['tag_ids'] = $saved['tag_ids'];
    }
    $tags = [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'Laravel'], ['id' => 3, 'name' => 'Testing']];
    $isRelation = isset($match[1]);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'PUT') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (! is_array($payload)) {
            http_response_code(422);
            exit;
        }
        if ($isRelation && $state['title'] === 'Fail relation') {
            http_response_code(500);
            echo json_encode(['password' => 'private-relation-secret']);
            exit;
        }
        if ($isRelation) {
            if (! is_array($payload['ids'] ?? null)) {
                http_response_code(422);
                exit;
            }
            $state['tag_ids'] = array_map(
                static fn (mixed $id): int => is_numeric($id) ? (int) $id : throw new InvalidArgumentException('Invalid tag ID.'),
                $payload['ids'],
            );
        } else {
            if (! is_string($payload['title'] ?? null)) {
                http_response_code(422);
                exit;
            }
            $state['title'] = $payload['title'];
        }
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        http_response_code(204);
        exit;
    }
    $data = $isRelation ? array_values(array_filter($tags, static fn (array $tag): bool => in_array($tag['id'], $state['tag_ids'], true))) : $state;
    echo json_encode(['data' => $path === '/save' ? [$state] : $data]);
    exit;
}
if ($path === '/tags') {
    echo json_encode(['data' => [['id' => 1, 'name' => 'PHP'], ['id' => 2, 'name' => 'Laravel'], ['id' => 3, 'name' => 'Testing']]]);
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
if (! in_array($path, ['/simple', '/numbered', '/cursor'], true)) {
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
if ($path === '/cursor') {
    $offset = is_string($_GET['after'] ?? null) ? (int) base64_decode($_GET['after'], true) : 0;
    echo json_encode([
        'data' => array_slice($rows, $offset, 2),
        'cursor' => ['next' => $offset + 2 < count($rows) ? base64_encode((string) ($offset + 2)) : null],
    ]);
    exit;
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
