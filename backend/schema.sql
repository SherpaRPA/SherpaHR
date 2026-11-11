CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL);
CREATE TABLE projects (
    id INTEGER PRIMARY KEY, name TEXT NOT NULL, description TEXT NOT NULL,
    owner_id INTEGER NOT NULL REFERENCES users(id), archived INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE project_members (
    project_id INTEGER NOT NULL REFERENCES projects(id),
    user_id INTEGER NOT NULL REFERENCES users(id), PRIMARY KEY (project_id, user_id)
);
CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','in_progress','blocked','done')),
    assignee_id INTEGER REFERENCES users(id), due_at TEXT,
    updated_at TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE comments (
    id INTEGER PRIMARY KEY AUTOINCREMENT, task_id INTEGER NOT NULL REFERENCES tasks(id),
    user_id INTEGER NOT NULL REFERENCES users(id), body TEXT NOT NULL, created_at TEXT NOT NULL
);
CREATE INDEX tasks_project ON tasks(project_id);
CREATE INDEX comments_task ON comments(task_id, id);
