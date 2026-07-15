# WhatsApp Notifications Toolkit

Drop-in helpers to send WhatsApp notifications from your app through OpenWA, with
ready-made **French** templates for common school/training events (inscription,
paiement, rappel de cours, planning, horaires, absences, résultats…).

- **PHP:** [`OpenWANotifier.php`](./OpenWANotifier.php)
- **Node.js:** [`openwa-notifier.js`](./openwa-notifier.js)

Both call the same gateway endpoint and normalise phone numbers to `<digits>@c.us`
automatically. Copy the file for your stack into your app.

---

## Setup

You need three values:

| Value | Where to get it |
| --- | --- |
| **baseUrl** | `https://taxiconnect.online/api` |
| **apiKey** | your admin key (or a per-integration key from the dashboard → Auth → API Keys) |
| **sessionId** | the WhatsApp account you send *from*, e.g. `e5c162c6-4a29-4a60-913e-902285e9d7f8` (intec-int / ScolApp) |

> Send from an **established** WhatsApp number. Brand-new numbers get rate-limited
> by WhatsApp when messaging people who haven't contacted them first.

---

## Event catalogue

| Method | When to call it | Placeholders (`data`) |
| --- | --- | --- |
| `inscription` | A student's registration is confirmed | `nom`, `formation`, `date_debut` |
| `paiementRecu` | A payment is recorded | `nom`, `montant`, `motif`, `recu` |
| `rappelPaiement` | A due date is approaching | `nom`, `montant`, `motif`, `echeance` |
| `rappelCours` | Before a class (e.g. day-before / hours-before) | `nom`, `cours`, `jour`, `heure`, `salle` |
| `planning` | Weekly/period schedule is published | `nom`, `periode`, `details` |
| `changementHoraire` | A class time/room changes | `nom`, `cours`, `date`, `nouvelle_heure`, `salle` |
| `absence` | A student is marked absent | `nom`, `cours`, `date` |
| `resultats` | Grades/results are available | `nom`, `examen`, `note`, `lien` |
| `annonce` | Any general announcement | `titre`, `message` |

Need a different message? Use `sendTemplate($phone, "…{{x}}…", ['x' => …])` or the
raw `sendText($phone, "…")`.

---

## PHP (Laravel or plain PHP)

```php
require 'OpenWANotifier.php';

$wa = new OpenWANotifier(
    'https://taxiconnect.online/api',
    env('OPENWA_KEY'),
    env('OPENWA_SESSION_ID')
);

// On registration confirmed:
$wa->inscription($student->phone, [
    'nom'        => $student->name,
    'formation'  => $course->title,
    'date_debut' => $course->starts_at->format('d/m/Y'),
]);

// On payment recorded:
$wa->paiementRecu($student->phone, [
    'nom'     => $student->name,
    'montant' => number_format($payment->amount, 0, ',', ' '),
    'motif'   => $payment->label,
    'recu'    => $payment->reference,
]);
```

### Laravel — event/listener wiring (recommended)

Fire notifications from your domain events so the app code stays clean:

```php
// app/Listeners/SendInscriptionWhatsApp.php
public function handle(StudentRegistered $event): void
{
    app(OpenWANotifier::class)->inscription($event->student->phone, [
        'nom'        => $event->student->name,
        'formation'  => $event->course->title,
        'date_debut' => $event->course->starts_at->format('d/m/Y'),
    ]);
}
```

Register `OpenWANotifier` as a singleton in a service provider:

```php
$this->app->singleton(OpenWANotifier::class, fn () => new OpenWANotifier(
    config('services.openwa.url'),
    config('services.openwa.key'),
    config('services.openwa.session'),
));
```

For reminders (rappel de cours, rappel de paiement), call the matching method
from a **scheduled job** (`php artisan schedule`), e.g. every morning select
tomorrow's classes / due payments and loop over the students.

---

## Node.js

```js
const { OpenWANotifier } = require('./openwa-notifier');

const wa = new OpenWANotifier({
  baseUrl: 'https://taxiconnect.online/api',
  apiKey: process.env.OPENWA_KEY,
  sessionId: process.env.OPENWA_SESSION_ID,
});

await wa.rappelCours('25377049495', {
  nom: 'Sara', cours: 'Anglais B1', jour: 'lundi', heure: '17h00', salle: 'B2',
});
```

---

## Return value & error handling

Every method returns `{ ok, status, body }`:

```php
$res = $wa->paiementRecu($phone, $data);
if (!$res['ok']) {
    Log::warning('WhatsApp notify failed', $res);
    // queue a retry, or fall back to SMS/email
}
```

- `ok = true`, `body.messageId` present → accepted by the gateway.
- `status = 409` / `"not active"` → the session isn't connected; start it (dashboard or `POST /sessions/{id}/start`).
- `status = 400` `"not registered on WhatsApp"` → the number has no WhatsApp account.
- `status = 429` → you're sending too fast; space messages out or use the bulk endpoint.

> Note: for some LID-addressed contacts WhatsApp returns a synthetic
> `messageId` (`sent_…`). The message is delivered but that id can't be used for
> later delivery/read tracking.

---

## Sending to many recipients

For campaigns (e.g. a planning to a whole class), prefer the gateway's paced
**bulk** endpoint over a tight loop — see
[`/INTEGRATION.md`](../../INTEGRATION.md#5-bulk-notifications). It spaces
messages automatically to reduce the risk of WhatsApp throttling your number.
