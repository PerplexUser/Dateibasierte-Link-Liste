<?php
/**
 * linklist.php — Dateibasierte Link-Liste mit Kategorien und Adminbereich
 *
 * www.perplex.click
 *
 * Anforderungen:
 * - Speichert Daten in Textdateien (chmod 777 empfohlen für DEMO-Umgebungen)
 * - Einfache Einbindung in bestehende Webseiten (include/require + render-Funktionen)
 * - Minimaler Adminbereich (Passwort, CSRF-Token)
 *
 * HINWEIS (Sicherheit):
 * - 777 ist unsicher. Nutzen Sie es nur in Testumgebungen. In Produktion: korrekte Owner/Gruppenrechte setzen.
 * - Datei-basierte Lösungen sind nicht transaktional und haben Limits bei hoher Last.
 */

// =========================
// Konfiguration
// =========================
const DATA_DIR = __DIR__ . '/data_links';      // Verzeichnis für Textdateien
const ADMIN_PASSWORD = 'changeme';             // Admin-Passwort HIER ändern
const SITE_TITLE = 'Link-Liste';               // Titel im Admin

// Dateinamen
const FILE_CATEGORIES = 'categories.txt';      // Jede Zeile: id\tname
// Links-Dateien pro Kategorie: links_{catId}.txt — JSON pro Zeile

// =========================
// Bootstrap
// =========================
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0777, true);
}
@touch(DATA_DIR . '/' . FILE_CATEGORIES);
@chmod(DATA_DIR, 0777);
@chmod(DATA_DIR . '/' . FILE_CATEGORIES, 0777);

session_start();
header_remove('X-Powered-By');

// =========================
// Helper
// =========================
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function slugify($s) {
    $s = strtolower(trim($s));
    $s = preg_replace('~[^a-z0-9]+~', '-', $s);
    return trim($s, '-');
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}
function check_csrf() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
        if (!$ok) {
            http_response_code(400);
            exit('Ungültiges CSRF-Token.');
        }
    }
}
function is_admin() { return ($_SESSION['is_admin'] ?? false) === true; }
function require_admin() {
    if (!is_admin()) { header('Location: ?admin=1'); exit; }
}

function cat_file($catId) { return DATA_DIR . '/links_' . $catId . '.txt'; }

// =========================
// Datenzugriff — Kategorien
// =========================
function read_categories() {
    $path = DATA_DIR . '/' . FILE_CATEGORIES;
    $list = [];
    $fh = @fopen($path, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            [$id, $name] = array_pad(explode("\t", $line, 2), 2, '');
            if ($id !== '' && $name !== '') {
                $list[$id] = $name;
            }
        }
        fclose($fh);
    }
    return $list; // [id => name]
}

function write_categories($cats) {
    $path = DATA_DIR . '/' . FILE_CATEGORIES;
    $fh = fopen($path, 'c+');
    if (!$fh) throw new RuntimeException('Konnte Kategorien-Datei nicht öffnen');
    if (!flock($fh, LOCK_EX)) throw new RuntimeException('Konnte Kategorien-Datei nicht sperren');
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($cats as $id => $name) {
        fwrite($fh, $id . "\t" . $name . "\n");
    }
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0777);
}

function add_category($name) {
    $name = trim($name);
    if ($name === '') return false;
    $cats = read_categories();
    $id = slugify($name);
    $orig = $id; $i = 2;
    while (isset($cats[$id]) || file_exists(cat_file($id))) {
        $id = $orig . '-' . $i++;
    }
    $cats[$id] = $name;
    write_categories($cats);
    // Links-Datei vorbereiten
    $lf = cat_file($id);
    @touch($lf); @chmod($lf, 0777);
    return $id;
}

function rename_category($id, $newName) {
    $cats = read_categories();
    if (!isset($cats[$id])) return false;
    $cats[$id] = trim($newName) ?: $cats[$id];
    write_categories($cats);
    return true;
}

function delete_category($id) {
    $cats = read_categories();
    if (!isset($cats[$id])) return false;
    unset($cats[$id]);
    write_categories($cats);
    // Links-Datei löschen (optional: archivieren)
    $lf = cat_file($id);
    if (file_exists($lf)) @unlink($lf);
    return true;
}

// =========================
// Datenzugriff — Links (JSON Lines)
// =========================
// Link-Datensatz:
// {
//   "id": "uuid",
//   "title": "...",
//   "url": "https://...",
//   "desc": "...",
//   "created": 1736371200,
//   "updated": 1736371200,
//   "pin": false
// }

function read_links($catId) {
    $path = cat_file($catId);
    $items = [];
    if (!file_exists($path)) return $items;
    $fh = fopen($path, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (is_array($row) && !empty($row['id'])) $items[$row['id']] = $row;
        }
        fclose($fh);
    }
    return $items; // [id => row]
}

function write_links($catId, $items) {
    $path = cat_file($catId);
    $fh = fopen($path, 'c+');
    if (!$fh) throw new RuntimeException('Konnte Links-Datei nicht öffnen');
    if (!flock($fh, LOCK_EX)) throw new RuntimeException('Konnte Links-Datei nicht sperren');
    ftruncate($fh, 0);
    rewind($fh);
    foreach ($items as $row) {
        fwrite($fh, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n");
    }
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod($path, 0777);
}

function uuid() { return bin2hex(random_bytes(8)); }

function add_link($catId, $title, $url, $desc = '', $pin = false) {
    $items = read_links($catId);
    $id = uuid();
    $now = time();
    $items[$id] = [
        'id' => $id,
        'title' => trim($title),
        'url' => trim($url),
        'desc' => trim($desc),
        'created' => $now,
        'updated' => $now,
        'pin' => (bool)$pin,
    ];
    write_links($catId, $items);
}

function update_link($catId, $id, $title, $url, $desc = '', $pin = false) {
    $items = read_links($catId);
    if (!isset($items[$id])) return false;
    $items[$id]['title'] = trim($title);
    $items[$id]['url'] = trim($url);
    $items[$id]['desc'] = trim($desc);
    $items[$id]['pin'] = (bool)$pin;
    $items[$id]['updated'] = time();
    write_links($catId, $items);
    return true;
}

function delete_link($catId, $id) {
    $items = read_links($catId);
    if (!isset($items[$id])) return false;
    unset($items[$id]);
    write_links($catId, $items);
    return true;
}

// =========================
// Public Rendering (für Einbindung)
// =========================
function render_linklist($opts = []) {
    // $opts: [ 'category' => catId|null, 'show_desc' => bool, 'limit' => int|null, 'order' => 'new'|'alpha'|'pin' ]
    $cats = read_categories();
    $category = $opts['category'] ?? null; // wenn null: alle Kategorien
    $showDesc = !empty($opts['show_desc']);
    $limit = isset($opts['limit']) ? (int)$opts['limit'] : null;
    $order = $opts['order'] ?? 'pin';

    $html = "<div class=\"llist\">";

    $catIds = $category ? (isset($cats[$category]) ? [$category] : []) : array_keys($cats);

    foreach ($catIds as $cid) {
        $links = array_values(read_links($cid));
        // Sortierung
        usort($links, function($a, $b) use ($order) {
            if ($order === 'alpha') return strcasecmp($a['title'], $b['title']);
            if ($order === 'new') return ($b['updated'] <=> $a['updated']);
            // 'pin' zuerst, dann updated
            $pa = $a['pin'] ? 1 : 0; $pb = $b['pin'] ? 1 : 0;
            if ($pa !== $pb) return $pb <=> $pa;
            return ($b['updated'] <=> $a['updated']);
        });
        if ($limit) $links = array_slice($links, 0, $limit);

        $html .= "<section class=\"llist-category\">";
        $html .= "<h3>" . h($cats[$cid] ?? $cid) . "</h3>";
        $html .= "<ul class=\"llist-items\">";
        foreach ($links as $L) {
            $html .= "<li class=\"llist-item\">";
            $html .= "<a href=\"" . h($L['url']) . "\" target=\"_blank\" rel=\"noopener noreferrer\">" . h($L['title']) . "</a>";
            if ($showDesc && ($L['desc'] ?? '') !== '') {
                $html .= "<div class=\"llist-desc\">" . nl2br(h($L['desc'])) . "</div>";
            }
            if (!empty($L['pin'])) {
                $html .= " <span class=\"llist-pin\" title=\"angeheftet\">📌</span>";
            }
            $html .= "</li>";
        }
        $html .= "</ul></section>";
    }

    $html .= "</div>\n";

    // Minimal-Styles (überschreibbar)
    $html .= "<style>.llist{font:14px/1.5 system-ui,Segoe UI,Roboto,Arial,sans-serif}.llist-category{margin:1rem 0;padding:0.5rem 0;border-top:1px solid #ddd}.llist-category:first-child{border-top:0}.llist-items{list-style:none;margin:0;padding:0}.llist-item{margin:0.25rem 0}.llist-item a{text-decoration:none}.llist-desc{color:#555;margin:0.15rem 0 0 0.5rem}.llist-pin{font-size:0.9em;margin-left:0.25rem}</style>";

    return $html;
}

// =========================
// Einfache API-Ausgabe (JSON/HTML) — z.B. für iframe/Fetch
// =========================
if (isset($_GET['api'])) {
    $cats = read_categories();
    $cat = $_GET['category'] ?? null;
    $format = $_GET['format'] ?? 'html';

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        $out = [];
        $ids = $cat ? (isset($cats[$cat]) ? [$cat] : []) : array_keys($cats);
        foreach ($ids as $cid) {
            $out[] = [
                'category' => ['id' => $cid, 'name' => $cats[$cid] ?? $cid],
                'links' => array_values(read_links($cid))
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    // HTML
    header('Content-Type: text/html; charset=utf-8');
    echo render_linklist([
        'category' => $cat ?: null,
        'show_desc' => !empty($_GET['show_desc']),
        'order' => $_GET['order'] ?? 'pin',
        'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : null,
    ]);
    exit;
}

// =========================
// Adminbereich
// =========================
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ?admin=1'); exit; }

if (isset($_GET['admin'])) {
    // Login
    if (!is_admin()) {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            check_csrf();
            $pw = $_POST['password'] ?? '';
            if (hash_equals(ADMIN_PASSWORD, $pw)) {
                $_SESSION['is_admin'] = true;
                header('Location: ?admin=1');
                exit;
            } else {
                $err = 'Passwort falsch.';
            }
        }
        echo '<!doctype html><meta charset="utf-8"><title>'.h(SITE_TITLE).' – Admin Login</title>';
        echo '<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:680px;margin:2rem auto;padding:0 1rem}form{display:grid;gap:.5rem}input[type=password]{padding:.5rem}button{padding:.5rem 1rem}</style>';
        echo '<h1>'.h(SITE_TITLE).' – Admin Login</h1>';
        if (!empty($err)) echo '<p style="color:#b00">'.h($err).'</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
        echo '<label>Passwort<br><input type="password" name="password" autocomplete="current-password"></label>';
        echo '<button type="submit">Anmelden</button></form>';
        exit;
    }

    // Aktionen
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        check_csrf();
        $action = $_POST['action'] ?? '';
        switch ($action) {
            case 'cat_add':
                $name = $_POST['name'] ?? '';
                add_category($name);
                break;
            case 'cat_rename':
                rename_category($_POST['id'] ?? '', $_POST['name'] ?? '');
                break;
            case 'cat_delete':
                delete_category($_POST['id'] ?? '');
                break;
            case 'link_add':
                add_link($_POST['cat'] ?? '', $_POST['title'] ?? '', $_POST['url'] ?? '', $_POST['desc'] ?? '', !empty($_POST['pin']));
                break;
            case 'link_update':
                update_link($_POST['cat'] ?? '', $_POST['id'] ?? '', $_POST['title'] ?? '', $_POST['url'] ?? '', $_POST['desc'] ?? '', !empty($_POST['pin']));
                break;
            case 'link_delete':
                delete_link($_POST['cat'] ?? '', $_POST['id'] ?? '');
                break;
        }
        header('Location: ?admin=1');
        exit;
    }

    // View
    $cats = read_categories();
    echo '<!doctype html><meta charset="utf-8"><title>'.h(SITE_TITLE).' – Admin</title>';
    echo '<style>body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem}header{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}.grid{display:grid;grid-template-columns:1fr 2fr;gap:1rem}section{border:1px solid #ddd;padding:1rem;border-radius:.5rem}h2{margin:.2rem 0 .8rem}.row{display:flex;gap:.5rem;flex-wrap:wrap;margin:.25rem 0}.row input[type=text], .row input[type=url], .row textarea{flex:1;min-width:180px;padding:.45rem}.row textarea{height:4rem}.list{margin:.5rem 0}.list-item{padding:.4rem;border:1px solid #eee;border-radius:.4rem;margin:.25rem 0}.pin{opacity:.8}</style>';
    echo '<header><h1>'.h(SITE_TITLE).' – Admin</h1><nav><a href="?logout=1">Logout</a></nav></header>';

    echo '<div class="grid">';

    // Kategorien verwalten
    echo '<section><h2>Kategorien</h2>';
    echo '<form method="post" class="row"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="cat_add">';
    echo '<input type="text" name="name" placeholder="Neue Kategorie"> <button>Hinzufügen</button></form>';

    echo '<div class="list">';
    foreach ($cats as $id => $name) {
        echo '<div class="list-item">';
        echo '<strong>'.h($name).'</strong> <code>'.h($id).'</code>';
        echo '<form method="post" class="row" style="margin-top:.3rem"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="id" value="'.h($id).'">';
        echo '<input type="hidden" name="action" value="cat_rename">';
        echo '<input type="text" name="name" value="'.h($name).'"> <button>Umbenennen</button></form>';
        echo '<form method="post" class="row"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="id" value="'.h($id).'">';
        echo '<input type="hidden" name="action" value="cat_delete">';
        echo '<button onclick="return confirm(\'Wirklich löschen?\')">Löschen</button></form>';
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';

    // Links verwalten
    echo '<section><h2>Links</h2>';
    if (empty($cats)) {
        echo '<p>Legen Sie zuerst eine Kategorie an.</p>';
    } else {
        foreach ($cats as $cid => $cname) {
            echo '<h3>'.h($cname).' <small style="font-weight:normal">('.h($cid).')</small></h3>';
            // Formular: Link hinzufügen
            echo '<form method="post" class="row"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="link_add">';
            echo '<input type="hidden" name="cat" value="'.h($cid).'">';
            echo '<input type="text" name="title" placeholder="Titel">';
            echo '<input type="url" name="url" placeholder="https://...">';
            echo '<textarea name="desc" placeholder="Beschreibung (optional)"></textarea>';
            echo '<label class="pin"><input type="checkbox" name="pin"> anheften</label>'; 
            echo '<button>Speichern</button></form>';

            $items = array_values(read_links($cid));
            if (empty($items)) {
                echo '<p><em>Noch keine Links.</em></p>';
            } else {
                // Sortierung im Admin: zuletzt geändert zuerst
                usort($items, fn($a,$b)=>($b['updated']<=>$a['updated']));
                foreach ($items as $L) {
                    echo '<div class="list-item">';
                    echo '<div><a href="'.h($L['url']).'" target="_blank">'.h($L['title']).'</a>' . (!empty($L['pin']) ? ' 📌' : '') . '</div>';
                    if (!empty($L['desc'])) echo '<div style="color:#555">'.nl2br(h($L['desc'])).'</div>';
                    echo '<small>Zuletzt geändert: '.date('Y-m-d H:i', $L['updated']).'</small>';
                    // Edit-Form
                    echo '<form method="post" class="row" style="margin-top:.3rem"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="link_update">';
                    echo '<input type="hidden" name="cat" value="'.h($cid).'">';
                    echo '<input type="hidden" name="id" value="'.h($L['id']).'">';
                    echo '<input type="text" name="title" value="'.h($L['title']).'">';
                    echo '<input type="url" name="url" value="'.h($L['url']).'">';
                    echo '<textarea name="desc">'.h($L['desc']).'</textarea>';
                    echo '<label class="pin"><input type="checkbox" name="pin" '.(!empty($L['pin'])?'checked':'').'> anheften</label>';
                    echo '<button>Aktualisieren</button></form>';
                    // Delete
                    echo '<form method="post" class="row"><input type="hidden" name="csrf" value="'.h(csrf_token()).'"><input type="hidden" name="action" value="link_delete">';
                    echo '<input type="hidden" name="cat" value="'.h($cid).'">';
                    echo '<input type="hidden" name="id" value="'.h($L['id']).'">';
                    echo '<button onclick="return confirm(\'Diesen Link löschen?\')">Löschen</button></form>';
                    echo '</div>';
                }
            }
        }
    }
    echo '</section>';

    echo '</div>';
    exit;
}

// =========================
// Public Rendering – Standardseite (Demo) ODER Einbindung via include
// =========================
if (php_sapi_name() !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    // Wird direkt im Browser aufgerufen → Demo-Ausgabe der Liste
    echo '<!doctype html><meta charset="utf-8"><title>'.h(SITE_TITLE).'</title>';
    echo '<div style="max-width:800px;margin:2rem auto;padding:0 1rem;font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif">';
    echo '<header style="display:flex;justify-content:space-between;align-items:center"><h1>'.h(SITE_TITLE).'</h1><nav><a href="?admin=1">Admin</a></nav></header>';
    echo render_linklist(['show_desc' => true, 'order' => 'pin']);
    echo '</div>';
}

// =========================
// Einbindung in bestehende Seiten:
//   include 'linklist.php';
//   echo render_linklist([
//       'category' => null,      // oder z.B. 'news', um nur eine Kategorie zu zeigen
//       'show_desc' => true,
//       'limit' => 20,
//       'order' => 'pin',        // 'pin' | 'new' | 'alpha'
//   ]);
//
// Alternative via API/iframe:
//   <iframe src="/pfad/linklist.php?api=1&format=html&category=news&show_desc=1" width="100%" height="500"></iframe>
//   JSON: /pfad/linklist.php?api=1&format=json
// =========================
?>
