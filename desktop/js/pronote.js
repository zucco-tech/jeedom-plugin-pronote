/* Plugin Pronote — interface équipement.
 *
 * Écrit pour l'API Jeedom 4.6 : DOM natif, helpers jeeValue() / setJeeValues()
 * de core/dom/dom.utils.js et alertes via jeedomUtils.showAlert().
 * Ne pas revenir aux appels jQuery ni à jeedom.cmd.addAttribute(), qui
 * n'existent plus : l'exception coupait le chargement de l'équipement et la
 * sauvegarde ne prenait plus.
 */

function pronoteAlert(message, level) {
  if (typeof jeedomUtils !== 'undefined' && typeof jeedomUtils.showAlert === 'function') {
    jeedomUtils.showAlert({ message: message, level: level || 'info' })
  } else {
    console.log('[pronote] ' + message)
  }
}

function pronoteEqLogicId() {
  const field = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return field ? field.jeeValue() : ''
}

/* N'affiche que les champs du mode de connexion choisi. */
function pronoteApplyMode() {
  const select = document.getElementById('sel_mode')
  if (!select) return
  const mode = select.jeeValue ? select.jeeValue() : select.value
  document.querySelectorAll('.pronote-mode').forEach(function(el) {
    el.style.display = 'none'
  })
  document.querySelectorAll('.pronote-mode-' + mode).forEach(function(el) {
    el.style.display = ''
  })
}

function pronoteHue(name) {
  let h = 0
  const n = String(name || '').toLowerCase()
  for (let i = 0; i < n.length; i++) h = (h * 31 + n.charCodeAt(i)) % 360
  return h
}
function pronoteInitials(name) {
  return String(name || '').trim().split(/[\s-]+/).filter(Boolean).slice(0, 2).map(function (p) { return p[0].toUpperCase() }).join('') || '?'
}

/* Fiche élève : avatar, classe, jeton, dernière synchro, alerte. */
function pronoteRefreshToken(_eqLogic) {
  const conf = (_eqLogic && _eqLogic.configuration) || {}
  const cache = (_eqLogic && _eqLogic.cache) || {}
  const name = (_eqLogic && _eqLogic.name) || ''
  const av = document.getElementById('pn_avatar')
  if (av) { av.textContent = pronoteInitials(name); av.style.setProperty('--h', pronoteHue(name)) }
  const sub = document.getElementById('pn_sub')
  if (sub) {
    const parts = [conf.student_class, conf.establishment].filter(Boolean)
    sub.textContent = parts.length ? parts.join(' · ') : '{{Classe et établissement remontés à la première synchronisation}}'
  }
  const hasToken = !!conf.credentials
  const badge = document.getElementById('span_tokenState')
  if (badge) {
    badge.classList.remove('ok', 'warn', 'bad')
    badge.classList.add(hasToken ? 'ok' : 'warn')
    badge.innerHTML = '<i class="fas fa-key"></i> ' + (hasToken ? '{{jeton enregistré}}' : '{{aucun jeton — enrôler}}')
  }
  const last = document.getElementById('span_lastSync')
  if (last) {
    const ts = parseInt(cache.lastSync || 0, 10)
    last.textContent = ts > 0 ? '{{synchro}} ' + new Date(ts * 1000).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '{{jamais synchronisé}}'
  }
  const alert = document.getElementById('pn_alert')
  if (alert) {
    const err = cache.lastError || ''
    alert.textContent = err ? '⚠ ' + err : ''
    alert.classList.toggle('on', !!err)
  }
  pronoteSteps(hasToken ? 3 : 1)
}

/* Enrôlement : étape courante (1 générer, 2 décoder, 3 enrôler ; 4 = fini). */
function pronoteSteps(cur) {
  for (let i = 1; i <= 3; i++) {
    const el = document.getElementById('pn_step' + i)
    if (!el) continue
    el.classList.toggle('done', i < cur)
    el.classList.toggle('cur', i === cur)
  }
}

/* Appel d'un endpoint du plugin. `body` est un FormData. */
function pronoteAjax(body, onSuccess) {
  fetch('plugins/pronote/core/ajax/pronote.ajax.php', {
    method: 'POST',
    body: body,
    credentials: 'same-origin'
  }).then(function(response) {
    return response.text()
  }).then(function(text) {
    let data
    try {
      data = JSON.parse(text)
    } catch (e) {
      pronoteAlert('{{Réponse illisible du serveur : }}' + text.substring(0, 200), 'danger')
      return
    }
    if (data.state !== 'ok') {
      pronoteAlert(data.result, 'danger')
      return
    }
    onSuccess(data.result)
  }).catch(function(error) {
    pronoteAlert('{{Appel impossible : }}' + error.message, 'danger')
  })
}

function pronoteForm(action, extra) {
  const body = new FormData()
  body.append('action', action)
  if (extra) {
    Object.keys(extra).forEach(function(key) { body.append(key, extra[key]) })
  }
  return body
}

document.addEventListener('click', function(event) {
  const target = event.target.closest('#bt_decodeQr, #bt_enroll, #bt_testConnection, #bt_syncNow, #bt_selftest')
  if (!target) return
  event.preventDefault()
  const id = pronoteEqLogicId()

  if (target.id === 'bt_decodeQr') {
    const input = document.getElementById('qr_image')
    if (!input || !input.files || input.files.length === 0) {
      pronoteAlert('{{Choisir une image du QR Code avant de décoder.}}', 'warning')
      return
    }
    const body = pronoteForm('decode_qr')
    body.append('image', input.files[0])
    pronoteAjax(body, function(result) {
      const field = document.getElementById('qr_json')
      if (field) field.value = JSON.stringify(result)
      pronoteSteps(3)
      const pin = document.getElementById('qr_pin'); if (pin) pin.focus()
      pronoteAlert('{{QR Code décodé. Saisir le code à 4 chiffres puis cliquer sur Enrôler.}}', 'success')
    })
    return
  }

  if (!id) {
    pronoteAlert('{{Sauvegarder cet élève avant de lancer cette action.}}', 'warning')
    return
  }

  if (target.id === 'bt_enroll') {
    const json = document.getElementById('qr_json')
    const pin = document.getElementById('qr_pin')
    if (!json || json.value.trim() === '') {
      pronoteAlert('{{Décoder une image de QR Code, ou coller son contenu.}}', 'warning')
      return
    }
    pronoteAjax(pronoteForm('enroll', { id: id, qr: json.value, pin: pin ? pin.value : '' }), function() {
      json.value = ''
      if (pin) pin.value = ''
      const badge = document.getElementById('span_tokenState')
      if (badge) {
        badge.classList.remove('warn', 'bad'); badge.classList.add('ok')
        badge.innerHTML = '<i class="fas fa-key"></i> {{jeton enregistré}}'
      }
      pronoteSteps(4)
      pronoteAlert('{{Enrôlement réussi : le jeton est enregistré. Lancer une synchronisation.}}', 'success')
    })
    return
  }

  if (target.id === 'bt_testConnection' || target.id === 'bt_syncNow') {
    pronoteAlert('{{Synchronisation en cours…}}', 'info')
    pronoteAjax(pronoteForm('sync', { id: id }), function(result) {
      const span = document.getElementById('span_lastSync')
      if (span && result.data) span.textContent = result.data.last_sync || ''
      pronoteAlert('{{Connexion Pronote réussie.}}', 'success')
    })
    return
  }

  if (target.id === 'bt_selftest') {
    pronoteAjax(pronoteForm('selftest', { id: id }), function(result) {
      const span = document.getElementById('span_lastSync')
      if (span && result.data) span.textContent = result.data.last_sync || ''
      pronoteAlert('{{Jeu d\'essai appliqué : les commandes ont été remplies avec des valeurs fictives.}}', 'success')
    })
  }
})

function pronoteApplyAccount() {
  const select = document.getElementById('sel_account')
  if (!select) return
  const account = select.jeeValue ? select.jeeValue() : select.value
  document.querySelectorAll('.pronote-account').forEach(function(el) { el.style.display = 'none' })
  document.querySelectorAll('.pronote-account-' + account).forEach(function(el) { el.style.display = '' })
}
document.addEventListener('change', function(event) {
  if (event.target && event.target.id === 'sel_mode') pronoteApplyMode()
  if (event.target && event.target.id === 'sel_account') pronoteApplyAccount()
})
document.addEventListener('input', function(event) {
  const t = event.target
  if (t && t.classList.contains('pn-name-input')) {
    const av = document.getElementById('pn_avatar')
    if (av) { av.textContent = pronoteInitials(t.value); av.style.setProperty('--h', pronoteHue(t.value)) }
  }
})

/* Appelé par Jeedom après le chargement d'un équipement. */
function printEqLogic(_eqLogic) {
  pronoteApplyMode()
  pronoteApplyAccount()
  pronoteRefreshToken(_eqLogic)
}

/* Rendu d'une ligne du tableau des commandes (5 colonnes, cf. le thead). */
function addCmdToTable(_cmd) {
  if (!isset(_cmd)) { _cmd = { configuration: {} } }
  if (!isset(_cmd.configuration)) { _cmd.configuration = {} }
  const tbody = document.querySelector('#table_cmd tbody')
  if (!tbody) return

  let html = ''
  html += '<td>'
  html += '<input class="cmdAttr form-control input-sm" data-l1key="id" style="display:none;">'
  html += '<input class="cmdAttr form-control input-sm" data-l1key="name">'
  html += '</td>'
  html += '<td><span class="cmdAttr label label-default" data-l1key="logicalId"></span></td>'
  html += '<td>'
  html += '<span class="cmdAttr" data-l1key="type"></span> / '
  html += '<span class="cmdAttr" data-l1key="subType"></span> '
  html += '<span class="cmdAttr text-muted" data-l1key="unite"></span>'
  html += '</td>'
  html += '<td>'
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized"> {{Historiser}}</label>'
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible"> {{Afficher}}</label>'
  html += '</td>'
  html += '<td>'
  html += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
  html += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i></a> '
  html += '<i class="fas fa-minus-circle pull-right cmdAction cursor" data-action="remove"></i>'
  html += '</td>'

  const newRow = document.createElement('tr')
  newRow.innerHTML = html
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  tbody.appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
}
