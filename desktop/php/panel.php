<?php
/* Panneau Pronote : tous les élèves sur une page — semaine complète, devoirs
 * par jour, notes par matière, vie scolaire, messagerie, cantine, abonnement
 * agenda. Rendu côté serveur depuis les commandes et les données structurées
 * de la dernière synchronisation ; le navigateur n'anime que l'heure courante,
 * les onglets et le changement de semaine.
 */
if (!isConnect()) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$pluginId = 'pronote';
sendVarToJS('eqType', $pluginId);
$isAdmin = isConnect('admin');

$students = array();
foreach (eqLogic::byType($pluginId, true) as $eq) {
    $students[] = $eq;
}

$val = function ($eq, $logicalId, $default = '') {
    $cmd = $eq->getCmd(null, $logicalId);
    if (!is_object($cmd)) {
        return $default;
    }
    $v = $cmd->execCmd();
    return ($v === null || $v === '') ? $default : $v;
};
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$jours = array('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche');
$mois = array('', 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.');
$today = date('Y-m-d');
$monday = strtotime('monday this week');
?>
<style>
  .pnp{--pn-line:rgba(128,140,155,.22);--pn-soft:rgba(128,140,155,.10);--pn-accent:var(--link-color,#1e8fd5);--pn-warn:var(--al-warning-color,#f0ad4e);--pn-bad:var(--al-danger-color,#d9534f);--pn-ok:var(--al-success-color,#5cb85c);padding:6px 4px 30px;font-variant-numeric:tabular-nums}
  .pnp *{box-sizing:border-box}
  .pnp .pnp-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:6px 0 16px}
  .pnp .pnp-tabs{display:flex;gap:8px;flex-wrap:wrap}
  .pnp .pnp-tab{display:flex;align-items:center;gap:10px;padding:7px 14px 7px 8px;border-radius:24px;border:1px solid var(--pn-line);background:var(--panel-bg-color,rgba(128,140,155,.08));cursor:pointer;transition:border-color .12s,background .12s}
  .pnp .pnp-tab.on{border-color:var(--pn-accent);background:rgba(30,143,213,.12)}
  .pnp .pnp-tab .pn-av{width:32px;height:32px;font-size:12px}
  .pnp .pnp-tab b{font-weight:500;font-size:14px;display:block;line-height:1.1}
  .pnp .pnp-tab small{opacity:.6;font-size:11px}
  .pnp .pnp-actions{margin-left:auto;display:flex;gap:8px;align-items:center}
  .pnp .pn-av{width:44px;height:44px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;color:#fff;background:hsl(var(--h,200),50%,48%);overflow:hidden}
  .pnp .pn-av img{width:100%;height:100%;object-fit:cover;display:block}
  .pnp .pnp-student{display:none}
  .pnp .pnp-student.on{display:block}

  .pnp .pnp-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:0 0 14px}
  .pnp .pnp-stat{padding:12px 14px;border-radius:var(--border-radius,6px);background:var(--panel-bg-color,rgba(128,140,155,.08));border:1px solid var(--pn-line);display:flex;flex-direction:column;gap:2px;min-height:78px}
  .pnp .pnp-stat .l{font-size:11px;letter-spacing:.06em;text-transform:uppercase;opacity:.55;font-weight:600}
  .pnp .pnp-stat .v{font-size:24px;font-weight:500;line-height:1.1}
  .pnp .pnp-stat .v small{font-size:13px;opacity:.6;font-weight:400}
  .pnp .pnp-stat .s{font-size:12px;opacity:.65}
  .pnp .pnp-bar{height:5px;border-radius:3px;background:var(--pn-soft);margin-top:6px;overflow:hidden}
  .pnp .pnp-bar i{display:block;height:100%;background:var(--pn-accent);border-radius:3px}
  .pnp .pnp-alerts{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}
  .pnp .pnp-alerts:empty{display:none}
  .pnp .pnp-chip{display:inline-flex;align-items:center;gap:7px;height:28px;padding:0 12px;border-radius:14px;font-size:12px;border:1px solid var(--pn-line);background:var(--pn-soft)}
  .pnp .pnp-chip.warn{color:var(--pn-warn);border-color:rgba(240,173,78,.4)}
  .pnp .pnp-chip.bad{color:var(--pn-bad);border-color:rgba(217,83,79,.4)}
  .pnp .pnp-chip.ok{color:var(--pn-ok);border-color:rgba(92,184,92,.4)}

  .pnp .pnp-block{border-radius:var(--border-radius,6px);background:var(--panel-bg-color,rgba(128,140,155,.08));border:1px solid var(--pn-line);padding:14px 16px;margin:0 0 14px;min-width:0}
  .pnp .pnp-block h3{margin:0 0 10px;font-size:11px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;opacity:.55;display:flex;align-items:center;gap:8px}
  .pnp .pnp-block h3 .r{margin-left:auto;display:flex;gap:6px;align-items:center;text-transform:none;letter-spacing:0;font-weight:400;opacity:1}
  .pnp .pnp-block h3 .btn{padding:1px 8px;font-size:11px}
  .pnp .pnp-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px}
  .pnp .pnp-empty{font-size:12.5px;opacity:.55;padding:8px 0}

  /* Agenda semaine : colonnes par jour, grille horaire proportionnelle */
  .pnp .pnp-week{display:grid;grid-template-columns:44px repeat(var(--cols,5),minmax(0,1fr));gap:0;position:relative;--h0:8;--h1:18;--ph:44px}
  .pnp .pnp-week .dh{font-size:12px;font-weight:500;text-align:center;padding:4px 0 8px;border-bottom:1px solid var(--pn-line)}
  .pnp .pnp-week .dh small{display:block;font-weight:400;opacity:.6;font-size:11px}
  .pnp .pnp-week .dh.today{color:var(--pn-accent)}
  .pnp .pnp-week .hours{position:relative;height:calc((var(--h1) - var(--h0)) * var(--ph))}
  .pnp .pnp-week .hours span{position:absolute;right:8px;font-size:10.5px;opacity:.5;transform:translateY(-50%)}
  .pnp .pnp-week .col{position:relative;height:calc((var(--h1) - var(--h0)) * var(--ph));border-left:1px solid var(--pn-line);background-image:linear-gradient(var(--pn-line) 1px,transparent 1px);background-size:100% var(--ph)}
  .pnp .pnp-week .col.today{background-color:rgba(30,143,213,.05)}
  .pnp .pnp-week .ev{position:absolute;left:3px;right:3px;border-radius:5px;padding:4px 6px 4px 9px;font-size:11.5px;line-height:1.25;background:color-mix(in srgb,var(--c) 20%,transparent);border-left:3px solid var(--c);overflow:hidden}
  @supports not (background:color-mix(in srgb,red 10%,blue)){.pnp .pnp-week .ev{background:var(--pn-soft)}}
  .pnp .pnp-week .ev b{display:block;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pnp .pnp-week .ev i{font-style:normal;opacity:.65;font-size:10.5px;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pnp .pnp-week .ev.cancelled{text-decoration:line-through;opacity:.55;border-left-color:var(--pn-bad)}
  .pnp .pnp-week .ev.cancelled b::after{content:" · annulé";text-decoration:none;color:var(--pn-bad);font-weight:400}
  .pnp .pnp-week .ev.past{opacity:.55}
  .pnp .pnp-week .ev.now{box-shadow:0 0 0 2px var(--pn-accent)}
  .pnp .pnp-week .ev.test::before{content:"✎ ";opacity:.7}
  .pnp .pnp-week .nowline{position:absolute;left:0;right:0;height:0;border-top:2px solid var(--pn-bad);z-index:2;pointer-events:none;display:none}
  .pnp .pnp-week .nowline::before{content:"";position:absolute;left:-4px;top:-5px;width:8px;height:8px;border-radius:50%;background:var(--pn-bad)}
  .pnp .pnp-week .hw{position:absolute;left:3px;right:3px;top:-2px;font-size:10px;color:var(--pn-warn);text-align:center}
  .pnp .pnp-weeknav{display:flex;gap:6px;align-items:center}
  .pnp .pnp-weeknav .btn{padding:2px 9px;font-size:12px}

  /* Listes : devoirs, notes, vie scolaire, messagerie, cantine */
  .pnp ul.pronote-hw,.pnp ul.pronote-grades,.pnp ul.pronote-abs,.pnp ul.pronote-msg,.pnp ul.pronote-info,.pnp ul.pronote-menu{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}
  .pnp ul.pronote-hw li,.pnp ul.pronote-abs li,.pnp ul.pronote-msg li,.pnp ul.pronote-info li,.pnp ul.pronote-menu li{display:grid;grid-template-columns:auto 1fr;gap:2px 10px;padding:7px 10px;border-radius:5px;background:var(--pn-soft);font-size:12.5px}
  .pnp ul.pronote-hw li b,.pnp ul.pronote-abs li b,.pnp ul.pronote-msg li b,.pnp ul.pronote-info li b,.pnp ul.pronote-menu li b{font-weight:500}
  .pnp ul li .d{font-size:11px;opacity:.6;text-align:right;white-space:nowrap}
  .pnp ul li .t{grid-column:1/-1;opacity:.8;line-height:1.35}
  .pnp ul li .i{grid-column:1/-1;font-size:11px;opacity:.6}
  .pnp ul.pronote-hw li.urgent{border-left:3px solid var(--pn-warn)}
  .pnp ul.pronote-hw li.done{opacity:.5;text-decoration:line-through}
  .pnp ul.pronote-abs li.nj{border-left:3px solid var(--pn-bad)}
  .pnp ul.pronote-msg li.unread,.pnp ul.pronote-info li.unread{border-left:3px solid var(--pn-accent)}
  .pnp .pnp-hwday{font-size:11.5px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.55;margin:10px 0 4px}
  .pnp .pnp-hwday:first-child{margin-top:0}
  .pnp ul.pronote-grades li{display:grid;grid-template-columns:1fr auto;gap:0 10px;padding:6px 10px 6px 12px;border-radius:5px;background:var(--pn-soft);border-left:3px solid var(--c);font-size:12.5px}
  .pnp ul.pronote-grades li .v{font-weight:600;font-size:14px}
  .pnp ul.pronote-grades li .i{grid-column:1/-1}
  .pnp .pnp-subjects{display:flex;flex-direction:column;gap:8px}
  .pnp .pnp-subj{display:grid;grid-template-columns:minmax(110px,1fr) 2fr auto;gap:10px;align-items:center;font-size:12.5px}
  .pnp .pnp-subj .n{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pnp .pnp-subj .bars{position:relative;height:16px}
  .pnp .pnp-subj .bars i{position:absolute;left:0;top:2px;height:5px;border-radius:3px;background:var(--pn-accent)}
  .pnp .pnp-subj .bars i.c{top:9px;background:rgba(128,140,155,.45)}
  .pnp .pnp-subj .m{font-weight:600;white-space:nowrap}
  .pnp .pnp-subj .m small{font-weight:400;opacity:.55;margin-left:4px}
  .pnp .pnp-subj.down .m{color:var(--pn-warn)}
  .pnp .pnp-legend{font-size:11px;opacity:.55;margin-top:8px;display:flex;gap:14px}
  .pnp .pnp-legend i{display:inline-block;width:14px;height:4px;border-radius:2px;background:var(--pn-accent);vertical-align:middle;margin-right:4px}
  .pnp .pnp-legend i.c{background:rgba(128,140,155,.45)}
  .pnp .pnp-ical{font-size:12px;opacity:.75;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .pnp .pnp-ical code{font-size:11px;padding:3px 6px;border-radius:4px;background:var(--pn-soft);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;vertical-align:middle}
  .pnp .pnp-foot{font-size:11.5px;opacity:.55;margin-top:4px}
  @media (max-width:800px){.pnp .pnp-week{--ph:36px}.pnp .pnp-week .ev i{display:none}}
</style>

<div class="pnp" id="pnp">
<?php if (!count($students)) { ?>
  <div class="pnp-block"><h3><i class="fas fa-graduation-cap"></i> {{Pronote}}</h3>
    <div class="pnp-empty">{{Aucun élève actif. Ajouter un élève dans}} <a href="index.php?v=d&m=pronote&p=pronote">{{la page du plugin}}</a>.</div></div>
<?php } else { ?>
  <div class="pnp-top">
    <div class="pnp-tabs">
      <?php foreach ($students as $k => $eq) {
          $name = trim((string)$eq->getConfiguration('student_name', '')) ?: $eq->getName();
          echo '<div class="pnp-tab' . ($k === 0 ? ' on' : '') . '" data-id="' . (int)$eq->getId() . '">';
          echo '<div class="pn-av" style="--h:' . pronote::hue($name) . '">' . ($eq->hasPhoto()
              ? '<img src="plugins/pronote/core/ajax/pronote.ajax.php?action=photo&id=' . (int)$eq->getId() . '&t=' . (int)@filemtime($eq->photoFile()) . '" alt="" />'
              : $h(pronote::initials($name))) . '</div>';
          echo '<div><b>' . $h($name) . '</b><small>' . $h(trim($eq->getConfiguration('student_class', '') . ' · ' . $eq->getConfiguration('establishment', ''), ' ·')) . '</small></div></div>';
      } ?>
    </div>
    <div class="pnp-actions">
      <?php if ($isAdmin) { ?>
      <a class="btn btn-default btn-sm" href="index.php?v=d&m=pronote&p=pronote"><i class="fas fa-cog"></i> {{Plugin}}</a>
      <button class="btn btn-primary btn-sm" id="pnp-refresh"><i class="fas fa-sync"></i> {{Synchroniser}}</button>
      <?php } ?>
    </div>
  </div>

  <?php foreach ($students as $k => $eq) {
      $id = (int)$eq->getId();
      $d = $eq->getData();
      $name = trim((string)$eq->getConfiguration('student_name', '')) ?: $eq->getName();
      $avg = $val($eq, 'avg_general', '');
      $avgClass = $val($eq, 'avg_class', '');
      $lastGrade = $val($eq, 'last_grade', '');
      $hwCount = (int)$val($eq, 'homework_count', 0);
      $hwTomorrow = (int)$val($eq, 'homework_tomorrow', 0);
      $absences = $val($eq, 'absences', '');
      $delays = (int)$val($eq, 'delays', 0);
      $unjust = (int)$val($eq, 'absences_unjustified', 0);
      $periodName = $val($eq, 'period_name', '');
      $periodEnd = $val($eq, 'period_end', '');
      $periodProgress = (int)$val($eq, 'period_progress', 0);
      $periodLeft = $val($eq, 'period_days_left', '');
      $nh = pronote::nextHoliday($eq);
      $holidaySource = $eq->holidayRangesFor()[0];
      $lastSync = (int)$eq->getCache('lastSync', 0);
      $lastError = (string)$eq->getCache('lastError', '');
      $lessons = (isset($d['_lessons']) && is_array($d['_lessons'])) ? $d['_lessons'] : array();
      $homework = (isset($d['_homework']) && is_array($d['_homework'])) ? $d['_homework'] : array();
      $subjects = (isset($d['_subjects']) && is_array($d['_subjects'])) ? $d['_subjects'] : array();
      $declining = (string)$val($eq, 'subjects_declining', '');
      $downNames = array();
      foreach (array_filter(array_map('trim', explode('·', $declining))) as $item) {
          $downNames[] = trim(preg_replace('/\s[\d,\.]+\s→.*$/u', '', $item));
      }

      /* Bornes horaires de la grille : premier et dernier cours sur 14 jours. */
      $h0 = 24; $h1 = 0;
      foreach ($lessons as $l) {
          if (empty($l['start'])) { continue; }
          $h0 = min($h0, (int)substr($l['start'], 0, 2));
          if (!empty($l['end'])) { $h1 = max($h1, (int)substr($l['end'], 0, 2) + ((int)substr($l['end'], 3, 2) > 0 ? 1 : 0)); }
      }
      if ($h0 >= $h1) { $h0 = 8; $h1 = 18; }
      $hwByDate = array();
      foreach ($homework as $hw) { if (!empty($hw['date'])) { $hwByDate[$hw['date']][] = $hw; } }
  ?>
  <div class="pnp-student<?php echo $k === 0 ? ' on' : ''; ?>" data-id="<?php echo $id; ?>">
    <?php if ($lastError !== '') { ?>
      <div class="pnp-alerts"><span class="pnp-chip bad"><i class="fas fa-exclamation-triangle"></i> <?php echo $h($lastError); ?></span></div>
    <?php } ?>
    <div class="pnp-stats">
      <div class="pnp-stat"><span class="l">{{Moyenne générale}}</span>
        <span class="v"><?php echo $avg === '' ? '—' : $h(str_replace('.', ',', $avg)) . '<small>/20</small>'; ?></span>
        <span class="s"><?php echo $avgClass === '' ? '{{pas encore de note}}' : '{{classe}} ' . $h(str_replace('.', ',', $avgClass)); ?></span></div>
      <div class="pnp-stat"><span class="l">{{Devoirs à faire}}</span>
        <span class="v"><?php echo $hwCount; ?></span>
        <span class="s"><?php echo $hwTomorrow ? $hwTomorrow . ' {{pour demain}}' : '{{rien pour demain}}'; ?></span></div>
      <div class="pnp-stat"><span class="l">{{Absences}}</span>
        <span class="v"><?php echo $absences === '' ? '—' : $h(str_replace('.', ',', $absences)) . '<small> h</small>'; ?></span>
        <span class="s"><?php echo $delays . ' {{retard}}' . ($delays > 1 ? 's' : '') . ($unjust ? ' · <span style="color:var(--pn-bad)">' . $unjust . ' {{non justifiée}}' . ($unjust > 1 ? 's' : '') . '</span>' : ''); ?></span></div>
      <div class="pnp-stat"><span class="l">{{Période}}</span>
        <span class="v" style="font-size:18px"><?php echo $periodName === '' ? '—' : $h($periodName); ?></span>
        <?php if ($periodEnd !== '') { ?><span class="s">{{jusqu'au}} <?php echo $h($periodEnd); ?><?php echo $periodLeft !== '' ? ' · ' . (int)$periodLeft . ' j' : ''; ?></span>
        <div class="pnp-bar"><i style="width:<?php echo max(0, min(100, $periodProgress)); ?>%"></i></div><?php } ?></div>
      <div class="pnp-stat"><span class="l">{{Vacances}}</span>
        <?php if ($nh) { $inDays = (int)ceil(($nh[0] - time()) / 86400); ?>
          <span class="v" style="font-size:18px"><?php echo $inDays <= 0 ? '{{en cours}}' : '{{dans}} ' . $inDays . ' j'; ?></span>
          <span class="s"><?php echo $h($nh[2]); ?> · <?php echo date('d/m', $nh[0]); ?> → <?php echo date('d/m', $nh[1]); ?><?php echo $holidaySource === 'pronote' ? ' · {{établissement}}' : ''; ?></span>
        <?php } else { ?><span class="v" style="font-size:18px">—</span><span class="s">{{calendrier non disponible}}</span><?php } ?></div>
    </div>

    <div class="pnp-alerts">
      <?php if ((int)$val($eq, 'course_cancelled_tomorrow', 0)) echo '<span class="pnp-chip warn"><i class="fas fa-ban"></i> {{Cours annulé demain}}</span>'; ?>
      <?php if ((int)$val($eq, 'course_cancelled', 0)) echo '<span class="pnp-chip warn"><i class="fas fa-ban"></i> {{Cours annulé aujourd\'hui}}</span>'; ?>
      <?php if ((int)$val($eq, 'homework_tomorrow_pending', 0)) echo '<span class="pnp-chip warn"><i class="fas fa-pen"></i> {{Devoir pour demain non fait}}</span>'; ?>
      <?php if ((int)$val($eq, 'new_grades', 0)) echo '<span class="pnp-chip ok"><i class="fas fa-star"></i> ' . (int)$val($eq, 'new_grades', 0) . ' {{nouvelle(s) note(s)}}' . ($lastGrade !== '' ? ' · ' . $h($lastGrade) : '') . '</span>'; ?>
      <?php if ($declining !== '') echo '<span class="pnp-chip warn"><i class="fas fa-arrow-down"></i> {{En baisse :}} ' . $h($declining) . '</span>'; ?>
      <?php if ((int)$val($eq, 'new_messages', 0)) echo '<span class="pnp-chip"><i class="fas fa-envelope"></i> ' . (int)$val($eq, 'new_messages', 0) . ' {{message(s) non lu(s)}}</span>'; ?>
      <?php if ((int)$val($eq, 'new_infos', 0)) echo '<span class="pnp-chip"><i class="fas fa-bullhorn"></i> ' . (int)$val($eq, 'new_infos', 0) . ' {{information(s) non lue(s)}}</span>'; ?>
      <?php if ((int)$val($eq, 'punishments', 0)) echo '<span class="pnp-chip bad"><i class="fas fa-gavel"></i> ' . (int)$val($eq, 'punishments', 0) . ' {{punition(s)}}</span>'; ?>
    </div>

    <div class="pnp-block">
      <h3><i class="far fa-calendar-alt"></i> {{Emploi du temps}}
        <span class="r pnp-weeknav">
          <button class="btn btn-default pnp-week-btn on" data-week="0">{{Cette semaine}}</button>
          <button class="btn btn-default pnp-week-btn" data-week="1">{{Semaine prochaine}}</button>
        </span></h3>
      <?php if (!count($lessons)) { ?>
        <div class="pnp-empty">{{Aucun cours connu : activer le bloc « Emploi du temps » et synchroniser.}}</div>
      <?php } else {
          for ($w = 0; $w < 2; $w++) {
              $days = array();
              for ($i = 0; $i < 7; $i++) { $days[] = date('Y-m-d', $monday + ($w * 7 + $i) * 86400); }
              $byDay = array();
              foreach ($lessons as $l) { if (!empty($l['date'])) { $byDay[$l['date']][] = $l; } }
              $cols = 5;
              foreach (array(5, 6) as $i) { if (!empty($byDay[$days[$i]])) { $cols = $i + 1; } }
              echo '<div class="pnp-week' . ($w ? ' hidden' : '') . '" data-week="' . $w . '" style="--cols:' . $cols . ';--h0:' . $h0 . ';--h1:' . $h1 . '">';
              echo '<div></div>';
              for ($i = 0; $i < $cols; $i++) {
                  $ts = strtotime($days[$i]);
                  echo '<div class="dh' . ($days[$i] === $today ? ' today' : '') . '">' . $jours[$i] . '<small>' . date('j', $ts) . ' ' . $mois[(int)date('n', $ts)] . '</small></div>';
              }
              echo '<div class="hours">';
              for ($hh = $h0; $hh <= $h1; $hh++) {
                  echo '<span style="top:calc((' . $hh . ' - var(--h0)) * var(--ph))">' . $hh . 'h</span>';
              }
              echo '</div>';
              for ($i = 0; $i < $cols; $i++) {
                  $date = $days[$i];
                  echo '<div class="col' . ($date === $today ? ' today' : '') . '" data-date="' . $date . '">';
                  if (!empty($hwByDate[$date])) {
                      $n = count(array_filter($hwByDate[$date], function ($x) { return empty($x['done']); }));
                      if ($n) { echo '<div class="hw" title="{{devoirs à rendre}}">' . str_repeat('●', min($n, 4)) . '</div>'; }
                  }
                  foreach ((isset($byDay[$date]) ? $byDay[$date] : array()) as $l) {
                      if (empty($l['start'])) { continue; }
                      $s = (int)substr($l['start'], 0, 2) * 60 + (int)substr($l['start'], 3, 2);
                      $e = !empty($l['end']) ? (int)substr($l['end'], 0, 2) * 60 + (int)substr($l['end'], 3, 2) : $s + 55;
                      $top = 'calc((' . $s . ' / 60 - var(--h0)) * var(--ph))';
                      $height = 'calc(' . max(20, $e - $s) . ' / 60 * var(--ph) - 2px)';
                      $cls = 'ev' . (!empty($l['cancelled']) ? ' cancelled' : '') . (!empty($l['test']) ? ' test' : '');
                      $sub = array_filter(array($l['teacher'] ?? '', $l['room'] ?? '', (!empty($l['cancelled']) && !empty($l['status'])) ? $l['status'] : ''));
                      echo '<div class="' . $cls . '" data-start="' . preg_replace('/\D/', '', $l['start']) . '" data-end="' . preg_replace('/\D/', '', (string)($l['end'] ?? '')) . '" style="--c:hsl(' . pronote::hue($l['subject'] ?? '') . ',55%,58%);top:' . $top . ';height:' . $height . '" title="' . $h(($l['subject'] ?? '') . ' ' . $l['start'] . '–' . ($l['end'] ?? '') . ' ' . implode(' · ', $sub)) . '">';
                      echo '<b>' . $h($l['subject'] ?? '') . '</b><i>' . $h(implode(' · ', $sub)) . '</i></div>';
                  }
                  if ($date === $today) { echo '<div class="nowline"></div>'; }
                  echo '</div>';
              }
              echo '</div>';
          }
      } ?>
    </div>

    <div class="pnp-grid2">
      <div class="pnp-block">
        <h3><i class="fas fa-pen"></i> {{Devoirs}} <span class="r"><?php echo count($homework) ? count($homework) . ' {{sur 14 jours}}' : ''; ?></span></h3>
        <?php if (!count($homework)) { ?><div class="pnp-empty">{{Aucun devoir à venir.}}</div><?php } else {
            ksort($hwByDate);
            foreach ($hwByDate as $date => $list) {
                if ($date < $today) { continue; }
                $ts = strtotime($date);
                $rel = ($date === $today) ? '{{aujourd\'hui}}' : (($date === date('Y-m-d', strtotime('+1 day'))) ? '{{demain}}' : '');
                echo '<div class="pnp-hwday">' . $jours[(int)date('N', $ts) - 1] . ' ' . date('j', $ts) . ' ' . $mois[(int)date('n', $ts)] . ($rel ? ' · ' . $rel : '') . '</div>';
                echo '<ul class="pronote-hw">';
                foreach ($list as $hw) {
                    $urgent = (strtotime($date) - strtotime($today)) <= 86400 && empty($hw['done']);
                    echo '<li class="' . (!empty($hw['done']) ? 'done' : ($urgent ? 'urgent' : '')) . '"><b>' . $h($hw['subject'] ?? '') . '</b><span class="d">' . (!empty($hw['done']) ? '✓ {{fait}}' : '') . '</span><span class="t">' . $h($hw['description'] ?? '') . '</span></li>';
                }
                echo '</ul>';
            }
        } ?>
      </div>

      <div class="pnp-block">
        <h3><i class="fas fa-chart-bar"></i> {{Moyennes par matière}}<span class="r"><?php echo $periodName !== '' ? $h($periodName) : ''; ?></span></h3>
        <?php if (!count($subjects)) { ?><div class="pnp-empty">{{Pas encore de moyenne : activer le bloc « Notes » et synchroniser.}}</div><?php } else { ?>
          <div class="pnp-subjects">
          <?php foreach ($subjects as $sj) {
              $v = isset($sj['value']) ? (float)$sj['value'] : null;
              $c = isset($sj['class_value']) && $sj['class_value'] !== null ? (float)$sj['class_value'] : null;
              if ($v === null) { continue; }
              $isDown = in_array($sj['name'], $downNames);
              echo '<div class="pnp-subj' . ($isDown ? ' down' : '') . '" title="' . $h(($sj['last_grade'] ?? '') !== '' ? '{{Dernière note}} : ' . $sj['last_grade'] : '') . '">';
              echo '<span class="n">' . $h($sj['name']) . '</span>';
              echo '<span class="bars"><i style="width:' . max(0, min(100, $v * 5)) . '%"></i>' . ($c !== null ? '<i class="c" style="width:' . max(0, min(100, $c * 5)) . '%"></i>' : '') . '</span>';
              echo '<span class="m">' . $h(str_replace('.', ',', (string)$v)) . ($c !== null ? '<small>' . $h(str_replace('.', ',', (string)$c)) . '</small>' : '') . '</span></div>';
          } ?>
          </div>
          <div class="pnp-legend"><span><i></i>{{élève}}</span><span><i class="c"></i>{{classe}}</span></div>
        <?php } ?>
        <?php $gh = (string)$val($eq, 'grades_html', ''); if ($gh !== '') { ?>
          <h3 style="margin-top:16px"><i class="fas fa-star"></i> {{Dernières notes}}</h3>
          <?php echo $gh; ?>
        <?php } ?>
      </div>

      <div class="pnp-block">
        <h3><i class="fas fa-user-clock"></i> {{Vie scolaire}}</h3>
        <?php $ah = (string)$val($eq, 'absences_html', ''); echo $ah !== '' ? $ah : '<div class="pnp-empty">{{Aucune absence ni retard sur la période.}}</div>'; ?>
        <?php $sk = (string)$val($eq, 'skills_html', ''); if ($sk !== '') { echo '<h3 style="margin-top:16px"><i class="fas fa-check-double"></i> {{Compétences}}</h3>' . $sk; } ?>
      </div>

      <div class="pnp-block">
        <h3><i class="fas fa-envelope"></i> {{Messagerie et informations}}</h3>
        <?php $mh = (string)$val($eq, 'messages_html', ''); $ih = (string)$val($eq, 'infos_html', '');
        if ($mh === '' && $ih === '') { echo '<div class="pnp-empty">{{Rien à afficher : activer le bloc « Vie scolaire » et synchroniser.}}</div>'; }
        echo $mh;
        if ($ih !== '') { echo '<div class="pnp-hwday" style="margin-top:12px">{{Informations et sondages}}</div>' . $ih; } ?>
      </div>

      <div class="pnp-block">
        <h3><i class="fas fa-utensils"></i> {{Cantine}}</h3>
        <?php $mw = (string)$val($eq, 'menu_week_html', ''); echo $mw !== '' ? $mw : '<div class="pnp-empty">{{Aucun menu publié.}}</div>'; ?>
      </div>

      <div class="pnp-block">
        <h3><i class="fas fa-calendar-plus"></i> {{Agenda (iCal)}}</h3>
        <?php if ($isAdmin) { $url = $eq->icalUrl(); ?>
          <div class="pnp-ical">
            <span>{{À coller dans « Abonnement à un calendrier » de Google Agenda, Apple Calendrier, Outlook, Thunderbird… : cours, devoirs et vacances, rafraîchis toutes les 6 h.}}</span>
            <code id="pnp-ical-<?php echo $id; ?>"><?php echo $h($url); ?></code>
            <button class="btn btn-default btn-xs pnp-copy" data-target="pnp-ical-<?php echo $id; ?>"><i class="fas fa-copy"></i> {{Copier}}</button>
            <a class="btn btn-default btn-xs" href="<?php echo $h($eq->icalUrl(true)); ?>" target="_blank"><i class="fas fa-download"></i> {{Télécharger}}</a>
          </div>
          <div class="pnp-foot">{{Le lien contient la clé API du plugin : ne le partagez qu'avec les agendas de la famille. Option : &amp;todo=1 pour des tâches au lieu d'événements, &amp;vacances=0 sans les vacances.}}</div>
        <?php } else { ?>
          <div class="pnp-empty">{{Le lien d'abonnement est visible par un administrateur.}}</div>
        <?php } ?>
      </div>
    </div>
    <div class="pnp-foot"><?php echo $lastSync ? '{{Dernière synchronisation}} : ' . date('d/m/Y H:i', $lastSync) : '{{Jamais synchronisé}}'; ?></div>
  </div>
  <?php } ?>
<?php } ?>
</div>

<script>
(function () {
  var root = document.getElementById('pnp');
  if (!root) return;
  var isAdmin = <?php echo $isAdmin ? 'true' : 'false'; ?>;

  /* Onglets élèves (mémorisés dans le navigateur) */
  var tabs = root.querySelectorAll('.pnp-tab');
  function showStudent(id) {
    root.querySelectorAll('.pnp-student').forEach(function (el) { el.classList.toggle('on', el.dataset.id === id); });
    tabs.forEach(function (t) { t.classList.toggle('on', t.dataset.id === id); });
    try { localStorage.setItem('pronote.panel.student', id); } catch (e) {}
    tickNow();
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { showStudent(t.dataset.id); }); });
  try {
    var saved = localStorage.getItem('pronote.panel.student');
    if (saved && root.querySelector('.pnp-student[data-id="' + saved + '"]')) showStudent(saved);
  } catch (e) {}

  /* Semaine courante / prochaine */
  root.querySelectorAll('.pnp-week-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var block = b.closest('.pnp-block');
      block.querySelectorAll('.pnp-week-btn').forEach(function (x) { x.classList.toggle('on', x === b); x.classList.toggle('btn-primary', x === b); x.classList.toggle('btn-default', x !== b); });
      block.querySelectorAll('.pnp-week').forEach(function (w) { w.classList.toggle('hidden', w.dataset.week !== b.dataset.week); });
      tickNow();
    });
  });
  root.querySelectorAll('.pnp-week-btn.on').forEach(function (b) { b.classList.add('btn-primary'); b.classList.remove('btn-default'); });

  /* Trait « maintenant », cours passés / en cours — heure du navigateur */
  function tickNow() {
    var now = new Date();
    var hhmm = now.getHours() * 100 + now.getMinutes();
    var minutes = now.getHours() * 60 + now.getMinutes();
    root.querySelectorAll('.pnp-week').forEach(function (w) {
      var h0 = parseInt(w.style.getPropertyValue('--h0') || 8, 10), h1 = parseInt(w.style.getPropertyValue('--h1') || 18, 10);
      var ph = parseFloat(getComputedStyle(w).getPropertyValue('--ph')) || 44;
      w.querySelectorAll('.col.today .ev').forEach(function (ev) {
        var s = parseInt(ev.dataset.start, 10), e = parseInt(ev.dataset.end || '2400', 10);
        ev.classList.toggle('past', e <= hhmm);
        ev.classList.toggle('now', s <= hhmm && hhmm < e);
      });
      var line = w.querySelector('.col.today .nowline');
      if (line) {
        var inside = minutes >= h0 * 60 && minutes <= h1 * 60;
        line.style.display = inside ? 'block' : 'none';
        line.style.top = ((minutes / 60 - h0) * ph) + 'px';
      }
    });
  }
  tickNow();
  setInterval(tickNow, 60000);

  /* Copier le lien iCal */
  root.querySelectorAll('.pnp-copy').forEach(function (b) {
    b.addEventListener('click', function () {
      var text = document.getElementById(b.dataset.target).textContent;
      var done = function () { b.innerHTML = '<i class="fas fa-check"></i> Copié'; setTimeout(function () { b.innerHTML = '<i class="fas fa-copy"></i> Copier'; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
      else { var r = document.createRange(); r.selectNodeContents(document.getElementById(b.dataset.target)); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });

  /* Synchroniser l'élève affiché, puis recharger la page */
  var refresh = document.getElementById('pnp-refresh');
  if (refresh && isAdmin) {
    refresh.addEventListener('click', function () {
      var cur = root.querySelector('.pnp-student.on');
      if (!cur) return;
      refresh.disabled = true;
      refresh.innerHTML = '<i class="fas fa-sync fa-spin"></i> Synchronisation…';
      var body = new URLSearchParams({ action: 'sync', id: cur.dataset.id });
      fetch('plugins/pronote/core/ajax/pronote.ajax.php', { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.state !== 'ok') throw new Error(res.result || 'erreur');
          location.reload();
        })
        .catch(function (e) {
          refresh.disabled = false;
          refresh.innerHTML = '<i class="fas fa-sync"></i> Synchroniser';
          if (window.jeedomUtils && jeedomUtils.showAlert) jeedomUtils.showAlert({ message: e.message, level: 'danger' }); else alert(e.message);
        });
    });
  }
})();
</script>
