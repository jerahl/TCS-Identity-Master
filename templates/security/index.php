<?php
/**
 * @var array $snapshot @var string $sudoersFile @var string $host
 * Snapshot shape: ['enabled'=>bool, 'cards'=>[...], 'jails'=>[...], 'bannedIps'=>[...]]
 * Each card: ['key','label','state','detail','facts'=>[[k,v],...]]
 */
$badgeFor = ['ok' => 'active', 'warn' => 'pending', 'down' => 'terminated', 'disabled' => 'disabled', 'unknown' => 'disabled'];
$borderFor = ['ok' => '#34D399', 'warn' => '#F5B301', 'down' => '#E5484D', 'disabled' => '#CBD5E1', 'unknown' => '#CBD5E1'];
$tone = static fn(string $s): string => $badgeFor[$s] ?? 'disabled';

$cards = $snapshot['cards'] ?? [];
$jails = $snapshot['jails'] ?? [];
$banned = $snapshot['bannedIps'] ?? [];
$source = $snapshot['source'] ?? 'off';
?>
<div class="page-head">
  <div>
    <h1>Security</h1>
    <p>Read-only view of this host's security posture — the runtime state of the controls set by <code>scripts/harden-debian12.sh</code>. This page never changes anything.</p>
  </div>
</div>

<?php if (empty($snapshot['enabled'])): ?>
  <div class="notice notice--info" style="margin-bottom:14px;">
    <div>Host security probes are off. Set <code>SECURITY_STATUS_ENABLED=true</code> to show firewall, fail2ban, and SSH status here. On a hardened host, install the root collector timer (<code>deploy/idm-security-snapshot.timer</code>) and point <code>SECURITY_STATUS_FILE</code> at its output; the app reads that file with no elevated access. The app-level HTTP hardening below is always shown.</div>
  </div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; margin-bottom:24px;">
  <?php foreach ($cards as $svc): ?>
  <div class="card card--pad" style="border-left:4px solid <?= e($borderFor[$svc['state']] ?? '#CBD5E1') ?>;">
    <div style="display:flex; align-items:center; gap:8px;">
      <strong style="font-size:13.5px; flex:1;"><?= e($svc['label']) ?></strong>
      <span class="badge badge--<?= e($tone($svc['state'])) ?>"><?= e(strtoupper($svc['state'])) ?></span>
    </div>
    <div class="muted" style="font-size:12.5px; margin-top:4px;"><?= e($svc['detail']) ?></div>
    <?php if (!empty($svc['facts'])): ?>
    <dl style="margin:10px 0 0; display:grid; grid-template-columns:auto 1fr; gap:2px 12px; font-size:12px;">
      <?php foreach ($svc['facts'] as [$k, $v]): ?>
        <dt class="muted"><?= e($k) ?></dt><dd class="mono" style="margin:0; word-break:break-all;"><?= e($v) ?></dd>
      <?php endforeach; ?>
    </dl>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($snapshot['enabled'])): ?>
<!-- fail2ban jails -->
<div class="panel" style="margin-bottom:16px;">
  <h2 class="panel__title" style="margin-bottom:4px;">fail2ban jails</h2>
  <p class="panel__note" style="margin-bottom:14px;">Per-jail ban activity from <span class="mono">fail2ban-client status &lt;jail&gt;</span>.</p>
  <?php if ($jails === []): ?>
    <p class="muted" style="font-size:12.5px;">No jails reported (fail2ban may be disabled or unreachable — see the card above).</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Jail</th><th>Currently banned</th><th>Currently failed</th><th>Total banned</th></tr></thead>
      <tbody>
        <?php foreach ($jails as $j): ?>
        <tr>
          <td class="mono"><?= e((string) $j['name']) ?></td>
          <td><?php if ((int) $j['banned'] > 0): ?><span class="badge badge--terminated"><?= e((int) $j['banned']) ?></span><?php else: ?>0<?php endif; ?></td>
          <td><?= e((int) $j['failed']) ?></td>
          <td class="muted"><?= e((int) $j['total_banned']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Banned IPs -->
<div class="panel">
  <h2 class="panel__title" style="margin-bottom:4px;">Currently banned IPs</h2>
  <p class="panel__note" style="margin-bottom:14px;">Live ban list across all jails. Bans expire automatically (fail2ban <span class="mono">bantime</span>).</p>
  <?php if ($banned === []): ?>
    <p class="muted" style="font-size:12.5px;">No IPs are currently banned. 🎉</p>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>IP address</th><th>Jail</th></tr></thead>
      <tbody>
        <?php foreach ($banned as $b): ?>
        <tr>
          <td class="mono"><?= e((string) $b['ip']) ?></td>
          <td><?= e((string) $b['jail']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="font-size:11.5px; margin-top:10px;"><?= e(count($banned)) ?> banned IP<?= count($banned) === 1 ? '' : 's' ?>. To unban, use the host: <span class="mono">sudo fail2ban-client set &lt;jail&gt; unbanip &lt;ip&gt;</span>.</p>
  <?php endif; ?>
</div>
<?php endif; ?>

<p class="muted" style="font-size:11.5px; margin-top:14px;">
  <?php if ($source === 'file'): ?>
    Host signals come from a JSON snapshot written out-of-band by the root <span class="mono">idm-security-snapshot</span> timer; the web app only reads it (no elevated access).
  <?php elseif ($source === 'live'): ?>
    Host signals are read live with a small, fixed allow-list of read-only commands run via <span class="mono">sudo -n</span> (no shell).
  <?php endif; ?>
  This page starts, stops, and changes nothing. Configure the controls themselves with <span class="mono">scripts/harden-debian12.sh</span>.
</p>
