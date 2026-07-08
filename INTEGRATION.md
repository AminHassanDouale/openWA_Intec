# OpenWA — WhatsApp Notification Integration Guide

Send WhatsApp notifications from any application (backend, CRM, e‑commerce,
n8n, etc.) through this gateway, and receive delivery / inbound events back.

- **Base URL:** `https://taxiconnect.online/api`
- **Auth:** every request needs the header `X-API-Key: <your-api-key>`
- **Interactive API reference (Swagger):** `https://taxiconnect.online/api/docs`

> Ask the gateway administrator for an **API key** (server‑side use only) and the
> **`sessionId`** of the WhatsApp number you should send from.

---

## Contents

1. [Concepts](#1-concepts)
2. [Quick start](#2-quick-start)
3. [Send a text notification](#3-send-a-text-notification)
4. [Send media (image / document / video / audio)](#4-send-media)
5. [Bulk notifications](#5-bulk-notifications)
6. [Receive events (webhooks)](#6-receive-events-webhooks)
7. [Helpers](#7-helpers)
8. [Errors & rate limits](#8-errors--rate-limits)
9. [Code samples](#9-code-samples)
10. [Production checklist](#10-production-checklist)

---

## 1. Concepts

| Term | Meaning |
| --- | --- |
| **Session** | One linked WhatsApp account (the phone that sends messages). Created once, linked by scanning a QR code. |
| **`sessionId`** | UUID of a session. Every messaging endpoint is scoped to it. |
| **API key** | Credential sent as the `X-API-Key` header. Keep it server‑side only. |
| **`chatId`** | Recipient address. Individual: `<countrycode><number>@c.us` (digits only). Group: `<groupId>@g.us`. |
| **Webhook** | Your HTTPS endpoint that OpenWA calls when events happen. |

### chatId format

```
Phone  +1 (415) 555‑2671   ->  14155552671@c.us
Phone  +62 812‑3456‑789    ->  628123456789@c.us
Phone  +253 77 12 34 56    ->  25377123456@c.us
Group  (from groups list)  ->  120363041234567890@g.us
```

Strip `+`, spaces, and dashes; keep only digits and append `@c.us`. The gateway
validates the number before sending, so an unregistered number returns a clear
error rather than failing silently.

---

## 2. Quick start

```bash
KEY="owa_k1_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"     # your API key
BASE="https://taxiconnect.online/api"
SID="7673e1fa-..."                                # your session id

# Send your first notification
curl -s -X POST "$BASE/sessions/$SID/messages/send-text" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{ "chatId": "14155552671@c.us", "text": "Hello from OpenWA!" }'
```

Don’t have a `sessionId` yet? List sessions:

```bash
curl -s "$BASE/sessions" -H "X-API-Key: $KEY"
```

<details>
<summary>Linking a new WhatsApp number (one‑time, admin task)</summary>

Easiest via the dashboard (`https://taxiconnect.online`): **Sessions → New →
Start → scan the QR with WhatsApp → Linked devices**. Or via API:

```bash
curl -s -X POST "$BASE/sessions" -H "X-API-Key: $KEY" \
  -H "Content-Type: application/json" -d '{"name":"notifications"}'      # -> { "id": "<sessionId>", ... }
curl -s -X POST "$BASE/sessions/<sessionId>/start" -H "X-API-Key: $KEY"  # launch engine
curl -s "$BASE/sessions/<sessionId>/qr" -H "X-API-Key: $KEY"            # -> { "qrCode": "data:image/png;base64,..." } scan it
curl -s "$BASE/sessions/<sessionId>" -H "X-API-Key: $KEY"              # status -> "ready" when linked
```

The session reconnects automatically after restarts — no re‑scan needed for
normal operation.
</details>

---

## 3. Send a text notification

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

Keep `messageId` to correlate later delivery/read events from webhooks.

---

## 4. Send media

Provide **either** a public `url` **or** base64 `base64` (+ `mimetype`).

```
POST /api/sessions/{sessionId}/messages/send-image
POST /api/sessions/{sessionId}/messages/send-document
POST /api/sessions/{sessionId}/messages/send-video
POST /api/sessions/{sessionId}/messages/send-audio
```

```bash
# Image by URL, with caption
curl -s -X POST "$BASE/sessions/$SID/messages/send-image" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{ "chatId":"14155552671@c.us", "url":"https://example.com/receipt.png", "caption":"Your receipt" }'

# PDF invoice by URL
curl -s -X POST "$BASE/sessions/$SID/messages/send-document" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{ "chatId":"14155552671@c.us", "url":"https://example.com/invoice-1234.pdf", "filename":"invoice-1234.pdf" }'
```

| Field | Type | Notes |
| --- | --- | --- |
| `chatId` | string, required | Recipient. |
| `url` | string | Public http/https URL (exclusive with `base64`). |
| `base64` | string | Base64 payload (requires `mimetype`). |
| `mimetype` | string | e.g. `image/jpeg`, `application/pdf` — required for `base64`. |
| `filename` | string | Shown to recipient (documents). |
| `caption` | string | Up to 1024 chars (images/videos). |

---

## 5. Bulk notifications

Send to many recipients in one request. Messages are queued and paced
automatically to reduce the risk of WhatsApp throttling your number.

```
POST /api/sessions/{sessionId}/messages/send-bulk
```

```bash
curl -s -X POST "$BASE/sessions/$SID/messages/send-bulk" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{
    "messages": [
      { "chatId":"14155552671@c.us", "type":"text",
        "content": { "text":"Hi {{name}}, your appointment is tomorrow." },
        "variables": { "name":"Sara" } },
      { "chatId":"628123456789@c.us", "type":"image",
        "content": { "image": { "url":"https://example.com/flyer.jpg" }, "caption":"See you!" } }
    ],
    "options": { "delayBetweenMessages": 3000, "randomizeDelay": true, "stopOnError": false }
  }'
```

Response returns a `batchId` + `statusUrl`. Track / cancel:

```
GET  /api/sessions/{sessionId}/messages/batch/{batchId}
POST /api/sessions/{sessionId}/messages/batch/{batchId}/cancel
```

| Constraint | Value |
| --- | --- |
| Max messages / request | 100 |
| `type` | `text` \| `image` \| `video` \| `audio` \| `document` |
| `delayBetweenMessages` | 1000–60000 ms (default 3000) |
| `variables` | `{{key}}` placeholders substituted into text/captions |

---

## 6. Receive events (webhooks)

Register an HTTPS endpoint to receive inbound messages and delivery/read
receipts.

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
| `X-OpenWA-Delivery-Id` | Unique per delivery. |
| `X-OpenWA-Idempotency-Key` | Stable per logical event — **dedupe on this**. |
| `X-OpenWA-Retry-Count` | `0` on first attempt, increments on retries. |
| `X-OpenWA-Signature` | `sha256=<hmac>` — present when a `secret` is set. |

Respond **HTTP 2xx** quickly (do heavy work async). Non‑2xx / timeouts are
retried with exponential backoff up to `retryCount` times (0–5, default 3).

### Verify the signature (recommended)

`X-OpenWA-Signature` = `sha256=` + HMAC‑SHA256 of the **raw JSON body**, keyed
with your `secret`. Compare in constant time:

```js
const crypto = require('crypto');

function verify(rawBody, headerSig, secret) {
  const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(rawBody).digest('hex');
  const a = Buffer.from(headerSig || ''), b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

app.post('/openwa/webhook',
  express.raw({ type: 'application/json' }),        // raw body needed for HMAC
  (req, res) => {
    if (!verify(req.body, req.header('X-OpenWA-Signature'), process.env.OPENWA_SECRET))
      return res.sendStatus(401);
    const evt = JSON.parse(req.body.toString());
    res.sendStatus(200);                             // ack fast
    // dedupe on evt.idempotencyKey, then process asynchronously
  });
```

Manage webhooks:

```
GET    /api/sessions/{sessionId}/webhooks            # list
GET    /api/webhooks                                  # list across all sessions
PUT    /api/sessions/{sessionId}/webhooks/{id}        # update
POST   /api/sessions/{sessionId}/webhooks/{id}/test   # send a test event
DELETE /api/sessions/{sessionId}/webhooks/{id}        # remove
```

---

## 7. Helpers

Check whether a number is on WhatsApp before sending:

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
| `400` | Bad input (unregistered number, bad `chatId`). Fix the request. |
| `401` | Missing/invalid `X-API-Key`. |
| `404` | Unknown session/resource. |
| `409` | Session not ready / already started — wait for `ready`. |
| `429` | Rate limited — back off (see headers). |
| `5xx` | Gateway/engine error — retry with backoff. |

Default rate limits (headers `X-RateLimit-Remaining` / `X-RateLimit-Reset` on
every response):

| Window | Limit |
| --- | --- |
| Short | 10 requests / second |
| Medium | 100 requests / minute |
| Long | 1000 requests / hour |

For large fan‑outs, prefer **send‑bulk** (section 5), which paces for you.

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
  CURLOPT_POSTFIELDS => json_encode(['chatId' => '14155552671@c.us', 'text' => 'Your code is 4821']),
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

- **Keep the API key secret** — server‑side only; never ship it in a browser/mobile app. Use a separate scoped key per integration so one can be revoked without affecting others.
- **Always use HTTPS** (enforced on `taxiconnect.online`).
- **Verify webhook signatures** and **dedupe on `X-OpenWA-Idempotency-Key`**.
- **Normalize phone numbers** to digits + `@c.us`; optionally pre‑check with the contacts/check endpoint.
- **Respect rate limits**; use `send-bulk` for campaigns and add jitter at volume.
- **Handle `409 not ready`** by checking `GET /sessions/{id}` for `status: "ready"` before sending; alert if a session drops to `disconnected`.
- **One sender = one number** — run multiple sessions for multiple numbers.

---

_Live interactive reference: `https://taxiconnect.online/api/docs` · Full docs: [`docs/`](./docs/)._
