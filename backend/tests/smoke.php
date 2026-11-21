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
// Inject a fixed clock only in this disposable server, not through an HTTP parameter.
$router = $directory . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
require 'backend/src/Database.php';
require 'backend/src/Application.php';
(new Application(Database::connect(), fn() => getenv('SEED_NOW')))->run();
PHP);
$process = proc_open([PHP_BINARY, '-S', $address, $router],
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
    [$status, $response] = request('GET', '/api/attention');
    $attention = $response['data'];
    check($status === 200 && array_column($attention, 'id') === [5, 1, 2, 7], 'Anna sees only active unfinished signals in priority order');
    check(array_column($attention, 'reasons', 'id') === [5 => ['blocked', 'overdue'], 1 => ['blocked'], 2 => ['overdue'], 7 => ['unassigned']], 'combined and isolated signals');
    check(request('GET', '/api/attention', null, 2) === [200, ['data' => []]], 'Boris is a member but leads no projects');
    [$status, $response] = request('GET', '/api/attention', null, 3);
    check($status === 200 && array_column($response['data'], 'id') === [11], 'Vera sees only her own project signals');
    $db = new PDO('sqlite:' . $environment['DB_PATH']);
    $db->exec('DELETE FROM project_members WHERE project_id=2 AND user_id=1');
    check(array_column(request('GET', '/api/attention')[1]['data'], 'id') === [1, 2], 'ownership without membership does not grant attention access');
    $db->exec('INSERT INTO project_members VALUES (2,1)');
    $db = null;
    [$status, $boundary] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Deadline boundary', 'assignee_id' => 2, 'due_at' => $environment['SEED_NOW'],
    ]);
    $boundaryId = $boundary['data']['id'];
    check($status === 201 && array_column(request('GET', '/api/attention')[1]['data'], 'id') === [5, 1, 2, 7], 'equal deadline and assigned task without deadline are not overdue');
    request('PATCH', "/api/tasks/$boundaryId", ['due_at' => '2026-11-11T07:59:59Z', 'version' => 1]);
    check(array_column(request('GET', '/api/attention')[1]['data'], 'id') === [5, 1, 2, $boundaryId, 7], 'one second before clock is overdue');
    request('PATCH', "/api/tasks/$boundaryId", ['status' => 'blocked', 'due_at' => '2026-11-12T08:00:00Z', 'version' => 2]);
    check(array_column(request('GET', '/api/attention')[1]['data'], 'id') === [5, 1, $boundaryId, 2, 7], 'equal deadlines break ties by ID');
    request('PATCH', "/api/tasks/$boundaryId", ['due_at' => null, 'version' => 3]);
    check(array_column(request('GET', '/api/attention')[1]['data'], 'id') === [5, 1, $boundaryId, 2, 7], 'undated tasks follow dated tasks in their group');
    $rows = array_column(request('GET', '/api/attention')[1]['data'], null, 'id');
    check(array_key_exists('latest_comment', $rows[$boundaryId]) && $rows[$boundaryId]['latest_comment'] === null,
        'latest comment is null for a task without comments');
    [$status, $response] = request('POST', '/api/tasks/7/comments', ['body' => str_repeat('я', 161)], 2);
    $row = array_column(request('GET', '/api/attention')[1]['data'], null, 'id')[7];
    check($status === 201 && $row['latest_comment'] === [
        'author' => 'Борис · разработчик',
        'created_at' => $response['data']['created_at'],
        'excerpt' => str_repeat('я', 160) . '…',
    ], 'latest comment updated with creation time, author and Unicode excerpt');
    check(!array_key_exists('created_at', $row), 'comment creation time is not exposed at the attention row root');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    [$status, $response] = request('GET', '/api/projects/2/tasks');
    $projectTasks = array_column($response['data'], null, 'id');
    check($status === 200 && isset($projectTasks[7]) && $projectTasks[7]['assignee_id'] === null, 'project lists the seeded unassigned task');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
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
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    $comment = $response['data'];
    check($status === 201 && $comment['author'] === 'Борис · разработчик' && $comment['user_id'] === 2, 'comment records acting user');
    check((bool) preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $comment['created_at']), 'comment timestamp is UTC');
    $limit = str_repeat('я', 2500);
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => $limit . 'x'])[0] === 422, '5001 UTF-8 bytes rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => $limit]);
    check($status === 201 && $response['data']['body'] === $limit, '5000 UTF-8 bytes accepted');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($status === 200 && $response['data']['status'] === 'done' && count($response['data']['comments']) === 2, 'changes persist across requests');
    check($response['data']['version'] === 2, 'comments do not change task version');
    check($response['data']['comments'][0] === $comment, 'saved comment matches discussion response');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    $projectTasks = array_column($response['data'], null, 'id');
    check(isset($projectTasks[$task['id']]) && $projectTasks[$task['id']]['assignee_id'] === null, 'task remains listed after unassignment');
    check(array_keys($projectTasks) === [1, 2, 3, 4, 12, $boundaryId, $task['id']], 'initialization does not duplicate or reset data');
    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
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
