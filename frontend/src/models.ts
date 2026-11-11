export interface User { id: number; name: string }
export interface Project { id: number; name: string; description: string; archived: boolean; owner_id: number; task_count: number; members: User[] }
export type TaskStatus = 'open' | 'in_progress' | 'blocked' | 'done';
export interface Task { id: number; project_id: number; title: string; description: string; status: TaskStatus; assignee_id: number | null; due_at: string | null; updated_at: string; version: number }
export interface Comment { id: number; task_id: number; user_id: number; body: string; created_at: string; author: string }
export interface TaskDetail extends Task { comments: Comment[] }
export interface Context { user: User; users: User[]; server_now: string; seeded_at: string }
