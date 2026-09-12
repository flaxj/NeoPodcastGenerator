CREATE TABLE settings (
 id INTEGER PRIMARY KEY CHECK(id=1), title TEXT NOT NULL, description TEXT NOT NULL,
 base_url TEXT NOT NULL, owner_name TEXT NOT NULL, owner_email TEXT NOT NULL,
 language TEXT NOT NULL DEFAULT 'en', explicit INTEGER NOT NULL DEFAULT 0,
 category TEXT NOT NULL DEFAULT 'Technology', artwork TEXT, updated_at INTEGER NOT NULL
);
CREATE TABLE admins(id INTEGER PRIMARY KEY CHECK(id=1), username TEXT NOT NULL, password TEXT NOT NULL);
CREATE TABLE episodes (
 id TEXT PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
 state TEXT NOT NULL DEFAULT 'draft' CHECK(state IN ('draft','published')),
 published_at INTEGER, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
 explicit INTEGER NOT NULL DEFAULT 0, season INTEGER, number INTEGER,
 episode_type TEXT NOT NULL DEFAULT 'full' CHECK(episode_type IN ('full','trailer','bonus')),
 audio_id TEXT, video_id TEXT
);
CREATE TABLE assets (
 id TEXT PRIMARY KEY, episode_id TEXT NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
 kind TEXT NOT NULL, filename TEXT NOT NULL UNIQUE, mime TEXT NOT NULL,
 bytes INTEGER NOT NULL, duration REAL NOT NULL
);
CREATE TABLE uploads (
 id TEXT PRIMARY KEY, episode_id TEXT NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
 extension TEXT NOT NULL, total INTEGER NOT NULL, offset INTEGER NOT NULL DEFAULT 0,
 created_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'uploading'
);
CREATE TABLE jobs (
 id TEXT PRIMARY KEY, episode_id TEXT NOT NULL REFERENCES episodes(id) ON DELETE CASCADE,
 upload_id TEXT NOT NULL REFERENCES uploads(id) ON DELETE CASCADE,
 state TEXT NOT NULL DEFAULT 'queued', error TEXT, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX one_active_job ON jobs(episode_id) WHERE state IN ('queued','processing');
CREATE TABLE health(id INTEGER PRIMARY KEY CHECK(id=1), heartbeat INTEGER NOT NULL);
CREATE TABLE login_attempts(key TEXT PRIMARY KEY, count INTEGER NOT NULL, started INTEGER NOT NULL);
