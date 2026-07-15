/**
 * OpenWANotifier — drop-in WhatsApp notification helper for OpenWA (Node.js).
 *
 * Copy into your app and call one method per event. French templates, filled
 * from the data you pass. Works on Node 18+ (uses global fetch).
 *
 *   const { OpenWANotifier } = require('./openwa-notifier');
 *   const wa = new OpenWANotifier({
 *     baseUrl: 'https://taxiconnect.online/api',
 *     apiKey: process.env.OPENWA_KEY,
 *     sessionId: 'e5c162c6-4a29-4a60-913e-902285e9d7f8',
 *   });
 *   await wa.inscription('25377049495', { nom: 'Sara', formation: 'Anglais B1', date_debut: '20/07/2026' });
 */

const TEMPLATES = {
  inscription:
    'Bonjour {{nom}} 👋\n\nVotre inscription à *{{formation}}* est confirmée ✅\n📅 Début des cours : {{date_debut}}\n\nBienvenue chez INTEC !',
  paiementRecu:
    'Bonjour {{nom}},\n\nNous confirmons la réception de votre paiement de *{{montant}} FDJ* pour {{motif}} ✅\nReçu N° {{recu}}\n\nMerci !',
  rappelPaiement:
    'Bonjour {{nom}},\n\n⏰ Rappel : un paiement de *{{montant}} FDJ* pour {{motif}} est attendu avant le *{{echeance}}*.\n\nMerci de régulariser votre situation.',
  rappelCours:
    '📚 Rappel de cours\n\nBonjour {{nom}}, votre cours *{{cours}}* a lieu :\n🗓️ {{jour}} à {{heure}}\n📍 Salle {{salle}}\n\nÀ bientôt !',
  planning:
    'Bonjour {{nom}} 📅\n\nVoici votre emploi du temps pour *{{periode}}* :\n\n{{details}}\n\nBonne semaine !',
  changementHoraire:
    "⚠️ Changement d'horaire\n\nBonjour {{nom}}, le cours *{{cours}}* du {{date}} est déplacé à *{{nouvelle_heure}}* (salle {{salle}}).\n\nMerci de votre compréhension.",
  absence:
    'Bonjour,\n\n{{nom}} a été noté(e) *absent(e)* au cours {{cours}} du {{date}}.\nMerci de justifier cette absence.',
  resultats:
    'Bonjour {{nom}} 🎓\n\nVos résultats pour *{{examen}}* sont disponibles.\nNote : *{{note}}/20*\n{{lien}}',
  annonce: '📢 {{titre}}\n\n{{message}}',
};

class OpenWANotifier {
  constructor({ baseUrl, apiKey, sessionId }) {
    this.baseUrl = baseUrl.replace(/\/$/, '');
    this.apiKey = apiKey;
    this.sessionId = sessionId;
  }

  // One method per event ----------------------------------------------------
  inscription(phone, data) { return this.sendTemplate(phone, TEMPLATES.inscription, data); }
  paiementRecu(phone, data) { return this.sendTemplate(phone, TEMPLATES.paiementRecu, data); }
  rappelPaiement(phone, data) { return this.sendTemplate(phone, TEMPLATES.rappelPaiement, data); }
  rappelCours(phone, data) { return this.sendTemplate(phone, TEMPLATES.rappelCours, data); }
  planning(phone, data) { return this.sendTemplate(phone, TEMPLATES.planning, data); }
  changementHoraire(phone, data) { return this.sendTemplate(phone, TEMPLATES.changementHoraire, data); }
  absence(phone, data) { return this.sendTemplate(phone, TEMPLATES.absence, data); }
  resultats(phone, data) { return this.sendTemplate(phone, TEMPLATES.resultats, data); }
  annonce(phone, data) { return this.sendTemplate(phone, TEMPLATES.annonce, data); }

  // Core --------------------------------------------------------------------
  sendTemplate(phone, template, data = {}) {
    const text = Object.entries(data).reduce(
      (acc, [k, v]) => acc.replaceAll(`{{${k}}}`, String(v)),
      template,
    );
    return this.sendText(phone, text);
  }

  async sendText(phone, text) {
    const chatId = this.normalize(phone);
    const res = await fetch(`${this.baseUrl}/sessions/${this.sessionId}/messages/send-text`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-API-Key': this.apiKey },
      body: JSON.stringify({ chatId, text }),
    });
    const body = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, body };
  }

  normalize(phone) {
    if (String(phone).includes('@')) return phone; // already a chatId
    return String(phone).replace(/\D+/g, '') + '@c.us';
  }
}

module.exports = { OpenWANotifier, TEMPLATES };
