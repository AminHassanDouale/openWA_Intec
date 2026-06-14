# Third‑Party Integration Guide — Sending WhatsApp Notifications

This guide shows how an external application (your backend, a CRM, an e‑commerce
store, an n8n flow, etc.) can send WhatsApp notifications through your OpenWA
gateway and receive delivery/inbound events back.

- **Base URL:** `https://taxiconnect.online/api`
- **Auth:** every request needs the header `X-API-Key: <your-api-key>`
- **Interactive reference:** `https://taxiconnect.online/api/docs` (Swagger)

> Replace `https://taxiconnect.online` with your own host if you are self‑hosting
> elsewhere. All paths below are prefixed with `/api`.

---

## 1. Concepts

| Term | Meaning |
| --- | --- |
| **Session** | One linked WhatsApp account (the phone that sends the messages). You create it once and scan a QR code to link it. |
| **`sessionId`** | UUID of a session. Every messaging endpoint is scoped to a session. |
| **API key** | Bearer credential sent as `X-API-Key`. The admin key can do everything; you can also mint scoped keys from the dashboard. |
| **`chatId`** | The recipient address. Individual: `<countrycode><number>@c.us` (digits only, no `+`/spaces). Group: `<groupId>@g.us`. |
| **Webhook** | Your HTTPS endpoint that OpenWA calls when events happen (message received, delivered, read…). |

### chatId format

```
Phone  +1 (415) 555‑2671   ->  14155552671@c.us
Phone  +62 812‑3456‑789    ->  628123456789@c.us
Group  (from groups list)  ->  120363041234567890@g.us
```

The gateway validates and resolves the number before sending, so a number that
isn’t on WhatsApp returns a clear error instead of silently failing.

---

## 2. One‑time setup: link a WhatsApp account

You only do this once per sending account. It can be done entirely from the
**dashboard** (`https://taxiconnect.online`) — *Sessions → New → Start → scan the
QR with WhatsApp → Linked devices*. The equivalent API calls:

```bash
KEY="owa_k1_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
BASE="https://taxiconnect.online/api"

# 1) Create a session
curl -s -X POST "$BASE/sessions" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"name":"notifications"}'
# -> { "id": "7673e1fa-...", "name": "notifications", "status": "created", ... }

SID="7673e1fa-..."   # the id from above

# 2) Start it (launches the WhatsApp engine)
curl -s -X POST "$BASE/sessions/$SID/start" -H "X-API-Key: $KEY"

# 3) Fetch the QR code (a PNG data URL) and scan it with WhatsApp
curl -s "$BASE/sessions/$SID/qr" -H "X-API-Key: $KEY"
# -> { "qrCode": "data:image/png;base64,iVBOR..." }

# 4) Poll until connected
curl -s "$BASE/sessions/$SID" -H "X-API-Key: $KEY"
# -> status becomes "ready" once the phone is linked
```

List sessions / find your `sessionId` anytime:

```bash
curl -s "$BASE/sessions" -H "X-API-Key: $KEY"
```

> After linking, the session reconnects automatically across restarts — you do
> **not** scan again for normal operation.

---

## 3. Send a notification (the core call)

```
POST /api/sessions/{sessionId}/messages/send-text
```

```bash
curl -s -X POST "$BASE/sessions/$SID/messages/send-text" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "chatId": "14155552671@c.us",
    "text": "Your order #1234 has shipped 🚚"
  }'
```

Response:

```json
{ "messageId": "true_14155552671@c.us_3EB0...", "timestamp": 1706868000 }
```

| Field | Type | Notes |
| --- | --- | --- |
| `chatId` | string, required | Recipient (`@c.us` or `@g.us`). |
| `text` | string, required | Up to 4096 characters. |

Store the returned `messageId` if you want to correlate it with delivery/read
events from webhooks (section 6).

---

## 4. Media notifications

Images, documents (PDF invoices, tickets), video, and audio. Provide **either**
a public `url` **or** base64 `base64` (+ `mimetype`).

```
POST /api/sessions/{sessionId}/messages/send-image
POST /api/sessions/{sessionId}/messages/send-document
POST /api/sessions/{sessionId}/messages/send-video
POST /api/sessions/{sessionId}/messages/send-audio
```

```bash
# Image by URL with a caption
curl -s -X POST "$BASE/sessions/$SID/messages/send-image" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "chatId": "14155552671@c.us",
    "url": "https://example.com/receipt.png",
    "caption": "Here is your receipt"
  }'

# PDF document by URL
curl -s -X POST "$BASE/sessions/$SID/messages/send-document" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "chatId": "14155552671@c.us",
    "url": "https://example.com/invoice-1234.pdf",
    "filename": "invoice-1234.pdf"
  }'
```

| Field | Type | Notes |
| --- | --- | --- |
| `chatId` | string, required | Recipient. |
| `url` | string | Public http/https URL (mutually exclusive with `base64`). |
| `base64` | string | Base64 payload (requires `mimetype`). |
| `mimetype` | string | e.g. `image/jpeg`, `application/pdf` — required for `base64`. |
| `filename` | string | Shown to the recipient (documents). |
| `caption` | string | Up to 1024 chars (images/videos). |

---

## 5. Bulk / batch notifications

Send to many recipients in one request. Messages are queued and paced
automatically to reduce the risk of WhatsApp rate‑limiting your number.

```
POST /api/sessions/{sessionId}/messages/send-bulk
```

```bash
curl -s -X POST "$BASE/sessions/$SID/messages/send-bulk" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "messages": [
      { "chatId": "14155552671@c.us", "type": "text",
        "content": { "text": "Hi {{name}}, your appointment is tomorrow." },
        "variables": { "name": "Sara" } },
      { "chatId": "628123456789@c.us", "type": "image",
        "content": { "image": { "url": "https://example.com/flyer.jpg" }, "caption": "See you!" } }
    ],
    "options": { "delayBetweenMessages": 3000, "randomizeDelay": true, "stopOnError": false }
  }'
```

Response includes a `batchId` and a `statusUrl`:

```json
{ "batchId": "batch_abc", "status": "queued", "totalMessages": 2, "statusUrl": "/api/sessions/{sessionId}/messages/batch/batch_abc" }
```

Track progress / cancel:

```
GET  /api/sessions/{sessionId}/messages/batch/{batchId}
POST /api/sessions/{sessionId}/messages/batch/{batchId}/cancel
```

| Constraint | Value |
| --- | --- |
| Max messages per request | 100 |
| `type` | `text` \| `image` \| `video` \| `audio` \| `document` |
| `delayBetweenMessages` | 1000–60000 ms (default 3000) |
| `variables` | `{{key}}` placeholders substituted into `content.text`/captions |

---

## 6. Receiving events (webhooks)

Register an HTTPS endpoint to be notified of inbound messages and delivery/read
receipts — this is how you build two‑way flows and track notification status.

```
POST /api/sessions/{sessionId}/webhooks
```

```bash
curl -s -X POST "$BASE/sessions/$SID/webhooks" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "url": "https://your-app.com/openwa/webhook",
    "events": ["message.received", "message.ack", "session.status"],
    "secret": "a-long-random-string"
  }'
```

### Event types

| Event | When |
| --- | --- |
| `message.received` | An inbound message arrived. |
| `message.sent` | An outbound message was sent. |
| `message.ack` | Delivery/read status changed (sent → delivered → read). |
| `message.revoked` | A message was deleted for everyone. |
| `session.status` | Session status changed. |
| `session.qr` | A new QR code is available. |
| `session.authenticated` / `session.disconnected` | Link state changes. |
| `group.join` / `group.leave` / `group.update` | Group membership/metadata changes. |

### Delivery format

OpenWA `POST`s JSON to your URL:

```json
{
  "event": "message.received",
  "timestamp": "2026-06-14T06:30:00.000Z",
  "sessionId": "7673e1fa-...",
  "idempotencyKey": "a1b2c3d4e5f6",
  "deliveryId": "dlv_...",
  "data": { "...event-specific fields..." }
}
```

Request headers:

| Header | Purpose |
| --- | --- |
| `X-OpenWA-Event` | Event type. |
| `X-OpenWA-Delivery-Id` | Unique per delivery attempt target. |
| `X-OpenWA-Idempotency-Key` | Stable per logical event — **dedupe on this**. |
| `X-OpenWA-Retry-Count` | `0` on first attempt, increments on retries. |
| `X-OpenWA-Signature` | `sha256=<hmac>` — present when a `secret` is set. |

Respond with **HTTP 2xx** quickly (do heavy work async). Non‑2xx / timeouts are
retried with exponential backoff up to `retryCount` times (0–5, default 3).

### Verifying the signature (recommended)

The signature is `sha256=` + HMAC‑SHA256 of the **raw JSON body**, keyed with
your `secret`. Compare in constant time:

```js
// Express example — make sure you can read the RAW body
const crypto = require('crypto');

function verify(rawBody, headerSig, secret) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  const a = Buffer.from(headerSig || '');
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

app.post('/openwa/webhook',
  express.raw({ type: 'application/json' }),           // raw body for HMAC
  (req, res) => {
    const sig = req.header('X-OpenWA-Signature');
    if (!verify(req.body, sig, process.env.OPENWA_SECRET)) return res.sendStatus(401);
    const evt = JSON.parse(req.body.toString());
    // idempotency: skip if you've already processed evt.idempotencyKey
    res.sendStatus(200);                                // ack fast
    // ...process evt asynchronously...
  });
```

Manage webhooks:

```
GET    /api/sessions/{sessionId}/webhooks            # list
GET    /api/webhooks                                  # list across all sessions
PUT    /api/sessions/{sessionId}/webhooks/{id}        # update (url/events/secret/active)
POST   /api/sessions/{sessionId}/webhooks/{id}/test   # send a test event
DELETE /api/sessions/{sessionId}/webhooks/{id}        # remove
```

---

## 7. Helpers

Check whether a number is registered on WhatsApp before sending:

```
GET /api/sessions/{sessionId}/contacts/check/{number}
```

```bash
curl -s "$BASE/sessions/$SID/contacts/check/14155552671" -H "X-API-Key: $KEY"
```

Other useful reads: `GET /sessions/{id}/contacts`, `GET /sessions/{id}/groups`,
`GET /sessions/{id}/status` (session health), `GET /health` (gateway health).

---

## 8. Errors & rate limits

Errors use standard HTTP status codes with a JSON body:

```json
{ "statusCode": 400, "message": "Number is not registered on WhatsApp: 14155550000", "error": "Bad Request" }
```

| Status | Meaning / action |
| --- | --- |
| `400` | Bad input (e.g. unregistered number, bad `chatId`). Fix the request. |
| `401` | Missing/invalid `X-API-Key`. |
| `404` | Unknown session/resource. |
| `409` | Session not ready / already started — start it and wait for `ready`. |
| `429` | Rate limited — back off (see headers below). |
| `5xx` | Gateway/engine error — retry with backoff. |

Rate‑limit headers are returned on every response; default limits:

| Window | Limit |
| --- | --- |
| Short | 10 requests / second |
| Medium | 100 requests / minute |
| Long | 1000 requests / hour |

Read `X-RateLimit-Remaining` / `X-RateLimit-Reset` and throttle accordingly. For
large fan‑outs, prefer **send‑bulk** (section 5) which paces messages for you.

---

## 9. Code samples

### Node.js (fetch)

```js
const BASE = 'https://taxiconnect.online/api';
const KEY = process.env.OPENWA_KEY;
const SID = process.env.OPENWA_SESSION_ID;

async function notify(chatId, text) {
  const res = await fetch(`${BASE}/sessions/${SID}/messages/send-text`, {
    method: 'POST',
    headers: { 'X-API-Key': KEY, 'Content-Type': 'application/json' },
    body: JSON.stringify({ chatId, text }),
  });
  if (!res.ok) throw new Error(`OpenWA ${res.status}: ${await res.text()}`);
  return res.json(); // { messageId, timestamp }
}

notify('14155552671@c.us', 'Hello from our app!').then(console.log);
```

### PHP (cURL)

```php
<?php
$base = 'https://taxiconnect.online/api';
$key  = getenv('OPENWA_KEY');
$sid  = getenv('OPENWA_SESSION_ID');

$ch = curl_init("$base/sessions/$sid/messages/send-text");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => ["X-API-Key: $key", 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode([
    'chatId' => '14155552671@c.us',
    'text'   => 'Your code is 4821',
  ]),
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo $status === 200 ? "sent: $response" : "error ($status): $response";
```

### Python (requests)

```python
import os, requests

BASE = "https://taxiconnect.online/api"
KEY  = os.environ["OPENWA_KEY"]
SID  = os.environ["OPENWA_SESSION_ID"]

def notify(chat_id: str, text: str):
    r = requests.post(
        f"{BASE}/sessions/{SID}/messages/send-text",
        headers={"X-API-Key": KEY, "Content-Type": "application/json"},
        json={"chatId": chat_id, "text": text},
        timeout=30,
    )
    r.raise_for_status()
    return r.json()

print(notify("14155552671@c.us", "Payment received ✅"))
```

---

## 10. Production checklist

- **Keep the API key secret** — server‑side only; never ship it in a browser/mobile app. Mint per‑integration keys in the dashboard so you can revoke one without affecting others.
- **Always send over HTTPS** (already enforced on `taxiconnect.online`).
- **Verify webhook signatures** and **dedupe on `X-OpenWA-Idempotency-Key`**.
- **Normalize phone numbers** to digits + `@c.us` before sending; optionally pre‑check with the contacts/check endpoint.
- **Respect rate limits**; use `send-bulk` for campaigns and add jitter for large volumes.
- **Handle `409 not ready`** by checking `GET /sessions/{id}` for `status: "ready"` before sending, and alert if a session drops to `disconnected`.
- **One sender, one number** — WhatsApp links one phone per session; run multiple sessions for multiple numbers.

---

_See also: [API Specification](./06-api-specification.md) · [Security Design](./04-security-design.md) · [n8n Integration](./22-n8n-integration.md) · live Swagger at `/api/docs`._
