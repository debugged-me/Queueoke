-- Queueoke MySQL schema
-- Import this via phpMyAdmin (cPanel -> phpMyAdmin -> Import tab).
-- All tables use InnoDB + utf8mb4 so emojis (🎤🎉👏) and any language lyrics work.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------
-- rooms: one per party. The TV host creates a room; phones join it.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rooms (
  id            VARCHAR(20)  NOT NULL,            -- short room code, e.g. "AB7K2"
  host_token    VARCHAR(64)  NOT NULL,            -- secret token only the TV knows; lets host approve/skip
  title         VARCHAR(120) NOT NULL DEFAULT 'Party Room',
  is_locked     TINYINT(1)   NOT NULL DEFAULT 1,  -- 1 = guests need host approval to join
  now_playing   VARCHAR(20)  NULL,                -- YouTube video id currently playing (NULL = idle)
  started_at    DATETIME     NULL,                -- when current song started (for resume-after-reload)
  pitch         INT          NOT NULL DEFAULT 0, -- -6..+6 semitones
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME     NOT NULL,           -- when the room auto-cleans (set on create)
  PRIMARY KEY (id),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------
-- guests: each phone that joined a room.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS guests (
  id            INT          NOT NULL AUTO_INCREMENT,
  room_id       VARCHAR(20)  NOT NULL,
  nickname      VARCHAR(40)  NOT NULL,
  color         VARCHAR(7)   NOT NULL DEFAULT '#ff5a5f', -- avatar color
  status        ENUM('pending','approved','kicked') NOT NULL DEFAULT 'pending',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_room (room_id, status),
  CONSTRAINT fk_guests_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------
-- queue: ordered list of upcoming songs for a room.
-- position lets us reorder without rewriting every row.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS queue (
  id            INT          NOT NULL AUTO_INCREMENT,
  room_id       VARCHAR(20)  NOT NULL,
  video_id      VARCHAR(20)  NOT NULL,            -- YouTube video id
  title         VARCHAR(255) NOT NULL,
  thumbnail     VARCHAR(255) NULL,
  duration_sec  INT          NULL,
  guest_id      INT          NULL,                -- who added it (NULL = host/AI DJ)
  nickname      VARCHAR(40)  NOT NULL DEFAULT 'Host',
  position      DOUBLE        NOT NULL,           -- fractional for stable reorder
  votes         INT          NOT NULL DEFAULT 0,  -- crowd upvotes (democratic queue mode)
  status        ENUM('queued','playing','done','skipped') NOT NULL DEFAULT 'queued',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_room_pos (room_id, position),
  CONSTRAINT fk_queue_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------
-- reactions_log: optional audit of soundboard hits (also powers
-- "most hyped singer" stats). Keep it lightweight.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reactions_log (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  room_id       VARCHAR(20)  NOT NULL,
  guest_id      INT          NULL,
  reaction      VARCHAR(24)  NOT NULL,            -- applause, cheer, airhorn, gong, chime, confetti
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_room_time (room_id, created_at),
  CONSTRAINT fk_reactions_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------
-- songs_cache: cache YouTube search results + metadata so we don't
-- hit the YouTube Data API repeatedly for the same track.
-- -----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS songs_cache (
  video_id      VARCHAR(20)  NOT NULL,
  title         VARCHAR(255) NOT NULL,
  channel       VARCHAR(255) NULL,
  thumbnail     VARCHAR(255) NULL,
  duration_sec  INT          NULL,
  is_karaoke    TINYINT(1)   NOT NULL DEFAULT 0,  -- heuristic: title contains "karaoke"
  fetched_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (video_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
