<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-clock"></i> {{Synchronisation}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Fréquence}}</label>
      <div class="col-sm-3">
        <select class="configKey form-control" data-l1key="frequency">
          <option value="15">{{Toutes les 15 minutes}}</option>
          <option value="30" selected>{{Toutes les 30 minutes}}</option>
          <option value="60">{{Toutes les heures}}</option>
          <option value="720">{{2 fois par jour}}</option>
          <option value="0">{{Manuelle}}</option>
        </select>
      </div>
      <div class="col-sm-5">
        <span class="help-block">{{Pour tous les élèves : Pronote compte les connexions de votre adresse IP, pas celles de chaque enfant. 30 minutes est un bon compromis.}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Plage horaire}}</label>
      <div class="col-sm-2">
        <input type="time" class="configKey form-control" data-l1key="sync_start" value="06:00" />
      </div>
      <div class="col-sm-2">
        <input type="time" class="configKey form-control" data-l1key="sync_end" value="20:00" />
      </div>
      <div class="col-sm-4">
        <span class="help-block">{{Hors de cette plage, aucune synchronisation n'est lancée.}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Délai entre deux appels}}</label>
      <div class="col-sm-2">
        <input type="number" min="0" max="60" class="configKey form-control" data-l1key="call_delay" value="5" />
      </div>
      <div class="col-sm-6">
        <span class="help-block">{{Secondes. Index Éducation limite les accès automatisés : un délai trop court expose à un blocage temporaire du compte.}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Délai maximal d'un appel}}</label>
      <div class="col-sm-2">
        <input type="number" min="30" max="600" class="configKey form-control" data-l1key="fetch_timeout" value="120" />
      </div>
      <div class="col-sm-6">
        <span class="help-block">{{Secondes. Au-delà, l'appel est interrompu et compté comme un échec — un Pronote qui ne répond plus ne bloque jamais le cron. Une synchronisation normale prend 1 à 2 s.}}</span>
      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{Alerte en cas d'échec d'authentification}}</label>
      <div class="col-sm-6">
        <input type="checkbox" class="configKey" data-l1key="alert_on_auth_error" checked />
        <span class="help-block">{{Un message dans le centre de messages Jeedom par erreur distincte, au plus un toutes les 6 h. Après un échec, les tentatives s'espacent (fréquence doublée à chaque échec, plafond 8 h).}}</span>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend><i class="fas fa-book"></i> {{Données}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Horizon des devoirs}}</label>
      <div class="col-sm-2">
        <div class="input-group">
          <input type="number" min="1" max="30" class="configKey form-control" data-l1key="homework_days" value="7" />
          <span class="input-group-addon">{{jours}}</span>
        </div>
      </div>
      <div class="col-sm-6"><span class="help-block">{{Devoirs remontés jusqu'à ce nombre de jours devant.}}</span></div>
    </div>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Options}}</label>
      <div class="col-sm-8">
        <label class="checkbox-inline" style="display:block;margin:0 0 6px"><input type="checkbox" class="configKey" data-l1key="per_subject" /> {{Une commande de moyenne par matière}} <span class="help-block" style="display:inline;margin-left:6px">{{(moyenne de l'élève, de la classe, dernière note — créées à la première synchronisation)}}</span></label>
        <label class="checkbox-inline" style="display:block;margin:0"><input type="checkbox" class="configKey" data-l1key="skip_done" /> {{Ignorer les devoirs cochés « fait » dans Pronote}}</label>
      </div>
    </div>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Nom de l'appareil}}</label>
      <div class="col-sm-3">
        <input type="text" class="configKey form-control" data-l1key="device_name" placeholder="Jeedom" maxlength="32" />
      </div>
      <div class="col-sm-5"><span class="help-block">{{Sous ce nom, Pronote enregistre Jeedom parmi les appareils autorisés du compte.}}</span></div>
    </div>
  </fieldset>

  <fieldset>
    <legend><i class="fas fa-umbrella-beach"></i> {{Pause pendant les vacances scolaires}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Activer la pause}}</label>
      <div class="col-sm-8">
        <input type="checkbox" class="configKey" data-l1key="suspend_holidays" />
        <span class="help-block">{{À quoi ça sert : pendant les vacances, Pronote n'a rien de nouveau à donner, mais chaque synchronisation reste une connexion comptée sur votre adresse IP — et Index Éducation suspend les adresses trop bavardes. La pause arrête d'interroger Pronote du premier au dernier jour des vacances de votre zone, puis reprend seule à la rentrée. Calendrier officiel du ministère, rafraîchi chaque semaine ; s'il est indisponible, rien n'est suspendu.}}</span>
      </div>
    </div>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Zone}}</label>
      <div class="col-sm-2">
        <select class="configKey form-control" data-l1key="holiday_zone">
          <option value="">{{—}}</option>
          <option value="A">{{Zone A}}</option>
          <option value="B">{{Zone B}}</option>
          <option value="C">{{Zone C}}</option>
        </select>
      </div>
      <div class="col-sm-6">
        <span class="help-block">
          <?php $nh = pronote::nextHoliday(); ?>
          <?php if (config::byKey('holiday_zone', 'pronote', '') === '') { ?>
            {{Zone A : Besançon, Bordeaux, Clermont-Ferrand, Dijon, Grenoble, Limoges, Lyon, Poitiers · Zone B : Aix-Marseille, Amiens, Lille, Nancy-Metz, Nantes, Nice, Normandie, Orléans-Tours, Reims, Rennes, Strasbourg · Zone C : Créteil, Montpellier, Paris, Toulouse, Versailles}}
          <?php } elseif ($nh) { ?>
            {{Prochaines vacances :}} <b><?php echo htmlspecialchars($nh[2]); ?></b>, <?php echo date('d/m', $nh[0]); ?> → <?php echo date('d/m', $nh[1]); ?>
          <?php } else { ?>
            {{Calendrier indisponible pour le moment.}}
          <?php } ?>
        </span>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend><i class="fas fa-desktop"></i> {{Affichage}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Widget personnalisé}}</label>
      <div class="col-sm-6">
        <input type="checkbox" class="configKey" data-l1key="custom_widget" checked />
        <span class="help-block">{{Coché : tuile compacte (moyenne, devoirs, absences, prochain cours). Décoché : liste brute de toutes les commandes.}}</span>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend><i class="fas fa-bug"></i> {{Journalisation}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Conserver les réponses brutes}}</label>
      <div class="col-sm-6">
        <input type="checkbox" class="configKey" data-l1key="debug_raw" />
        <span class="help-block">{{Écrit les réponses brutes de Pronote dans un log dédié « pronote_raw », visible quel que soit le niveau de log.}}</span>
      </div>
    </div>
  </fieldset>
</form>
