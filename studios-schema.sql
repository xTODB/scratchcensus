-- Run this once in phpMyAdmin (SQL tab) on the ScratchCensus database.
-- Do NOT commit this file to the repo (the FTP deploy would upload it to the
-- web root). Safe to re-run: both statements are CREATE TABLE IF NOT EXISTS.

CREATE TABLE IF NOT EXISTS studios (
    id               INT UNSIGNED NOT NULL PRIMARY KEY,          -- Scratch studio id
    title            VARCHAR(255) NULL,
    host_id          INT UNSIGNED NULL,                          -- Scratch USER id of the host
    host_username    VARCHAR(64)  NULL,                          -- filled when the managers list shows it
    follower_count   INT UNSIGNED NULL,
    project_count    INT UNSIGNED NULL,
    manager_count    INT UNSIGNED NULL,
    comment_count    INT UNSIGNED NULL,
    open_to_all      TINYINT(1)   NULL,                          -- 1 = anyone can add projects
    is_public        TINYINT(1)   NULL,
    comments_allowed TINYINT(1)   NULL,
    created_on       DATETIME     NULL,                          -- studio creation time on Scratch (UTC)
    status           ENUM('pending','fetched','error') NOT NULL DEFAULT 'pending',
    retries          TINYINT UNSIGNED NOT NULL DEFAULT 0,
    priority         INT UNSIGNED NOT NULL DEFAULT 0,
    discovered_from  VARCHAR(64)  NULL,                          -- username (or 'seed') that led us here
    claim_token      VARCHAR(16)  NULL,
    claimed_at       DATETIME     NULL,
    checked_at       DATETIME     NULL,
    KEY idx_status_followers (status, follower_count),
    KEY idx_status_priority (status, priority, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS studio_people (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username          VARCHAR(64) NOT NULL,
    discovered_studio INT UNSIGNED NULL,                         -- studio whose host/curator list had this person
    status            ENUM('pending','mined','error') NOT NULL DEFAULT 'pending',
    retries           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    priority          INT UNSIGNED NOT NULL DEFAULT 0,           -- follower count of that studio
    claim_token       VARCHAR(16) NULL,
    claimed_at        DATETIME    NULL,
    mined_at          DATETIME    NULL,
    UNIQUE KEY uq_username (username),
    KEY idx_status_priority (status, priority, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
