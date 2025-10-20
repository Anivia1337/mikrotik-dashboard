<?php


declare(strict_types=1);

// ===================== CONFIG ===================== //
$ROUTER = [
    'host'          => '0.0.0.0',
    'port'          => 22,
    'user'          => 'api_read',  // User with read rights
    'pass'          => 'changeMe',
    'timeout'       => 6,

    // Exakte Anzeige-Reihenfolge (wenn gesetzt, wird nur das gezeigt):
    'if_exact'      => ['ether1','ether2','ether3','ether4','ether5'],

    // Fallback, falls if_exact leer: Substring-Match
    'if_name_match' => ['ether','sfp'],
];

const CACHE_TTL  = 0;   // Sekunden
const CACHE_FILE = '/tmp/router_cache.json';
const REFRESH_MS = 1000;

// ===================== SSH helpers ===================== //
$_SSH = null;
function ssh_connect(array $cfg) {
    $c = @ssh2_connect($cfg['host'], (int)$cfg['port']);
    if (!$c) throw new RuntimeException('SSH connect failed');
    if (!@ssh2_auth_password($c, $cfg['user'], $cfg['pass'])) {
        throw new RuntimeException('SSH auth failed');
    }
    return $c;
}
function ros_exec(string $cmd): string {
    global $_SSH, $ROUTER;
    if (!$_SSH) $_SSH = ssh_connect($ROUTER);
    // RouterOS-CLI bekommt den Befehl direkt, keine Shell -> kein escapeshellarg()
    $s = @ssh2_exec($_SSH, $cmd . "\n");
    if (!$s) throw new RuntimeException('SSH exec failed: '.$cmd);
    stream_set_blocking($s, true);
    $out = stream_get_contents($s);
    fclose($s);
    return trim((string)$out);
}



// ===================== Parsing helpers ===================== //
function parse_kv_lines(string $raw): array {
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
    $rows = [];
    $current = [];
    foreach ($lines as $ln) {
        if ($ln === '') { if ($current) { $rows[] = $current; $current = []; } continue; }

        if (str_contains($ln, '=')) {           // terse: one line with pairs
            $kv = [];
            foreach (preg_split('/\s+/', $ln) as $pair) {
                if ($pair === '' || !str_contains($pair, '=')) continue;
                [$k,$v] = explode('=', $pair, 2);
                $kv[$k] = $v;
            }
            if ($kv) $rows[] = $kv;
            continue;
        }

        if (str_contains($ln, ':')) {           // as-value/plain: key: value
            [$k,$v] = array_map('trim', explode(':', $ln, 2));
            if ($k !== '') $current[$k] = $v;
            continue;
        }
    }
    if ($current) $rows[] = $current;
    return $rows;
}
function ros_print_any(string $path): array {
    $a = parse_kv_lines(ros_exec($path.' terse'));                 if ($a) return $a;
    $b = parse_kv_lines(ros_exec($path.' as-value'));              if ($b) return $b;
    $c = parse_kv_lines(ros_exec($path));                          return $c;
}

function ros_uptime_cpu(): array {
    $rows = ros_print_any('/system/resource/print');
    $r = $rows[0] ?? [];
    $cpuRaw = $r['cpu-load'] ?? ($r['cpu_load'] ?? null);
    if (is_string($cpuRaw)) $cpuRaw = (int)preg_replace('/[^0-9]/', '', $cpuRaw);
    return [
        'uptime'   => $r['uptime'] ?? '',
        'cpu_load' => is_numeric($cpuRaw) ? (int)$cpuRaw : null,
        'version'  => $r['version'] ?? null,
        'board'    => $r['board-name'] ?? ($r['board'] ?? null),
    ];
}

function ros_interfaces_filtered(array $match): array {
    global $ROUTER;
    $rows = ros_print_any('/interface/print');
    $present = [];
    foreach ($rows as $r) {
        $n = $r['name'] ?? '';
        if ($n !== '') $present[$n] = true;
    }
    if (!empty($ROUTER['if_exact']) && is_array($ROUTER['if_exact'])) {
        $want = [];
        foreach ($ROUTER['if_exact'] as $n) if (isset($present[$n])) $want[] = $n;
        return $want;
    }
    $want = [];
    foreach ($rows as $r) {
        $n = $r['name'] ?? '';
        if ($n === '') continue;
        foreach ($match as $m) { if (stripos($n, $m) !== false) { $want[] = $n; break; } }
    }
    return $want;
}

// Helper: "43.3kbps" -> 43300 bps, "10.4mbps" -> 10400000 bps, "123bps" -> 123
function parse_rate_to_bps(string $s): int {
    $s = trim(strtolower($s));
    if ($s === '' || $s === '0' || $s === '0bps') return 0;
    if (str_ends_with($s, 'kbps')) { $n = (float)substr($s, 0, -4); return (int)round($n*1000); }
    if (str_ends_with($s, 'mbps')) { $n = (float)substr($s, 0, -4); return (int)round($n*1000*1000); }
    if (str_ends_with($s, 'gbps')) { $n = (float)substr($s, 0, -4); return (int)round($n*1000*1000*1000); }
    if (str_ends_with($s, 'bps'))  { $n = (float)substr($s, 0, -3); return (int)round($n); }
    $n = (float)preg_replace('/[^0-9.]/','', $s);
    return (int)round($n);
}
function ros_monitor_ethernet_bulk(array $ifnames): array {
    // numbers erwartet z. B. "ether1,ether2,ether3"
    $list = implode(',', $ifnames);
    $raw  = ros_exec('/interface/ethernet/monitor numbers='.$list.' once');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
    $out = []; $current = null;

    foreach ($lines as $ln) {
        if ($ln === '') { $current = null; continue; }
        if (str_starts_with($ln, 'name:')) {
            $name = trim(substr($ln, strlen('name:')));
            $current = $name;
            if (!isset($out[$name])) $out[$name] = ['rx_bps'=>0,'tx_bps'=>0];
            continue;
        }
        if ($current && str_contains($ln, ':')) {
            [$k,$v] = array_map('trim', explode(':', $ln, 2));
            if ($k === 'rx-rate') $out[$current]['rx_bps'] = parse_rate_to_bps($v);
            if ($k === 'tx-rate') $out[$current]['tx_bps'] = parse_rate_to_bps($v);
        }
    }
    return $out; // map: name => ['rx_bps'=>..,'tx_bps'=>..]
}


function ros_monitor_once(string $ifname): array {
    $raw = ros_exec('/interface/monitor-traffic interface='.$ifname.' once');
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
    $kv = [];
    foreach ($lines as $ln) {
        if ($ln === '') continue;
        if (str_contains($ln, '=')) {
            foreach (preg_split('/\s+/', $ln) as $pair) {
                if (!str_contains($pair, '=')) continue;
                [$k,$v] = explode('=', $pair, 2); $kv[$k] = $v;
            }
        } elseif (str_contains($ln, ':')) {
            [$k,$v] = array_map('trim', explode(':', $ln, 2)); $kv[$k] = $v;
        }
    }
    $rx = $kv['rx-bits-per-second'] ?? ($kv['fp-rx-bits-per-second'] ?? '0');
    $tx = $kv['tx-bits-per-second'] ?? ($kv['fp-tx-bits-per-second'] ?? '0');
    return ['rx_bps'=>parse_rate_to_bps((string)$rx), 'tx_bps'=>parse_rate_to_bps((string)$tx)];
}




function format_bps(int $bps): string {
    $u = ['bps','Kbps','Mbps','Gbps']; $v = (float)$bps; $i=0;
    while ($v >= 1000 && $i < 3) { $v/=1000; $i++; }
    $p = $v < 10 ? 2 : ($v < 100 ? 1 : 0);
    return number_format($v, $p).' '.$u[$i];
}

// ===================== Cache ===================== //
function cache_get(): ?array {
    if (!is_file(CACHE_FILE)) return null;
    if (time() - (int)@filemtime(CACHE_FILE) > CACHE_TTL) return null;
    $j = @file_get_contents(CACHE_FILE); if (!$j) return null;
    $d = json_decode($j, true); return is_array($d) ? $d : null;
}
function cache_set(array $d): void { @file_put_contents(CACHE_FILE, json_encode($d, JSON_UNESCAPED_SLASHES)); }

// ===================== Controller ===================== //
$wantJson = isset($_GET['json']) && $_GET['json'] == '1';
try {
    $data = cache_get();
    if (!$data) {
        $meta = ros_uptime_cpu();
        $ifnames = ros_interfaces_filtered($ROUTER['if_name_match']);
        $ifs = [];
        foreach ($ifnames as $n) {
            $m = ros_monitor_once($n);
            $ifs[] = ['name'=>$n, 'rx_bps'=>$m['rx_bps'], 'tx_bps'=>$m['tx_bps']];
        }
        $data = ['ok'=>true,'ts'=>time(),'meta'=>$meta,'interfaces'=>$ifs];
        cache_set($data);
    }
    if ($wantJson) { header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_SLASHES); exit; }
} catch (Throwable $e) {
    if ($wantJson) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
    $data = ['ok'=>false,'error'=>$e->getMessage(),'interfaces'=>[], 'meta'=>[], 'ts'=>time()];
}

$uptime = htmlspecialchars($data['meta']['uptime'] ?? '');
$cpu    = (int)($data['meta']['cpu_load'] ?? 0);
$board  = htmlspecialchars($data['meta']['board'] ?? '');
$vers   = htmlspecialchars($data['meta']['version'] ?? '');
$ts     = (int)($data['ts'] ?? time());
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Router Status</title>
<style>
  :root { color-scheme: dark; }
  body { margin: 0; font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, "Helvetica Neue", Arial, "Apple Color Emoji", "Segoe UI Emoji"; background: #0b0f14; color: #e6f0ff; }
  .wrap { max-width: 980px; margin: 24px auto; padding: 0 16px; }
  .card { background: #0f1620; border: 1px solid #1f2a38; border-radius: 16px; padding: 16px 18px; box-shadow: 0 10px 30px rgba(0,0,0,.25); }
  .grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); }
  .muted { color: #9db3d1; }
  .pill { display:inline-block; padding: 4px 10px; border-radius: 999px; background:#101b28; border:1px solid #223047; font-size:12px; color:#a8c0e8; }
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 10px 8px; border-bottom: 1px solid #1f2a38; text-align:left; }
  th { font-weight: 600; color:#bcd2f4; }
  .rx { color: #8ad1ff; }
  .tx { color: #b7ffa8; }
  .footer { font-size: 12px; color: #90a7c7; margin-top: 16px; }
  .bar { height: 8px; border-radius: 6px; background: #142033; position: relative; overflow:hidden; }
  .bar > i { position:absolute; top:0; left:0; bottom:0; background: linear-gradient(90deg,#1fb6ff,#00e0a3); width:0%; }
</style>
</head>
<body>
<!-- Füge diesen Block direkt in <body> als ersten Knoten ein -->
<div class="bg-anim" aria-hidden="true">
  <div class="layer base"></div>
  <div class="blob b1"></div>
  <div class="blob b2"></div>
  <div class="blob b3"></div>
  <div class="grain"></div>
</div>

<style>
  :root{
    /*  Farben anpassen */
    --c1: #0e1b2b;   /* dunkler Grundton */
    --c2: #10233b;   /* leicht heller */
    --a1: #3db6ff;   /* Akzent 1 (zyan) */
    --a2: #7ae0bf;   /* Akzent 2 (mint) */
    --a3: #8aa8ff;   /* Akzent 3 (eisblau) */

    /* ⚙️ Verhalten anpassen */
    --blob-size: 38vmax;       /* Größe der Blobs */
    --blur: 60px;              /* Weichzeichnung */
    --speed-1: 28s;            /* Animationszeiten */
    --speed-2: 34s;
    --speed-3: 40s;
    --grain-opacity: .08;      /* Körnung */
  }

  /* Vollflächige Bühne */
  .bg-anim{
    position: fixed; inset: 0; z-index: -1; overflow: hidden;
    background:
      radial-gradient(1200px 800px at 10% 10%, #0a1220 0%, transparent 60%),
      radial-gradient(1600px 1000px at 120% -20%, #0a1422 0%, transparent 60%),
      linear-gradient(180deg, var(--c2), var(--c1));
  }

  /* dezente Basis-Glow-Schicht (nicht bewegend) */
  .bg-anim .layer.base{
    position:absolute; inset:-10%;
    background:
      radial-gradient(60% 60% at 20% 30%, #17406655 0%, transparent 60%),
      radial-gradient(50% 50% at 80% 60%, #0b3e4d44 0%, transparent 60%);
    filter: blur(30px) saturate(120%);
  }

  /* die farbigen „Blobs“ */
  .bg-anim .blob{
    position:absolute; width: var(--blob-size); height: var(--blob-size);
    border-radius: 50%;
    filter: blur(var(--blur)) contrast(120%) saturate(120%);
    mix-blend-mode: screen; opacity:.9;
    will-change: transform;
  }
  .bg-anim .b1{ background: radial-gradient(circle at 40% 40%, var(--a1), transparent 60%); }
  .bg-anim .b2{ background: radial-gradient(circle at 40% 40%, var(--a2), transparent 60%); }
  .bg-anim .b3{ background: radial-gradient(circle at 40% 40%, var(--a3), transparent 60%); }

  /* Keyframes: langsame organische Bewegung */
  @keyframes float1{
    0%   { transform: translate(-10%, -10%) scale(1); }
    33%  { transform: translate(60%, -5%)  scale(1.08); }
    66%  { transform: translate(10%, 40%)  scale(0.96); }
    100% { transform: translate(-10%, -10%) scale(1); }
  }
  @keyframes float2{
    0%   { transform: translate(50%, 10%)  scale(1.05); }
    33%  { transform: translate(-20%, 50%) scale(0.95); }
    66%  { transform: translate(30%, -20%) scale(1.1); }
    100% { transform: translate(50%, 10%)  scale(1.05); }
  }
  @keyframes float3{
    0%   { transform: translate(10%, 60%)  scale(1); }
    33%  { transform: translate(-5%, -10%) scale(1.12); }
    66%  { transform: translate(60%, 20%)  scale(0.92); }
    100% { transform: translate(10%, 60%)  scale(1); }
  }

  .bg-anim .b1{ left:-10%; top:-10%; animation: float1 var(--speed-1) linear infinite; }
  .bg-anim .b2{ right:-15%; top:10%; animation:  float2 var(--speed-2) linear infinite; }
  .bg-anim .b3{ left:10%; bottom:-20%; animation: float3 var(--speed-3) linear infinite; }

  /* feine Körnung (Noise) als Overlay */
  .bg-anim .grain{
    position:absolute; inset:-50%;
    background-image: url("data:image/svg+xml;utf8,\
<svg xmlns='http://www.w3.org/2000/svg' width='180' height='180' viewBox='0 0 180 180'>\
  <filter id='n'>\
    <feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/>\
    <feColorMatrix type='saturate' values='0'/>\
  </filter>\
  <rect width='100%' height='100%' filter='url(%23n)' opacity='0.38'/>\
</svg>");
    opacity: var(--grain-opacity);
    mix-blend-mode: overlay;
    animation: grainMove 8s steps(8) infinite;
    pointer-events:none;
  }
  @keyframes grainMove{
    0% { transform: translate3d(0,0,0); }
    100% { transform: translate3d(-5%, -5%, 0); }
  }

  /* Respektiere reduzierte Bewegung */
  @media (prefers-reduced-motion: reduce){
    .bg-anim .blob{ animation: none; }
    .bg-anim .grain{ animation: none; }
  }
</style>

  <div class="wrap">
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;justify-content:space-between">
        <div>
          <div style="font-size:20px;font-weight:700">Router Status</div>
          <div class="muted" style="font-size:13px">Board: <?= $board ?: '–' ?> · ROS: <?= $vers ?: '–' ?></div>
        </div>
        <div class="pill">Auto-Refresh: <?= (int)(REFRESH_MS/1000) ?>s</div>
      </div>
      <div class="grid" style="margin-top:14px">
        <div>
<div class="muted" style="font-size:12px">Uptime</div>
<div style="font-size:22px;font-weight:700;letter-spacing:.2px">
  <span id="uptimeText"><?= $uptime ?: '–' ?></span>
</div>

        </div>
        <div>
<div class="muted" style="font-size:12px">CPU-Last</div>
<div style="font-size:22px;font-weight:700;letter-spacing:.2px">
  <span id="cpuText"><?= $cpu ?></span> %
</div>
<div class="bar" aria-hidden="true"><i id="cpuBar" style="width: <?= max(0,min(100,$cpu)) ?>%"></i></div>

        </div>
        <div>
          <div class="muted" style="font-size:12px">Letzte Aktualisierung</div>
<div id="updatedTs" style="font-size:22px;font-weight:700;letter-spacing:.2px"><?= date('H:i:s', $ts) ?></div>        </div>
      </div>
    </div>

    <div class="card">
      <div style="font-size:16px;font-weight:700;margin-bottom:8px">Schnittstellen (aktuelle Bitraten)</div>
      <table id="iftable">
        <thead>
          <tr>
            <th style="width:36px">#</th>
            <th>Interface</th>
            <th class="rx">RX</th>
            <th class="tx">TX</th>
          </tr>
        </thead>
        <tbody>
        <?php $i=1; foreach (($data['interfaces'] ?? []) as $if): ?>
          <tr>
            <td class="muted"><?= $i++ ?></td>
            <td><code><?= htmlspecialchars($if['name']) ?></code></td>
            <td class="rx"><?= htmlspecialchars(format_bps((int)$if['rx_bps'])) ?></td>
            <td class="tx"><?= htmlspecialchars(format_bps((int)$if['tx_bps'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="footer">Nur Lesezugriff · Datenquelle: /system/resource/print & /interface/monitor-traffic once</div>
    </div>
  </div>

<script>
const fmt = bps => {
  const u = ['bps','Kbps','Mbps','Gbps'];
  let v = Number(bps)||0, i=0;
  while (v>=1000 && i<u.length-1) { v/=1000; i++; }
  const p = v<10?2:(v<100?1:0);
  return v.toFixed(p)+' '+u[i];
};
async function refresh() {
  try {
    const r = await fetch(`?json=1&t=${Date.now()}`, { cache: 'no-store' });
    const j = await r.json();
    if (!j || !j.ok) return;
if (j.meta && j.meta.uptime) {
  document.getElementById('uptimeText').textContent = j.meta.uptime;
}

    // Kopf aktualisieren
document.getElementById('updatedTs').textContent = new Date().toLocaleTimeString();
    const cpu = (j.meta && j.meta.cpu_load) ? Number(j.meta.cpu_load) : 0;
    document.getElementById('cpuText').textContent = String(cpu);
    document.querySelector('#cpuBar').style.width = Math.max(0, Math.min(100, cpu)) + '%';

    // Tabelle aktualisieren (Zeilen nach Name mappen)
    const tbody = document.querySelector('#iftable tbody');
    const rowsByName = {};
    [...tbody.querySelectorAll('tr')].forEach(tr => {
      const name = tr.children[1]?.textContent?.trim();
      if (name) rowsByName[name] = tr;
    });

    j.interfaces.forEach((it, idx) => {
      const name = it.name;
      let tr = rowsByName[name];
      if (!tr) {
        tr = document.createElement('tr');
        tr.innerHTML = `<td class="muted">${idx+1}</td><td><code>${name}</code></td><td class="rx">0.00 bps</td><td class="tx">0.00 bps</td>`;
        tbody.appendChild(tr);
      } else {
        tr.children[0].textContent = String(idx + 1); // Index aktualisieren
      }
      tr.children[2].textContent = fmt(it.rx_bps);
      tr.children[3].textContent = fmt(it.tx_bps);
    });
  } catch(e) { /* ignore */ }
  
}

setInterval(refresh, <?= (int)REFRESH_MS ?>);
</script>
</body>
</html>
