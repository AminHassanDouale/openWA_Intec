<?php

/**
 * OpenWANotifier — drop-in WhatsApp notification helper for OpenWA.
 *
 * Copy this file into your app (e.g. app/Services/OpenWANotifier.php for
 * Laravel) and call one method per event. All messages are in French and use
 * {{placeholders}} filled from the data you pass.
 *
 * Usage:
 *   $wa = new OpenWANotifier(
 *       'https://taxiconnect.online/api',
 *       'owa_k1_xxxxxxxx',                       // your API key
 *       'e5c162c6-4a29-4a60-913e-902285e9d7f8'   // session id (the sending WhatsApp)
 *   );
 *   $wa->inscription('25377049495', ['nom' => 'Sara', 'formation' => 'Anglais B1', 'date_debut' => '20/07/2026']);
 *   $wa->paiementRecu('25377049495', ['nom' => 'Sara', 'montant' => '15000', 'motif' => 'Mensualité Juillet', 'recu' => 'R-1042']);
 */
class OpenWANotifier
{
    private string $baseUrl;
    private string $apiKey;
    private string $sessionId;

    public function __construct(string $baseUrl, string $apiKey, string $sessionId)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->sessionId = $sessionId;
    }

    // ---------------------------------------------------------------------
    // Event helpers — one per notification type. `$phone` may be any format;
    // it is normalised to digits. `$data` fills the template placeholders.
    // ---------------------------------------------------------------------

    /** Inscription confirmée */
    public function inscription(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour {{nom}} 👋\n\nVotre inscription à *{{formation}}* est confirmée ✅\n"
            . "📅 Début des cours : {{date_debut}}\n\nBienvenue chez INTEC !",
            $data);
    }

    /** Paiement reçu */
    public function paiementRecu(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour {{nom}},\n\nNous confirmons la réception de votre paiement de *{{montant}} FDJ* "
            . "pour {{motif}} ✅\nReçu N° {{recu}}\n\nMerci !",
            $data);
    }

    /** Rappel de paiement (échéance à venir) */
    public function rappelPaiement(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour {{nom}},\n\n⏰ Rappel : un paiement de *{{montant}} FDJ* pour {{motif}} "
            . "est attendu avant le *{{echeance}}*.\n\nMerci de régulariser votre situation.",
            $data);
    }

    /** Rappel de cours (avant une séance) */
    public function rappelCours(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "📚 Rappel de cours\n\nBonjour {{nom}}, votre cours *{{cours}}* a lieu :\n"
            . "🗓️ {{jour}} à {{heure}}\n📍 Salle {{salle}}\n\nÀ bientôt !",
            $data);
    }

    /** Emploi du temps / planning */
    public function planning(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour {{nom}} 📅\n\nVoici votre emploi du temps pour *{{periode}}* :\n\n{{details}}\n\n"
            . "Bonne semaine !",
            $data);
    }

    /** Changement d'horaire / de salle */
    public function changementHoraire(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "⚠️ Changement d'horaire\n\nBonjour {{nom}}, le cours *{{cours}}* du {{date}} "
            . "est déplacé à *{{nouvelle_heure}}* (salle {{salle}}).\n\nMerci de votre compréhension.",
            $data);
    }

    /** Absence signalée */
    public function absence(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour,\n\n{{nom}} a été noté(e) *absent(e)* au cours {{cours}} du {{date}}.\n"
            . "Merci de justifier cette absence.",
            $data);
    }

    /** Résultats / notes disponibles */
    public function resultats(string $phone, array $data): array
    {
        return $this->sendTemplate($phone,
            "Bonjour {{nom}} 🎓\n\nVos résultats pour *{{examen}}* sont disponibles.\n"
            . "Note : *{{note}}/20*\n{{lien}}",
            $data);
    }

    /** Notification / annonce générale */
    public function annonce(string $phone, array $data): array
    {
        return $this->sendTemplate($phone, "📢 {{titre}}\n\n{{message}}", $data);
    }

    // ---------------------------------------------------------------------
    // Core
    // ---------------------------------------------------------------------

    /** Fill a template with {{key}} values then send. */
    public function sendTemplate(string $phone, string $template, array $data): array
    {
        $text = $template;
        foreach ($data as $key => $value) {
            $text = str_replace('{{' . $key . '}}', (string) $value, $text);
        }
        return $this->sendText($phone, $text);
    }

    /** Send a raw text message. Returns ['ok' => bool, 'status' => int, 'body' => array]. */
    public function sendText(string $phone, string $text): array
    {
        $chatId = $this->normalize($phone);
        $url = "{$this->baseUrl}/sessions/{$this->sessionId}/messages/send-text";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-API-Key: ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode(['chatId' => $chatId, 'text' => $text]),
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $body = json_decode((string) $raw, true) ?: ['raw' => $raw, 'curlError' => $err];
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }

    /** Normalise a phone number to `<digits>@c.us`. Accepts +, spaces, dashes. */
    public function normalize(string $phone): string
    {
        if (str_contains($phone, '@')) {
            return $phone; // already a chatId (e.g. group @g.us)
        }
        $digits = preg_replace('/\D+/', '', $phone);
        return $digits . '@c.us';
    }
}
