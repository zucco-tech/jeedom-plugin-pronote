<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
/* Ne pas dépendre de $plugin : Jeedom ne le définit que si l'URL porte le
   paramètre « m ». */
$pluginId = 'pronote';

/* Variable obligatoire : plugin.template.js s'en sert pour sauvegarder,
   supprimer et configurer un équipement. */
sendVarToJS('eqType', $pluginId);

$eqLogics = eqLogic::byType($pluginId);
$dep = pronote::dependancy_info();
$pronotepyVersion = ($dep['state'] === 'ok') ? pronote::pronotepyVersion() : '';
?>
<style>
  /* Feuille de style de la page Pronote — variables du thème Jeedom, avec repli. */
  .pronote-page{--pn-line:rgba(128,140,155,.22);--pn-soft:rgba(128,140,155,.10);--pn-accent:var(--link-color,#1e8fd5)}
  .pronote-page .pn-hero{display:flex;align-items:center;gap:18px;padding:18px 22px;margin:8px 0 22px;border-radius:var(--border-radius,6px);background:var(--panel-bg-color,rgba(128,140,155,.08));border:1px solid var(--pn-line);flex-wrap:wrap}
  .pronote-page .pn-hero img{width:56px;height:56px;border-radius:14px;flex:none}
  .pronote-page .pn-hero h1{margin:0;font-size:22px;font-weight:500;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .pronote-page .pn-hero p{margin:3px 0 0;font-size:13px;opacity:.7;max-width:60ch}
  .pronote-page .pn-hero .pn-chips{margin-left:auto}
  .pronote-page .pn-section{font-size:11px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;opacity:.55;margin:0 0 10px;display:flex;align-items:center;gap:8px}
  .pronote-page .pn-cards{display:flex;flex-wrap:wrap;gap:14px;margin:6px 0 18px}
  .pronote-page .pn-empty{padding:22px 24px;margin:0 0 18px;border-radius:var(--border-radius,6px);border:1px dashed var(--pn-line);max-width:760px}
  .pronote-page .pn-empty h3{margin:0 0 4px;font-size:16px;font-weight:500}
  .pronote-page .pn-empty p{margin:0 0 16px;font-size:13px;opacity:.7}
  .pronote-page .pn-empty ol{list-style:none;margin:0 0 18px;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
  .pronote-page .pn-empty li{display:grid;grid-template-columns:28px 1fr;gap:10px;align-items:start}
  .pronote-page .pn-empty li i{width:26px;height:26px;border-radius:50%;background:var(--pn-accent);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;font-style:normal}
  .pronote-page .pn-empty li b{display:block;font-size:13px;font-weight:500}
  .pronote-page .pn-empty li small{font-size:11.5px;opacity:.65;line-height:1.35}
  .pronote-page .pn-empty .btn-primary{font-weight:500}
  .pronote-page .pn-card{width:250px;padding:14px 16px;border-radius:var(--border-radius,6px);background:var(--panel-bg-color,rgba(128,140,155,.08));border:1px solid var(--pn-line);display:flex;gap:12px;align-items:center;transition:transform .12s,box-shadow .12s}
  .pronote-page .pn-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(0,0,0,.25)}
  .pronote-page .pn-card.add{border-style:dashed;justify-content:center;color:var(--pn-accent);font-weight:500}
  .pronote-page .pn-av{width:44px;height:44px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:15px;color:#fff;background:hsl(var(--h,200),50%,48%)}
  .pronote-page .pn-card .pn-n{font-size:14px;font-weight:500;line-height:1.2}
  .pronote-page .pn-card .pn-c{font-size:11.5px;opacity:.65;line-height:1.3}
  .pronote-page .pn-card .pn-s{display:flex;align-items:center;gap:6px;font-size:11px;margin-top:5px}
  .pronote-page .pn-dot{width:7px;height:7px;border-radius:50%;flex:none}
  .pronote-page .pn-dot.ok{background:var(--al-success-color,#5cb85c)}
  .pronote-page .pn-dot.warn{background:var(--al-warning-color,#f0ad4e)}
  .pronote-page .pn-dot.bad{background:var(--al-danger-color,#d9534f)}
  .pronote-page .pn-dot.off{background:rgba(128,140,155,.5)}

  .pronote-page .pn-head{display:flex;align-items:center;gap:16px;padding:16px 18px;margin:14px 0 18px;border-radius:var(--border-radius,6px);background:var(--panel-bg-color,rgba(128,140,155,.08));border:1px solid var(--pn-line);flex-wrap:wrap}
  .pronote-page .pn-head .pn-av{width:56px;height:56px;font-size:19px}
  .pronote-page .pn-head .pn-name{min-width:220px}
  .pronote-page .pn-head input.pn-name-input{font-size:20px;font-weight:500;height:38px;background:transparent;border:1px solid transparent;border-radius:4px;padding:0 6px;margin-left:-6px;color:inherit;width:100%}
  .pronote-page .pn-head input.pn-name-input:hover,.pronote-page .pn-head input.pn-name-input:focus{border-color:var(--pn-line);background:var(--form-bg-color,rgba(0,0,0,.15))}
  .pronote-page .pn-head .pn-sub{font-size:12.5px;opacity:.65;margin-top:1px;padding-left:1px}
  .pronote-page .pn-chips{display:flex;flex-wrap:wrap;gap:8px;margin-left:auto;align-items:center}
  .pronote-page .pn-chip{display:inline-flex;align-items:center;gap:7px;height:28px;padding:0 11px;border-radius:14px;font-size:12px;background:var(--pn-soft);border:1px solid var(--pn-line)}
  .pronote-page .pn-chip b{font-weight:500}
  .pronote-page .pn-chip.ok{color:var(--al-success-color,#5cb85c)}
  .pronote-page .pn-chip.warn{color:var(--al-warning-color,#f0ad4e)}
  .pronote-page .pn-chip.bad{color:var(--al-danger-color,#d9534f)}
  .pronote-page .pn-alert{display:none;padding:9px 12px;margin:-8px 0 14px;border-radius:var(--border-radius,6px);background:rgba(240,173,78,.14);color:var(--al-warning-color,#f0ad4e);font-size:12.5px}
  .pronote-page .pn-alert.on{display:block}

  .pronote-page .pn-sec{margin-bottom:18px;border-radius:var(--border-radius,6px);border:1px solid var(--pn-line);background:var(--panel-bg-color,rgba(128,140,155,.06))}
  .pronote-page .pn-sec > h4{margin:0;padding:11px 16px;border-bottom:1px solid var(--pn-line);font-size:11px;font-weight:600;letter-spacing:.09em;text-transform:uppercase;opacity:.7;display:flex;align-items:center;gap:9px}
  .pronote-page .pn-sec > div{padding:14px 16px}
  .pronote-page .pn-sec .form-group{margin-bottom:12px}
  .pronote-page .pn-sec .form-group:last-child{margin-bottom:0}
  .pronote-page .control-label{font-weight:500;opacity:.85}
  .pronote-page .help-block{font-size:11.5px;opacity:.6;margin-bottom:0}

  .pronote-page .pn-steps{display:flex;flex-direction:column;gap:10px}
  .pronote-page .pn-step{display:grid;grid-template-columns:30px 1fr;gap:12px;padding:12px 14px;border-radius:var(--border-radius,6px);background:var(--pn-soft);border:1px solid transparent}
  .pronote-page .pn-step.cur{border-color:var(--pn-accent)}
  .pronote-page .pn-step.done{opacity:.7}
  .pronote-page .pn-step .pn-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;background:var(--pn-line)}
  .pronote-page .pn-step.cur .pn-num{background:var(--pn-accent);color:#fff}
  .pronote-page .pn-step.done .pn-num{background:var(--al-success-color,#5cb85c);color:#fff}
  .pronote-page .pn-step b{display:block;font-size:13px;font-weight:500;margin-bottom:2px}
  .pronote-page .pn-step p{margin:0 0 8px;font-size:12px;opacity:.7}
  .pronote-page .pn-step .pn-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .pronote-page .pn-step details{font-size:11.5px;opacity:.7;margin-top:6px}
  .pronote-page .pn-step details textarea{margin-top:6px;font-family:ui-monospace,Menlo,monospace;font-size:11px}
  .pronote-page .pn-pin{width:96px;text-align:center;letter-spacing:.35em;font-size:16px;font-weight:600}

  .pronote-page .pn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px}
  .pronote-page .pn-opt{display:grid;grid-template-columns:auto 1fr;gap:4px 10px;align-items:center;padding:10px 12px;border-radius:var(--border-radius,6px);border:1px solid var(--pn-line);background:var(--pn-soft);cursor:pointer;margin:0;font-weight:400}
  .pronote-page .pn-opt input{margin:0;grid-row:span 2;width:16px;height:16px}
  .pronote-page .pn-opt b{font-weight:500;font-size:12.5px}
  .pronote-page .pn-opt small{font-size:11px;opacity:.6;line-height:1.25}
  .pronote-page .pn-opt:has(input:checked){border-color:var(--pn-accent);background:color-mix(in srgb,var(--pn-accent) 12%,transparent)}
  .pronote-page .pn-opt.off{opacity:.55}

  .pronote-page .pn-seg{display:inline-flex;border:1px solid var(--pn-line);border-radius:4px;overflow:hidden}
  .pronote-page .pn-seg label{margin:0;padding:6px 14px;font-size:12.5px;font-weight:400;cursor:pointer;border-left:1px solid var(--pn-line)}
  .pronote-page .pn-seg label:first-child{border-left:0}
  .pronote-page .pn-seg label:has(input:checked){background:var(--pn-accent);color:#fff}
  .pronote-page .pn-seg input{display:none}
  .pronote-page .pn-toggles label{display:flex;gap:10px;align-items:flex-start;font-weight:400;margin:0 0 8px}
  .pronote-page .pn-toggles label input{margin-top:3px}
  .pronote-page .pn-toggles small{display:block;font-size:11px;opacity:.6}
  .pronote-page #table_cmd .label{font-family:ui-monospace,Menlo,monospace;font-weight:400}
</style>

<div class="row row-overflow pronote-page">
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <div class="pn-hero">
      <img src="plugins/pronote/plugin_info/pronote_icon.png" alt="" />
      <div>
        <h1>Pronote
          <span class="pn-chip warn" title="{{Une seule installation testée : sauvegarder Jeedom, signaler les problèmes sur le dépôt.}}"><i class="fas fa-flask"></i> {{bêta}} <b><?php echo htmlspecialchars(json_decode(file_get_contents(__DIR__ . '/../../plugin_info/info.json'), true)['version'] ?? ''); ?></b></span>
        </h1>
        <p>{{Notes, devoirs, emploi du temps, absences et vie scolaire de vos enfants, remontés de Pronote dans Jeedom.}}</p>
      </div>
      <div class="pn-chips">
        <?php if ($dep['state'] === 'ok') { ?>
          <span class="pn-chip ok"><i class="fas fa-check"></i> {{Dépendances OK}} <b>pronotepy <?php echo htmlspecialchars($pronotepyVersion); ?></b></span>
        <?php } else { ?>
          <span class="pn-chip bad"><i class="fas fa-exclamation-triangle"></i> {{Dépendances non installées}}</span>
        <?php } ?>
        <a class="btn btn-default btn-sm eqLogicAction" data-action="gotoPluginConf"><i class="fas fa-wrench"></i> {{Configuration du plugin}}</a>
      </div>
    </div>

    <?php if (count($eqLogics) === 0) { ?>
      <div class="pn-empty">
        <h3>{{Premier élève en trois étapes}}</h3>
        <p>{{Un élève = un équipement Jeedom, avec ses propres commandes et son widget.}}</p>
        <ol>
          <li><i>1</i><span><b>{{Ajouter un élève}}</b><small>{{Son prénom, le type de compte (Parent ou Élève) et l'adresse Pronote de l'établissement.}}</small></span></li>
          <li><i>2</i><span><b>{{Enrôler un QR Code}}</b><small>{{Dans Pronote : Mon compte › Autoriser un accès mobile. Déposer l'image, saisir le code, c'est fait une fois pour toutes.}}</small></span></li>
          <li><i>3</i><span><b>{{Synchroniser}}</b><small>{{Les commandes se remplissent ; le widget se pose ensuite sur le dashboard.}}</small></span></li>
        </ol>
        <a class="btn btn-primary eqLogicAction" data-action="add"><i class="fas fa-plus"></i> {{Ajouter un élève}}</a>
      </div>
    <?php } else { ?>
      <div class="pn-section"><i class="fas fa-graduation-cap"></i> {{Mes élèves}}</div>
      <div class="pn-cards">
        <?php
        foreach ($eqLogics as $eqLogic) {
            $opacity = ($eqLogic->getIsEnable()) ? '' : ' disableCard';
            $name = $eqLogic->getName();
            $sub = trim(implode(' · ', array_filter(array($eqLogic->getConfiguration('student_class', ''), $eqLogic->getConfiguration('establishment', '')))));
            $err = (string)$eqLogic->getCache('lastError', '');
            $token = $eqLogic->getConfiguration('credentials', '') !== '';
            $last = (int)$eqLogic->getCache('lastSync', 0);
            if (!$eqLogic->getIsEnable()) {
                $dot = 'off'; $status = __('Désactivé', __FILE__);
            } elseif (!$token) {
                $dot = 'warn'; $status = __('À enrôler', __FILE__);
            } elseif ($err !== '') {
                $dot = 'bad'; $status = __('Erreur de synchronisation', __FILE__);
            } else {
                $dot = 'ok'; $status = $last > 0 ? __('Synchronisé à ', __FILE__) . date('H:i', $last) : __('Jamais synchronisé', __FILE__);
            }
            echo '<div class="eqLogicDisplayCard cursor pn-card' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
            echo '<div class="pn-av" style="--h:' . pronote::hue($name) . '">' . htmlspecialchars(pronote::initials($name)) . '</div>';
            echo '<div style="min-width:0">';
            echo '<div class="pn-n name">' . htmlspecialchars($name) . '</div>';
            echo '<div class="pn-c">' . ($sub !== '' ? htmlspecialchars($sub) : '{{Classe et établissement remontés à la première synchronisation}}') . '</div>';
            echo '<div class="pn-s"><span class="pn-dot ' . $dot . '"></span>' . htmlspecialchars($status) . '</div>';
            echo '</div></div>';
        }
        ?>
        <div class="cursor eqLogicAction pn-card add" data-action="add">
          <i class="fas fa-plus-circle"></i> {{Ajouter un élève}}
        </div>
      </div>
    <?php } ?>
  </div>

  <div class="col-xs-12 eqLogic" style="display: none;">
    <div class="input-group pull-right" style="display:inline-flex">
      <span class="input-group-btn">
        <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
        <a class="btn btn-sm btn-default eqLogicAction" data-action="copy"><i class="fas fa-copy"></i><span class="hidden-xs"> {{Dupliquer}}</span></a>
        <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
        <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
      </span>
    </div>

    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-user-graduate"></i> {{Élève}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
    </ul>

    <div class="tab-content">
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">

        <div class="pn-head">
          <div class="pn-av" id="pn_avatar">?</div>
          <div class="pn-name">
            <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;" />
            <input type="text" class="eqLogicAttr pn-name-input" data-l1key="name" placeholder="{{Prénom de l'élève}}" />
            <div class="pn-sub" id="pn_sub">{{Classe et établissement remontés à la première synchronisation}}</div>
          </div>
          <div class="pn-chips">
            <span class="pn-chip" id="span_tokenState"><i class="fas fa-key"></i> {{jeton}}</span>
            <span class="pn-chip"><i class="fas fa-sync"></i> <span id="span_lastSync">—</span></span>
            <a class="btn btn-default btn-sm" id="bt_syncNow"><i class="fas fa-sync"></i> {{Synchroniser}}</a>
            <a class="btn btn-default btn-sm" id="bt_selftest" title="{{Remplit les commandes avec des valeurs fictives, sans contacter Pronote}}"><i class="fas fa-vial"></i> {{Jeu d'essai}}</a>
          </div>
        </div>
        <div class="pn-alert" id="pn_alert"></div>

        <div class="row">
          <div class="col-lg-6">
            <form class="form-horizontal">
              <div class="pn-sec">
                <h4><i class="fas fa-lock"></i> {{Connexion Pronote}}</h4>
                <div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Mode}}</label>
                    <div class="col-sm-8">
                      <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="mode" id="sel_mode">
                        <option value="qr">{{QR Code — recommandé}}</option>
                        <option value="ent">{{ENT}}</option>
                        <option value="password">{{Identifiants directs}}</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Compte}}</label>
                    <div class="col-sm-8">
                      <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="account" id="sel_account">
                        <option value="eleve">{{Élève}}</option>
                        <option value="parent">{{Parent}}</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-group pronote-account pronote-account-parent">
                    <label class="col-sm-4 control-label">{{Enfant}}</label>
                    <div class="col-sm-8">
                      <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="child_name" placeholder="{{vide = premier enfant du compte}}" />
                      <span class="help-block">{{Compte Parents avec plusieurs enfants : prénom ou nom de celui-ci. Les noms trouvés s'affichent dans l'erreur si celui-ci est inconnu.}}</span>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{URL Pronote}}</label>
                    <div class="col-sm-8">
                      <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="url" placeholder="https://xxxxxxx.index-education.net/pronote/parent.html" />
                    </div>
                  </div>

                  <div class="pronote-mode pronote-mode-ent pronote-mode-password">
                    <div class="form-group">
                      <label class="col-sm-4 control-label">{{Identifiant}}</label>
                      <div class="col-sm-8"><input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="username" /></div>
                    </div>
                    <div class="form-group">
                      <label class="col-sm-4 control-label">{{Mot de passe}}</label>
                      <div class="col-sm-8"><input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="password" /></div>
                    </div>
                  </div>
                  <div class="pronote-mode pronote-mode-ent">
                    <div class="form-group">
                      <label class="col-sm-4 control-label">{{ENT}}</label>
                      <div class="col-sm-8">
                        <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ent" placeholder="ac_orleans_tours" />
                        <span class="help-block">{{Nom de la fonction dans pronotepy.ent — la liste s'affiche dans l'erreur si le nom est inconnu.}}</span>
                      </div>
                    </div>
                  </div>

                  <div class="pronote-mode pronote-mode-qr">
                    <div class="pn-steps">
                      <div class="pn-step cur" id="pn_step1">
                        <span class="pn-num">1</span>
                        <div>
                          <b>{{Générer le QR Code dans Pronote}}</b>
                          <p>{{Mon compte › Autoriser un accès mobile. Choisir un code à 4 chiffres. Le QR Code et le code expirent au bout de 10 minutes.}}</p>
                        </div>
                      </div>
                      <div class="pn-step" id="pn_step2">
                        <span class="pn-num">2</span>
                        <div>
                          <b>{{Déposer l'image du QR Code}}</b>
                          <p>{{Capture d'écran ou photo. L'image est effacée dès qu'elle est lue : elle vaut un accès au compte.}}</p>
                          <div class="pn-row">
                            <input type="file" id="qr_image" accept="image/*" style="max-width:260px" />
                            <a class="btn btn-default btn-sm" id="bt_decodeQr"><i class="fas fa-camera"></i> {{Décoder}}</a>
                          </div>
                          <details><summary>{{Contenu décodé (ou à coller à la main)}}</summary>
                            <textarea class="form-control" id="qr_json" rows="3"></textarea>
                          </details>
                        </div>
                      </div>
                      <div class="pn-step" id="pn_step3">
                        <span class="pn-num">3</span>
                        <div>
                          <b>{{Code à 4 chiffres, puis enrôler}}</b>
                          <p>{{Jeedom obtient un jeton qu'il renouvelle seul ensuite. Rien à refaire, sauf si le jeton est révoqué.}}</p>
                          <div class="pn-row">
                            <input type="text" class="form-control pn-pin" id="qr_pin" maxlength="4" placeholder="••••" />
                            <a class="btn btn-primary btn-sm" id="bt_enroll"><i class="fas fa-qrcode"></i> {{Enrôler}}</a>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="form-group" style="margin-top:14px">
                    <label class="col-sm-4 control-label">{{Nom de l'appareil}}</label>
                    <div class="col-sm-8">
                      <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="device_name" maxlength="32" placeholder="Jeedom" />
                      <span class="help-block">{{Sous ce nom, Pronote enregistre Jeedom parmi les appareils autorisés.}}</span>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{PIN du compte (2FA)}}</label>
                    <div class="col-sm-8">
                      <input type="password" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="account_pin" maxlength="60" placeholder="{{vide si aucun}}" style="max-width:180px" autocomplete="new-password" />
                      <span class="help-block">{{Seulement si Pronote demande un code à chaque connexion. Différent du code du QR Code.}}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="pn-sec">
                <h4><i class="fas fa-sliders-h"></i> {{Général}}</h4>
                <div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Objet parent}}</label>
                    <div class="col-sm-8">
                      <select class="eqLogicAttr form-control" data-l1key="object_id">
                        <option value="">{{Aucun}}</option>
                        <?php foreach (jeeObject::all() as $object) { echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>'; } ?>
                      </select>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Catégorie}}</label>
                    <div class="col-sm-8">
                      <?php foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                          echo '<label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" /> ' . $value['name'] . '</label>';
                      } ?>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{État}}</label>
                    <div class="col-sm-8">
                      <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked /> {{Activer}}</label>
                      <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked /> {{Visible}}</label>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>

          <div class="col-lg-6">
            <form class="form-horizontal">
              <div class="pn-sec">
                <h4><i class="fas fa-database"></i> {{Données à récupérer}}</h4>
                <div>
                  <div class="pn-grid">
                    <?php
                    $icons = array('notes' => 'fa-star', 'devoirs' => 'fa-book', 'edt' => 'fa-calendar-alt', 'absences' => 'fa-user-clock',
                                   'vie' => 'fa-comment', 'punitions' => 'fa-gavel', 'cantine' => 'fa-utensils', 'competences' => 'fa-award');
                    $descr = array('notes' => 'Moyenne générale, moyenne de classe, dernière note, nouvelles notes',
                                   'devoirs' => 'À faire, pour demain, détail',
                                   'edt' => 'Journée, lendemain, semaine, prochain cours, annulations',
                                   'absences' => 'Heures manquées et retards de la période',
                                   'vie' => 'Messages non lus',
                                   'punitions' => 'Nombre sur la période',
                                   'cantine' => 'Menu du jour',
                                   'competences' => 'Évaluations par compétences');
                    foreach (pronote::dataBlocks() as $key => $label) { ?>
                      <label class="pn-opt">
                        <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="data_<?php echo $key; ?>" />
                        <b><i class="fas <?php echo $icons[$key]; ?>" style="opacity:.6;margin-right:5px"></i>{{<?php echo $label; ?>}}</b>
                        <small>{{<?php echo $descr[$key]; ?>}}</small>
                      </label>
                    <?php } ?>
                  </div>
                  <span class="help-block" style="margin-top:8px">{{Les commandes suivent ces cases : en décocher une supprime ses commandes (et leur historique) à la sauvegarde.}}</span>
                </div>
              </div>

              <div class="pn-sec">
                <h4><i class="fas fa-sync"></i> {{Synchronisation}}</h4>
                <div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Fréquence}}</label>
                    <div class="col-sm-8">
                      <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="frequency" style="max-width:220px">
                        <option value="15">{{Toutes les 15 minutes}}</option>
                        <option value="30">{{Toutes les 30 minutes}}</option>
                        <option value="60">{{Toutes les heures}}</option>
                        <option value="720">{{2 fois par jour}}</option>
                        <option value="0">{{Manuelle}}</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Horizon devoirs}}</label>
                    <div class="col-sm-8">
                      <div class="input-group" style="max-width:140px">
                        <input type="number" min="1" max="30" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="homework_days" />
                        <span class="input-group-addon">{{jours}}</span>
                      </div>
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="col-sm-4 control-label">{{Options}}</label>
                    <div class="col-sm-8 pn-toggles">
                      <label><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="per_subject" />
                        <span>{{Une commande de moyenne par matière}}<small>{{Créées à la première synchronisation, une par matière suivie.}}</small></span></label>
                      <label><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="skip_done" />
                        <span>{{Ignorer les devoirs cochés « fait » dans Pronote}}</span></label>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>

      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br>
        <div class="alert alert-info">
          {{Les commandes sont créées automatiquement à partir des cases « Données à récupérer ». En décocher une supprime ses commandes à la sauvegarde.}}
        </div>
        <table id="table_cmd" class="table table-bordered table-condensed">
          <thead>
            <tr>
              <th style="width:260px">{{Nom}}</th>
              <th style="width:200px">{{Identifiant logique}}</th>
              <th style="width:200px">{{Type}}</th>
              <th style="width:200px">{{Paramètres}}</th>
              <th style="width:80px">{{Actions}}</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include_file('desktop', 'pronote', 'js', 'pronote'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
