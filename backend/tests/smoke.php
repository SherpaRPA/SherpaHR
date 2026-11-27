<?php
declare(strict_types=1);

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4, 12], 'project list includes all five original tasks');
    [$status, $response] = request('GET', '/api/projects/2/tasks');
    check($status === 200 && array_column($response['data'], 'id') === [5, 6, 7] && $response['data'][2]['assignee_id'] === null, 'another project includes its unassigned task only');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', '{}')[0] === 422, 'missing comment body rejected');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => 123])[0] === 422, 'non-string comment rejected');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => str_repeat('x', 5001)])[0] === 422, 'comment above byte limit rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    $comment = $response['data'];
    check(is_int($comment['id']) && $comment['id'] > 0 && $comment['task_id'] === $task['id'] && $comment['user_id'] === 2, 'comment response identifies comment, task and user');
    $created = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $comment['created_at'], new DateTimeZone('UTC'));
    check($created !== false && $created->format('Y-m-d\TH:i:s\Z') === $comment['created_at'], 'comment timestamp is UTC');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    check($response['data']['comments'][0] === $comment && $response['data']['version'] === 2, 'saved comment matches response without changing task version');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '  Спасибо за уточнение  ']);
    check($status === 201 && $response['data']['body'] === 'Спасибо за уточнение', 'comment trims surrounding whitespace');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => str_repeat('я', 2500)]);
    check($status === 201 && strlen($response['data']['body']) === 5000, 'comment at UTF-8 byte limit accepted');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check(count($response['data']['comments']) === 3 && $response['data']['comments'][0]['id'] < $response['data']['comments'][1]['id'] && $response['data']['comments'][1]['id'] < $response['data']['comments'][2]['id'], 'comments persist in insertion order');
    check(request('POST', '/api/tasks/9999/comments', ['body' => 'Missing'])[0] === 404, 'missing task cannot receive comments');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');
    check(array_column($response['data'], 'id') === [1, 2, 3, 4, 12, $task['id']], 'project list keeps original tasks and newly unassigned task exactly once');
    $listedTask = $response['data'][5];
    check($listedTask['assignee_id'] === null && $listedTask['due_at'] === null && $listedTask['status'] === 'done' && $listedTask['version'] === 2, 'project list retains edits after removing assignee');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    // Attention fixtures use past/future dates independent of the demo seed date.
    $create = function (int $project, array $fields, int $user = 1): array {
        [$status, $response] = request('POST', '/api/projects/' . $project . '/tasks', $fields, $user);
        if ($status !== 201) throw new RuntimeException('Cannot create attention fixture');
        return $response['data'];
    };
    $blocked = $create(1, ['title' => 'Blocked and overdue', 'status' => 'blocked', 'due_at' => '2000-01-01T00:00:00Z']);
    $noDue = $create(2, ['title' => 'Blocked without deadline', 'status' => 'blocked']);
    $futureBlocked = $create(1, ['title' => 'Blocked before deadline', 'status' => 'blocked', 'due_at' => '2999-01-01T00:00:00Z']);
    $overdue = $create(3, ['title' => 'Overdue without assignee', 'due_at' => '2000-01-02T00:00:00Z']);
    $future = $create(1, ['title' => 'Not due yet', 'due_at' => '2999-01-01T00:00:00Z']);
    $done = $create(1, ['title' => 'Finished before review', 'status' => 'done', 'due_at' => '2000-01-01T00:00:00Z']);
    $noSignal = $create(1, ['title' => 'Open without deadline']);
    $hidden = $create(5, ['title' => 'Private blocker', 'status' => 'blocked'], 3);
    $before = gmdate('Y-m-d\TH:i:s\Z');
    [$status, $response] = request('GET', '/api/attention');
    $after = gmdate('Y-m-d\TH:i:s\Z');
    $overview = $response['data'];
    check($status === 200 && $before <= $overview['server_now'] && $overview['server_now'] <= $after, 'attention uses current server time rather than seed time');
    $byId = array_column($overview['tasks'], null, 'id');
    check(isset($byId[$blocked['id']], $byId[$noDue['id']], $byId[$futureBlocked['id']], $byId[$overdue['id']]), 'attention includes blocked and overdue tasks across active projects');
    check($byId[$blocked['id']]['blocked'] && $byId[$blocked['id']]['overdue'], 'combined reasons share a single task');
    check($byId[$noDue['id']]['blocked'] && !$byId[$noDue['id']]['overdue'], 'blocked task without deadline is included');
    check($byId[$futureBlocked['id']]['blocked'] && !$byId[$futureBlocked['id']]['overdue'], 'future blocker is not labelled overdue');
    check(!$byId[$overdue['id']]['blocked'] && $byId[$overdue['id']]['overdue'] && $byId[$overdue['id']]['assignee_id'] === null, 'unassigned overdue task is included');
    check(!isset($byId[$future['id']]) && !isset($byId[$noSignal['id']]), 'future and undated open tasks do not require attention');
    check(!isset($byId[$done['id']]) && !isset($byId[3]) && !isset($byId[9]), 'completed tasks are excluded even when overdue');
    check(!isset($byId[10]), 'archived project blocker is excluded');
    check(!isset($byId[11]) && !isset($byId[$hidden['id']]), 'foreign project tasks are excluded for Anna');
    check(count($byId) === count($overview['tasks']), 'attention contains no duplicate tasks');
    $order = array_column($overview['tasks'], 'id');
    check(array_search($blocked['id'], $order, true) < array_search($overdue['id'], $order, true) && array_search($futureBlocked['id'], $order, true) < array_search($noDue['id'], $order, true), 'attention puts blockers first and undated blockers last');
    check($byId[$overdue['id']]['project_name'] === 'Обновление базы знаний', 'attention provides project name for navigation');
    [$status, $response] = request('GET', '/api/tasks/' . $overdue['id']);
    check($status === 200 && $response['data']['id'] === $overdue['id'] && $response['data']['comments'] === [], 'attention task opens through existing detail endpoint');
    [$status, $response] = request('GET', '/api/attention', null, 2);
    $boris = array_column($response['data']['tasks'], 'id');
    check($status === 200 && in_array($noDue['id'], $boris, true) && !in_array($overdue['id'], $boris, true) && !in_array($hidden['id'], $boris, true), 'Boris attention respects his project membership');
    [$status, $response] = request('GET', '/api/attention', null, 3);
    $vera = array_column($response['data']['tasks'], 'id');
    check($status === 200 && in_array($hidden['id'], $vera, true) && !in_array($noDue['id'], $vera, true), 'Vera attention includes her private project only for her');
    check(request('GET', '/api/attention', null, 999)[0] === 401, 'attention rejects unknown demo user');
    [$status] = request('PATCH', '/api/tasks/' . $overdue['id'], ['status' => 'done', 'version' => 1]);
    check($status === 200, 'attention fixture can be completed');
    [$status, $response] = request('GET', '/api/attention');
    check($status === 200 && !in_array($overdue['id'], array_column($response['data']['tasks'], 'id'), true), 'completed task disappears from refreshed attention');
    foreach ($response['data']['tasks'] as $remaining) {
        [$status] = request('PATCH', '/api/tasks/' . $remaining['id'], ['status' => 'done', 'version' => $remaining['version']]);
        if ($status !== 200) throw new RuntimeException('Cannot complete attention fixture');
    }
    [$status, $response] = request('GET', '/api/attention');
    check($status === 200 && $response['data']['tasks'] === [], 'attention returns an empty list when nothing requires attention');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
