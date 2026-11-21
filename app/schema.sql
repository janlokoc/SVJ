CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE COLLATE NOCASE,
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'resident' CHECK (role IN ('admin','resident')),
  phone TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'owner' CHECK (kind IN ('owner','tenant')),
  email_notify INTEGER NOT NULL DEFAULT 1,
  active INTEGER NOT NULL DEFAULT 1,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  invite_hash TEXT,
  invite_expires TEXT,
  totp_secret TEXT,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  totp_last INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  last_login_at TEXT
);
CREATE TABLE units (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entrance TEXT NOT NULL DEFAULT '',
  number TEXT NOT NULL,
  floor TEXT NOT NULL DEFAULT '',
  share TEXT NOT NULL DEFAULT '',
  owner_note TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  UNIQUE (entrance, number)
);
CREATE TABLE user_units (
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  PRIMARY KEY (user_id, unit_id)
);
CREATE TABLE posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  visibility TEXT NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','residents')),
  pinned INTEGER NOT NULL DEFAULT 0,
  published_at TEXT NOT NULL,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL,
  visibility TEXT NOT NULL DEFAULT 'public' CHECK (visibility IN ('public','residents')),
  filename TEXT,
  original_name TEXT NOT NULL DEFAULT '',
  size INTEGER NOT NULL DEFAULT 0,
  external_url TEXT,
  doc_date TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE post_documents (
  post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  PRIMARY KEY (post_id, document_id)
);
CREATE TABLE contacts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  grp TEXT NOT NULL CHECK (grp IN ('vybor','sprava','havarie')),
  role TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  event_date TEXT NOT NULL,
  event_time TEXT NOT NULL DEFAULT '',
  place TEXT NOT NULL DEFAULT '',
  note TEXT NOT NULL DEFAULT '',
  rsvp INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE forum_posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  parent_id INTEGER REFERENCES forum_posts(id) ON DELETE CASCADE,
  title TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  last_activity_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  edited_note TEXT NOT NULL DEFAULT ''
);
CREATE TABLE tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  subject TEXT NOT NULL,
  location TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new' CHECK (status IN ('new','in_progress','done')),
  admin_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE mailings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  subject TEXT NOT NULL,
  body TEXT NOT NULL,
  audience TEXT NOT NULL CHECK (audience IN ('owners','all')),
  sent_count INTEGER NOT NULL DEFAULT 0,
  failed_count INTEGER NOT NULL DEFAULT 0,
  failed_list TEXT NOT NULL DEFAULT '',
  tracked INTEGER NOT NULL DEFAULT 0,
  sent_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sent_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE event_rsvp (
  event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL CHECK (status IN ('yes','proxy','no')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  PRIMARY KEY (event_id, unit_id)
);
CREATE TABLE mailing_recipients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  mailing_id INTEGER NOT NULL REFERENCES mailings(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  name TEXT NOT NULL,
  email TEXT NOT NULL,
  token TEXT NOT NULL UNIQUE,
  sent_ok INTEGER NOT NULL DEFAULT 0,
  opened_at TEXT,
  clicked_at TEXT
);
CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE login_attempts (ip TEXT NOT NULL, at INTEGER NOT NULL, email TEXT NOT NULL DEFAULT '');
CREATE TABLE audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  ip TEXT NOT NULL DEFAULT '',
  action TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  user_name TEXT NOT NULL DEFAULT '',
  chain TEXT
);
CREATE INDEX idx_posts_pub ON posts(published_at);
CREATE INDEX idx_docs_cat ON documents(category, doc_date);
CREATE INDEX idx_forum_parent ON forum_posts(parent_id);
