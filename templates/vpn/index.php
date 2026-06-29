<?php
/** @var bool $configured @var string $monitorUrl @var array $status @var array $history */
use App\Service\VpnMonitorService;

$badge = static fn(string $tone): string => 'badge badge--' . $tone;

$fmtDur = static function ($secs): string {
    $secs = max(0, (int) $secs);
    $d = intdiv($secs, 86400); $secs -= $d * 86400;
    $h = intdiv($secs, 3600);  $secs -= $h * 3600;
    $m = intdiv($secs, 60);
    return ($d ? "{$d}d " : '') . (($h || $d) ? "{$h}h " : '') . "{$m}m";
};

// Friendly labels + render order for the signal cards.
$labels = [
    'service'         => 'systemd service',
    'tunnel'          => 'Tunnel interface',
    'db_route'        => 'DB route path',
    'db_reachability' => 'DB reachability',
    'portal'          => 'Portal liveness',
    'logs'            => 'Recent logs',
];
$order = ['service', 'tunnel', 'db_route', 'db_reachability', 'portal', 'logs'];

// Per-signal key facts to show under the detail line.
$facts = static function (string $key, array $d) use ($fmtDur): array {
    $rows = [];
    $add = static function (&$rows, string $k, $v): void {
        if ($v !== null && $v !== '' && $v !== []) { $rows[] = [$k, is_array($v) ? implode(', ', $v) : $v]; }
    };
    switch ($key) {
        case 'service':
            $add($rows, 'State', trim(($d['active_state'] ?? '?') . ' / ' . ($d['sub_state'] ?? '?')));
            $add($rows, 'Uptime', $d['uptime'] ?? null);
            $add($rows, 'Restarts', $d['n_restarts'] ?? null);
            $add($rows, 'PID', $d['main_pid'] ?? null);
            break;
        case 'tunnel':
            $add($rows, 'Present', isset($d['present']) ? ($d['present'] ? 'yes' : 'no') : null);
            $add($rows, 'Operstate', $d['operstate'] ?? null);
            $add($rows, 'Address', $d['addresses'] ?? null);
            break;
        case 'db_route':
            $add($rows, 'Destination', $d['dest'] ?? null);
            $add($rows, 'Egress dev', $d['dev'] ?? null);
            $add($rows, 'Gateway', $d['gateway'] ?? null);
            break;
        case 'db_reachability':
        case 'portal':
            $add($rows, 'Target', isset($d['host']) ? ($d['host'] . (isset($d['port']) ? ':' . $d['port'] : '')) : null);
            $add($rows, 'Probe', $d['result'] ?? null);
            break;
    }
    return $rows;
};
?>
<div class="page-head">
  <div>
    <h1>VPN status</h1>
    <p>Live read-only view of the PowerSchool VPN tunnel from <code>pseast-vpn-monitor</code> — service, tunnel, and the route to the database behind it.</p>
  </div>
</div>

<?php if (!$configured): ?>
  <div class="notice notice--warn" style="margin-bottom:14px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#9A6A12" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><path d="M9 1.5L17 15.5H1L9 1.5z" stroke-linejoin="round"/><path d="M9 7v3.5" stroke-linecap="round"/><circle cx="9" cy="12.7" r=".6" fill="#9A6A12" stroke="none"/></svg>
    <div>The VPN monitor isn't configured. Set <code>VPN_MONITOR_URL</code> in <code>.env</code> (e.g. <code>http://127.0.0.1:8787</code>) to the address of the <code>pseast-vpn-monitor</code> service.</div>
  </div>
<?php elseif (!$status['ok']): ?>
  <div class="notice notice--warn" style="margin-bottom:14px;">
    <svg width="17" height="17" viewBox="0 0 18 18" fill="none" stroke="#9A6A12" stroke-width="1.7" style="flex:0 0 17px; margin-top:1px;"><path d="M9 1.5L17 15.5H1L9 1.5z" stroke-linejoin="round"/><path d="M9 7v3.5" stroke-linecap="round"/><circle cx="9" cy="12.7" r=".6" fill="#9A6A12" stroke="none"/></svg>
    <div><strong>Can't reach the VPN monitor.</strong> <?= e($status['error']) ?> <span class="muted">(<?= e($monitorUrl) ?>)</span> — check that the <code>pseast-vpn-monitor</code> service is running.</div>
  </div>
<?php else:
    $snap = $status['data'];
    $overall = (string) ($snap['overall'] ?? 'unknown');
    $signals = $snap['signals'] ?? [];
    $hist = ($history['ok'] && !empty($history['data']['enabled'])) ? ($history['data']['summary'] ?? []) : null;
?>
  <div class="card card--pad" style="margin-bottom:16px; display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
    <div>
      <div class="kv__label">Overall</div>
      <div style="margin-top:4px;"><span class="<?= e($badge(VpnMonitorService::tone($overall))) ?>"><?= e(strtoupper($overall)) ?></span></div>
    </div>
    <div>
      <div class="kv__label">Last checked</div>
      <div class="mono" style="font-size:13px;"><?= e(str_replace('T', ' ', (string) ($snap['generated_local'] ?? ''))) ?> <span class="muted"><?= e($snap['timezone'] ?? '') ?></span></div>
    </div>
    <?php if (!empty($snap['mock'])): ?><div><span class="badge badge--disabled">MOCK DATA</span></div><?php endif; ?>
    <?php if ($hist): ?>
      <div><div class="kv__label">Uptime (window)</div><div class="mono" style="font-size:13px;"><?= $hist['uptime_pct'] === null ? '—' : e($hist['uptime_pct']) . '%' ?></div></div>
      <div><div class="kv__label">Current for</div><div class="mono" style="font-size:13px;"><?= e($fmtDur($hist['current_for_seconds'] ?? 0)) ?></div></div>
      <div><div class="kv__label">Flaps</div><div class="mono" style="font-size:13px;"><?= e($hist['flaps'] ?? 0) ?></div></div>
    <?php endif; ?>
    <div style="flex:1;"></div>
    <div class="muted" style="font-size:11.5px;">Auto-refreshes every 30s · read-only</div>
  </div>

  <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px;">
    <?php
    $keys = array_merge(array_filter($order, static fn($k) => isset($signals[$k])),
                        array_keys(array_diff_key($signals, array_flip($order))));
    foreach ($keys as $key):
        $sig = $signals[$key];
        $tone = VpnMonitorService::tone((string) ($sig['status'] ?? 'unknown'));
    ?>
    <div class="card card--pad" style="border-left:4px solid var(--line, #243445);">
      <div style="display:flex; align-items:center; gap:8px;">
        <strong style="font-size:13.5px; flex:1;"><?= e($labels[$key] ?? $key) ?></strong>
        <span class="<?= e($badge($tone)) ?>"><?= e($sig['status'] ?? 'unknown') ?></span>
      </div>
      <div class="muted" style="font-size:12.5px; margin-top:3px;"><?= e($sig['detail'] ?? '') ?></div>

      <?php if ($key === 'logs'): $entries = $sig['data']['entries'] ?? []; ?>
        <?php if ($entries): ?>
        <div class="mono" style="margin-top:10px; max-height:200px; overflow:auto; font-size:11.5px; border-top:1px solid #EDF1F3;">
          <?php foreach (array_reverse($entries) as $en): ?>
          <div style="display:grid; grid-template-columns:118px 52px 1fr; gap:8px; padding:3px 0; border-bottom:1px solid #F4F7F9;">
            <span class="muted"><?= e(str_replace('T', ' ', (string) ($en['time_local'] ?? ''))) ?></span>
            <span style="text-transform:uppercase; font-size:10px; font-weight:700;"><?= e($en['level'] ?? '') ?></span>
            <span><?= e($en['message'] ?? '') ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php else: $rows = $facts($key, $sig['data'] ?? []); ?>
        <?php if ($rows): ?>
        <dl style="margin:10px 0 0; display:grid; grid-template-columns:auto 1fr; gap:2px 12px; font-size:12px;">
          <?php foreach ($rows as [$k, $v]): ?>
            <dt class="muted"><?= e($k) ?></dt><dd class="mono" style="margin:0; word-break:break-all;"><?= e($v) ?></dd>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <p class="muted" style="font-size:11.5px; margin-top:14px;">Data from the read-only <code>pseast-vpn-monitor</code>. This page only displays status; it never starts, stops, or reconfigures the tunnel.</p>

  <script>
    // Whole-page refresh so the server re-fetches the monitor snapshot.
    setTimeout(function () { location.reload(); }, 30000);
  </script>
<?php endif; ?>
