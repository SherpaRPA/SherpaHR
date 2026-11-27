// Component state tests: real AppComponent methods, fake HTTP and Angular signals.
// Angular template compilation is checked separately by npm run build.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const source = fs.readFileSync(require.resolve('../src/app.ts'), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: {
  module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, experimentalDecorators: true,
} }).outputText;
const project = { id: 1, name: 'Project', archived: false, members: [], task_count: 1 };
const task = { id: 2, project_id: 1, title: 'Review me', status: 'blocked', comments: [], due_at: null, assignee_id: null, version: 1 };
const attention = { server_now: '2026-11-01T10:00:00Z', tasks: [task] };
const deferred = () => { let resolve, reject; const promise = new Promise((a, b) => { resolve = a; reject = b; }); return { promise, resolve, reject }; };
const settle = () => new Promise(resolve => setImmediate(resolve));

async function setup() {
  const calls = [];
  const renders = [];
  let focusCount = 0;
  const api = { userId: 1, request: async (path, method = 'GET', data) => {
    calls.push({ path, method, data });
    if (path === '/context') return { user: { id: api.userId }, users: [] };
    if (path === '/projects') return [project];
    if (path === '/attention') return attention;
    if (path === '/projects/1/tasks') return [task];
    if (path === '/tasks/2') return { ...task };
    throw new Error(`Unexpected request: ${path}`);
  } };
  const signal = value => {
    const read = () => value;
    read.set = next => { value = next; };
    read.update = fn => { value = fn(value); };
    return read;
  };
  const modules = {
    '@angular/core': {
      Component: () => type => type, inject: () => api, signal,
      viewChild: () => () => ({ nativeElement: { focus: () => { ++focusCount; } } }),
      afterNextRender: spec => { renders.push(spec.write); },
    },
    '@angular/common': {}, '@angular/forms': {}, './api': {},
  };
  const exports = {};
  vm.runInNewContext(compiled, { exports, Error, require: name => {
    if (!(name in modules)) throw new Error(`Unexpected module: ${name}`);
    return modules[name];
  } });
  const app = new exports.AppComponent();
  await settle();
  return { app, api, calls, renders, focusCount: () => focusCount };
}

test('initial view loads attention and opening a result loads its project then detail', async () => {
  const { app, calls } = await setup();
  assert.equal(app.overview(), true);
  assert.equal(app.attention(), attention);
  await app.openAttentionTask(task);
  assert.equal(app.overview(), false);
  assert.equal(app.project().id, 1);
  assert.equal(app.detail().id, 2);
  const paths = calls.map(c => c.path);
  assert.ok(paths.indexOf('/projects/1/tasks') < paths.indexOf('/tasks/2'));
  await app.selectAttention();
  assert.equal(app.detail(), null);
  assert.equal(app.project(), null);
});

test('archived and foreign overview results cannot open a detail', async () => {
  const { app, calls } = await setup();
  const before = calls.length;
  await app.openAttentionTask({ ...task, project_id: 99 });
  app.projects.set([{ ...project, archived: true }]);
  await app.openAttentionTask(task);
  assert.equal(calls.length, before);
});

test('failed overview clears stale results and allows retry', async () => {
  const { app, api } = await setup();
  api.request = async () => { throw new Error('Offline'); };
  await app.refreshAttention();
  assert.equal(app.attention(), null);
  assert.equal(app.attentionError(), 'Offline');
  assert.equal(app.attentionLoading(), false);
  api.request = async () => ({ ...attention, tasks: [] });
  await app.refreshAttention();
  assert.equal(app.attention().tasks.length, 0);
  assert.equal(app.attentionError(), '');
});

test('late overview response cannot replace data after user switch', async () => {
  const { app, api } = await setup();
  const old = deferred();
  const normal = api.request;
  api.request = path => path === '/attention' && api.userId === 1 ? old.promise : normal(path);
  const pending = app.refreshAttention();
  app.switchUser('3');
  assert.equal(app.attention(), null);
  await settle();
  const current = app.attention();
  old.resolve({ server_now: 'old', tasks: [{ id: 99 }] });
  await pending;
  assert.equal(app.attention(), current);
  assert.equal(app.overview(), true);
});

test('abandoned project navigation cannot open a late task detail', async () => {
  const { app, api } = await setup();
  const loadProject = deferred();
  const normal = api.request;
  api.request = path => path === '/projects/1/tasks' ? loadProject.promise : normal(path);
  const pending = app.openAttentionTask(task);
  await app.selectAttention();
  loadProject.resolve([task]);
  await pending;
  assert.equal(app.overview(), true);
  assert.equal(app.detail(), null);
});

test('saving a task refreshes overview without discarding its comment draft', async () => {
  const { app, api, calls } = await setup();
  await app.openAttentionTask(task);
  app.comment = 'Keep this unsent comment';
  app.draft.status = 'done';
  const normal = api.request;
  api.request = (path, method, data) => {
    if (path === '/tasks/2' && method === 'PATCH') return Promise.resolve({ ...task, ...data, version: 2 });
    return normal(path, method, data);
  };
  const before = calls.filter(c => c.path === '/attention').length;
  await app.saveTask();
  await settle();
  assert.equal(app.comment, 'Keep this unsent comment');
  assert.equal(app.detail().status, 'done');
  assert.equal(calls.filter(c => c.path === '/attention').length, before + 1);
});

test('save finishing after return to overview refreshes the current snapshot', async () => {
  const { app, api } = await setup();
  await app.openAttentionTask(task);
  const saved = deferred();
  const normal = api.request;
  let finished = false;
  api.request = (path, method, data) => {
    if (method === 'PATCH') return saved.promise;
    if (path === '/attention') return Promise.resolve({ ...attention, tasks: finished ? [] : [task] });
    return normal(path, method, data);
  };
  const pending = app.saveTask();
  await app.selectAttention();
  assert.equal(app.attention().tasks.length, 1);
  finished = true;
  saved.resolve({ ...task, status: 'done', version: 2 });
  await pending;
  await settle();
  assert.equal(app.attention().tasks.length, 0);
  assert.equal(app.overview(), true);
});

test('old user save response cannot refresh or replace the new user overview', async () => {
  const { app, api, calls } = await setup();
  await app.openAttentionTask(task);
  const saved = deferred();
  const normal = api.request;
  api.request = (path, method, data) => method === 'PATCH' ? saved.promise : normal(path, method, data);
  const pending = app.saveTask();
  app.switchUser('3');
  await settle();
  const current = app.attention();
  const count = calls.length;
  saved.resolve({ ...task, status: 'done', version: 2 });
  await pending;
  await settle();
  assert.equal(app.attention(), current);
  assert.equal(calls.length, count);
  assert.equal(app.detail(), null);
});

test('opening from overview focuses detail only after render', async () => {
  const { app, renders, focusCount } = await setup();
  await app.openAttentionTask(task);
  assert.equal(focusCount(), 0);
  assert.equal(renders.length, 1);
  renders.shift()();
  assert.equal(focusCount(), 1);
});

test('cancelled detail render does not move focus after user switch', async () => {
  const { app, renders, focusCount } = await setup();
  await app.openAttentionTask(task);
  app.switchUser('3');
  await settle();
  for (const render of renders) render();
  assert.equal(focusCount(), 0);
});

test('old detail response cannot schedule focus on the same task opened by a new user', async () => {
  const { app, api, renders } = await setup();
  const old = deferred();
  const normal = api.request;
  let firstDetail = true;
  api.request = (path, method, data) => {
    if (path === '/tasks/2' && firstDetail) {
      firstDetail = false;
      return old.promise;
    }
    return normal(path, method, data);
  };
  const pending = app.openAttentionTask(task);
  await settle();
  app.switchUser('3');
  await settle();
  await app.openAttentionTask(task);
  assert.equal(renders.length, 1);
  old.resolve({ ...task });
  await pending;
  assert.equal(renders.length, 1);
});
