CREATE TABLE event_documents (
  event_id INTEGER NOT NULL REFERENCES events(id) ON DELETE CASCADE,
  document_id INTEGER NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
  PRIMARY KEY (event_id, document_id)
);
CREATE TABLE polls (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  opens_at TEXT NOT NULL,
  closes_at TEXT NOT NULL,
  threshold INTEGER NOT NULL DEFAULT 50,
  online INTEGER NOT NULL DEFAULT 1,
  published INTEGER NOT NULL DEFAULT 0,
  event_id INTEGER REFERENCES events(id) ON DELETE SET NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE poll_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  poll_id INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
  question TEXT NOT NULL,
  sort INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE poll_votes (
  item_id INTEGER NOT NULL REFERENCES poll_items(id) ON DELETE CASCADE,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  choice TEXT NOT NULL CHECK (choice IN ('yes','no','abstain')),
  voted_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  PRIMARY KEY (item_id, unit_id)
);
CREATE TABLE maintenance (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  contractor TEXT NOT NULL DEFAULT '',
  last_date TEXT,
  interval_months INTEGER NOT NULL DEFAULT 0,
  next_date TEXT,
  note TEXT NOT NULL DEFAULT '',
  reminded_for TEXT
);
CREATE TABLE readings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
  kind TEXT NOT NULL,
  meter_no TEXT NOT NULL DEFAULT '',
  amount REAL NOT NULL,
  read_date TEXT NOT NULL,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE resources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE reservations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  resource_id INTEGER NOT NULL REFERENCES resources(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  res_date TEXT NOT NULL,
  time_from TEXT NOT NULL,
  time_to TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE keys_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  unit_id INTEGER REFERENCES units(id) ON DELETE SET NULL,
  holder TEXT NOT NULL DEFAULT '',
  issued_at TEXT,
  returned_at TEXT,
  note TEXT NOT NULL DEFAULT ''
);
