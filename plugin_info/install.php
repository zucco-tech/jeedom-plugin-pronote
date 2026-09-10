<?php
/* Hooks appelés par Jeedom lors de l'activation, la mise à jour et la suppression.
 *
 * PAS de garde isConnect() ici : Jeedom exécute ce fichier via jeePlugin.php en
 * ligne de commande, donc sans session. Un garde y lève « 401 Unauthorized » et
 * le hook ne tourne jamais — les valeurs par défaut n'étaient pas écrites.
 */

function pronote_install() {
    /* Les crons sont déclarés par les méthodes statiques cronXX() de la classe :
       Jeedom les découvre seul, il n'y a rien à créer ici. */
    config::save('sync_start', config::byKey('sync_start', 'pronote', '06:00'), 'pronote');
    config::save('sync_end', config::byKey('sync_end', 'pronote', '20:00'), 'pronote');
    config::save('call_delay', config::byKey('call_delay', 'pronote', 5), 'pronote');
    config::save('custom_widget', config::byKey('custom_widget', 'pronote', 1), 'pronote');
}

function pronote_update() {
    pronote_install();
    /* Une mise à jour peut apporter de nouvelles commandes : on les crée sur
       les équipements existants, et on chiffre les secrets encore en clair. */
    foreach (eqLogic::byType('pronote') as $eqLogic) {
        try {
            /* Réglages devenus globaux (fréquence, horizon, options, appareil) :
               la valeur du premier élève qui en a une devient celle du plugin,
               puis la surcharge par élève est effacée pour qu'un seul endroit
               fasse foi. */
            $changed = false;
            foreach (array_keys(pronote::PLUGIN_SETTINGS) as $key) {
                $own = $eqLogic->getConfiguration($key, null);
                if ($own !== null && $own !== '') {
                    if (config::byKey($key, 'pronote', '') === '') {
                        config::save($key, $own, 'pronote');
                    }
                    $eqLogic->setConfiguration($key, null);
                    $changed = true;
                }
            }
            $eqLogic->syncCommands();
            if ($eqLogic->encryptSecrets() || $changed) {
                $eqLogic->save(true);
            }
        } catch (Exception $e) {
            log::add('pronote', 'error', 'Mise à jour de ' . $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function pronote_remove() {
    /* Rien à nettoyer : les équipements et commandes sont supprimés par Jeedom. */
}
