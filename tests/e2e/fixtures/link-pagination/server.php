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
if (is_string($path) && str_starts_with($path, '/mixed/')) {
    $statePath = __DIR__.'/mixed-state.json';
    /** @return array<string, int|string>|null */
    $readRow = static function (mixed $value): ?array {
        if (! is_array($value) || $value === []) {
            return null;
        }
        $row = [];
        foreach ($value as $column => $cell) {
            if (! is_string($column) || (! is_int($cell) && ! is_string($cell))) {
                return null;
            }
            $row[$column] = $cell;
        }

        return $row;
    };
    $initial = [
        'settings' => [
            ['name' => 'site_title', 'label' => 'Site title', 'value' => 'MoonShine V fixture', 'status' => 'published'],
            ['name' => 'timezone', 'label' => 'Timezone', 'value' => 'UTC', 'status' => 'published'],
        ],
        'task' => ['id' => 1, 'title' => 'Mapping task 1', 'status' => 'published', 'setting_name' => 'site_title', 'permission_id' => 2],
    ];
    $saved = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    $state = $initial;
    if (is_array($saved)) {
        $savedSettings = $saved['settings'] ?? null;
        foreach ($initial['settings'] as $index => $setting) {
            $savedSetting = $readRow(is_array($savedSettings) ? ($savedSettings[$index] ?? null) : null);
            if ($savedSetting !== null && ($savedSetting['name'] ?? null) === $setting['name']) {
                $state['settings'][$index] = array_replace($setting, $savedSetting);
            }
        }
        $savedTask = $readRow($saved['task'] ?? null);
        if ($savedTask !== null) {
            $state['task'] = array_replace($initial['task'], $savedTask);
        }
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($path === '/mixed/state') {
        if ($method === 'PUT') {
            $state = $initial;
            file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        }
        echo json_encode($state);
        exit;
    }
    if (preg_match('#^/mixed/(tasks|transcriptions|notifications|permissions|settings)(?:/([^/]+))?$#', $path, $match) !== 1) {
        http_response_code(404);
        exit;
    }
    $resource = $match[1];
    $key = isset($match[2]) ? rawurldecode($match[2]) : null;
    $primaryKey = $resource === 'settings' ? 'name' : 'id';
    if ($resource === 'settings') {
        $rows = $state['settings'];
    } elseif ($resource === 'permissions') {
        $rows = [
            ['id' => 1, 'label' => 'View projects', 'status' => 'published'],
            ['id' => 2, 'label' => 'Edit projects', 'status' => 'published'],
        ];
    } else {
        $rows = [];
        for ($id = 1; $id <= 9; $id++) {
            $rows[] = $resource === 'tasks' && $id === 1
                ? $state['task']
                : ['id' => $id, 'title' => ($id === 9 ? 'Other' : 'Mapping').' '.$resource.' '.$id, 'status' => $id % 2 === 1 ? 'published' : 'draft'];
        }
    }
    if ($key !== null) {
        $matching = array_find($rows, static fn (array $row): bool => (string) $row[$primaryKey] === $key);
        if ($matching === null) {
            http_response_code(404);
            exit;
        }
        if ($method === 'PUT' && ($resource === 'settings' || ($resource === 'tasks' && $key === '1'))) {
            $input = $readRow(json_decode((string) file_get_contents('php://input'), true));
            if ($input === null) {
                http_response_code(422);
                exit;
            }
            $matching = array_replace($matching, $input, [$primaryKey => $matching[$primaryKey]]);
            if ($resource === 'settings') {
                $state['settings'] = array_map(static fn (array $row): array => $row['name'] === $key ? $matching : $row, $rows);
            } else {
                $state['task'] = $matching;
            }
            file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            http_response_code(204);
            exit;
        }
        if ($resource === 'settings') {
            http_response_code(405);
            exit;
        }
        echo json_encode(['data' => $matching]);
        exit;
    }
    $filters = is_array($_GET['filter'] ?? null) ? $_GET['filter'] : [];
    $search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
    $lookup = $_GET[$primaryKey] ?? null;
    if ($lookup !== null && ! is_string($lookup) && ! is_int($lookup)) {
        http_response_code(422);
        exit;
    }
    $rows = array_values(array_filter($rows, static function (array $row) use ($filters, $search, $lookup, $primaryKey): bool {
        if ($lookup !== null && (string) $row[$primaryKey] !== (string) $lookup) {
            return false;
        }
        if (isset($filters['status']) && $row['status'] !== $filters['status']) {
            return false;
        }

        return $search === '' || str_contains(implode(' ', $row), $search);
    }));
    if (($_GET['sort'] ?? '') === '-id') {
        $rows = array_reverse($rows);
    }
    if ($resource === 'permissions') {
        echo json_encode($rows);
    } elseif ($resource === 'settings') {
        echo json_encode(['data' => $rows]);
    } else {
        $cursor = is_string($_GET['cursor'] ?? null) ? base64_decode($_GET['cursor'], true) : false;
        $offset = is_string($cursor) && ctype_digit($cursor) ? (int) $cursor : 0;
        $next = $offset + 2 < count($rows) ? base64_encode((string) ($offset + 2)) : null;
        $body = ['data' => array_slice($rows, $offset, 2)];
        if ($resource === 'tasks') {
            $body['cursor'] = ['next' => $next];
        } elseif ($resource === 'transcriptions') {
            $body['meta'] = ['next_cursor' => $next];
        } else {
            $body['next_cursor'] = $next;
        }
        echo json_encode($body);
    }
    exit;
}
if ($path === '/nested-state' || (is_string($path) && str_starts_with($path, '/projects'))) {
    $statePath = __DIR__.'/nested-state.json';
    $initial = ['id' => 17, 'project_role' => 'member', 'assignee_id' => null, 'model_ids' => ['openai:gpt-4o-mini']];
    $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
    $state = is_array($state) ? $state : ['member' => $initial, 'reply' => 'message'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($path === '/nested-state') {
        if ($method === 'PUT') {
            $input = json_decode((string) file_get_contents('php://input'), true);
            $state = ['member' => $initial, 'reply' => is_array($input) ? ($input['reply'] ?? 'message') : 'message'];
            file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        }
        echo json_encode($state);
        exit;
    }
    if ($path === '/projects/2/members/17') {
        if ($method === 'GET') {
            http_response_code(405);
            header('Allow: PUT, DELETE');
            echo json_encode(['message' => 'The GET method is not supported for this route.']);
            exit;
        }
        if ($method === 'PUT') {
            $input = json_decode((string) file_get_contents('php://input'), true);
            $errors = [];
            if (! is_array($input) || ! in_array($input['project_role'] ?? null, ['admin', 'member', 'contributor'], true)) {
                $errors['project_role'] = ['The selected role is invalid.'];
            }
            if (is_array($input) && ($input['assignee_id'] ?? null) !== null && ! is_int($input['assignee_id'])) {
                $errors['assignee_id'] = ['The assignee must be an integer.'];
            }
            if (! is_array($input) || ! is_array($input['model_ids'] ?? null) || array_filter($input['model_ids'], static fn (mixed $value): bool => ! is_string($value)) !== []) {
                $errors['model_ids'] = ['Model IDs must be a flat list of strings.'];
            }
            if ($errors !== []) {
                http_response_code(422);
                echo json_encode(['message' => 'Invalid member.', 'errors' => $errors]);
                exit;
            }
            $state['member'] = [...$initial, ...$input];
            file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            if ($state['reply'] === 'empty') {
                http_response_code(204);
                exit;
            }
            if ($state['reply'] === 'dirty') {
                echo 'Development diagnostic before JSON'."\n";
            }
            echo json_encode(['message' => 'Member updated.']);
            exit;
        }
    }
    if ($path === '/projects/2/members') {
        echo json_encode(['data' => [$state['member']]]);
        exit;
    }
    if ($path === '/projects' || $path === '/projects/2') {
        $project = ['id' => 2, 'title' => 'Nested members project'];
        echo json_encode(['data' => $path === '/projects' ? [$project] : $project]);
        exit;
    }
    http_response_code(404);
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
