<?php
/**
 * Base de donnees SQLite partagee par le proxy chat, les reservations,
 * le traceur de stats et le back-office.
 *
 * Le fichier vit dans api/.data/finalyn.sqlite (hors depot, voir .gitignore).
 * Aucune dependance : PDO SQLite est inclus avec PHP.
 */

function finalyn_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = __DIR__ . '/.data';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }

    $pdo = new PDO('sqlite:' . $dir . '/finalyn.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    $pdo->exec("CREATE TABLE IF NOT EXISTS conversations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conv_key TEXT UNIQUE,
        started_at TEXT NOT NULL,
        last_at TEXT NOT NULL,
        ip_hash TEXT,
        user_agent TEXT,
        msg_count INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        conversation_id INTEGER NOT NULL,
        role TEXT NOT NULL,
        content TEXT NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        firstname TEXT,
        lastname TEXT,
        email TEXT,
        company TEXT,
        slot_date TEXT NOT NULL,
        slot_time TEXT NOT NULL,
        message TEXT,
        status TEXT NOT NULL DEFAULT 'confirmed'
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pageviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        path TEXT NOT NULL,
        referrer TEXT,
        visitor_hash TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_dates (
        slot_date TEXT PRIMARY KEY,
        created_at TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS blocked_slots (
        slot_date TEXT NOT NULL,
        slot_time TEXT NOT NULL,
        created_at TEXT NOT NULL,
        PRIMARY KEY (slot_date, slot_time)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        skey TEXT PRIMARY KEY,
        sval TEXT NOT NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT UNIQUE NOT NULL,
        title TEXT NOT NULL,
        tag TEXT,
        excerpt TEXT,
        cover TEXT,
        read_min INTEGER DEFAULT 5,
        created_at TEXT NOT NULL
    )");

    // Migration : stocker le corps (markdown-lite) pour permettre l'edition
    $cols = $pdo->query("PRAGMA table_info(posts)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('body', $cols, true)) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN body TEXT");
    }
    if (!in_array('updated_at', $cols, true)) {
        $pdo->exec("ALTER TABLE posts ADD COLUMN updated_at TEXT");
    }

    // Migration : jeton d'annulation cote client pour les reservations
    $bcols = $pdo->query("PRAGMA table_info(bookings)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('token', $bcols, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN token TEXT");
    }

    return $pdo;
}

/** Hash non reversible d'une IP (pour stats sans stocker l'IP en clair). */
function finalyn_ip_hash() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? '' : substr(hash('sha256', 'finalyn|' . $ip), 0, 32);
}
