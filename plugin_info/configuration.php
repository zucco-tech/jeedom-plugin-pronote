<?php
if (!isConnect('admin')) {
    throw new Exception('401 Unauthorized');
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-clock"></i> {{Synchronisation}}</legend>

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
    <legend><i class="fas fa-umbrella-beach"></i> {{Vacances scolaires}}</legend>
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Suspendre pendant les vacances}}</label>
      <div class="col-sm-6">
        <input type="checkbox" class="configKey" data-l1key="suspend_holidays" />
        <span class="help-block">{{Calendrier officiel (data.education.gouv.fr), rafraîchi chaque semaine. Si le calendrier est indisponible, la synchronisation n'est pas suspendue.}}</span>
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
