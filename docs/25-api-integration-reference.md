# 25 — OpenWA API Integration Reference

Complete HTTP API reference for integrating OpenWA into another project. Generated from the
actual controllers/DTOs in `src/modules/**`.

- **Base URL (this deployment):** `https://wa.sahansolution.com/api`
- **Auth:** every request sends header **`X-API-Key: <your-key>`**
- **Content type:** `application/json` for bodies
- **Realtime:** Socket.IO on the same origin (`wss://wa.sahansolution.com`) for live QR/status

---

## 1. Authentication & roles

Send your key on every call:
```
X-API-Key: owa_k1_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Role hierarchy (higher includes lower): **ADMIN > OPERATOR > VIEWER**.
- **VIEWER** (any valid key): all `GET`/read endpoints.
- **OPERATOR**: send messages, manage sessions, manage webhooks.
- **ADMIN**: API keys, settings, infra.

Validate a key:
```bash
curl -X POST https://wa.sahansolution.com/api/auth/validate -H "X-API-Key: $KEY"
# -> {"valid":true,"role":"ADMIN"}
```

**Rate limits** (default): 10 req/sec, 100 req/min, 1000 req/hour (per key). `429` on exceed.

### ID formats (important)
- **`sessionId`** in routes = the session's **UUID `id`** (e.g. `efdf1e87-...`), *not* its name.
- **`chatId`**: individual = `<countrycode><number>@c.us` (e.g. `25377330376@c.us`, no `+`);
  group = `<groupId>@g.us`.

---

## 2. Sessions — `/api/sessions`

| Method | Path | Role | Body / notes |
|---|---|---|---|
| POST | `/sessions` | OPERATOR | `{ "name":"my-bot", "config?":{}, "proxyUrl?":"", "proxyType?":"http\|https\|socks4\|socks5" }` |
| GET | `/sessions` | VIEWER | list all sessions |
| GET | `/sessions/:id` | VIEWER | one session; `status` ∈ `created\|qr_ready\|connected\|disconnected` |
| DELETE | `/sessions/:id` | OPERATOR | delete session |
| POST | `/sessions/:id/start` | OPERATOR | start engine (generates QR) |
| POST | `/sessions/:id/stop` | OPERATOR | stop engine |
| GET | `/sessions/:id/qr` | OPERATOR | `{ "qrCode":"data:image/png;base64,...", "status":"qr_ready" }` |
| GET | `/sessions/:id/groups` | VIEWER | `[{ "id":"...@g.us", "name":"..." }]` |
| GET | `/sessions/stats/overview` | VIEWER | multi-session counters |

Session object fields: `id, name, status, phone, pushName, connectedAt, lastActive, autoStart, lastDisconnectReason, lastDisconnectAt, createdAt, updatedAt`.

---

## 3. Messages — `/api/sessions/:sessionId/messages`

All send endpoints require **OPERATOR**. Success returns `{ "messageId":"...", "timestamp":1699999999 }`.

| Method | Path | Body |
|---|---|---|
| GET | `/messages` | query: list chat messages |
| POST | `/messages/send-text` | `{ "chatId":"<id>", "text":"Hello" }` (text ≤ 4096) |
| POST | `/messages/send-image` | media body (below) |
| POST | `/messages/send-video` | media body |
| POST | `/messages/send-audio` | media body |
| POST | `/messages/send-document` | media body |
| POST | `/messages/send-sticker` | media body |
| POST | `/messages/send-location` | `{ "chatId":"<id>", "latitude":11.58, "longitude":43.14, "description?":"", "address?":"" }` |
| POST | `/messages/send-contact` | `{ "chatId":"<id>", "contactName":"Jane", "contactNumber":"25377000000" }` |
| POST | `/messages/reply` | `{ "chatId":"<id>", "quotedMessageId":"<msgId>", "text":"..." }` |
| POST | `/messages/forward` | `{ "fromChatId":"<id>", "toChatId":"<id>", "messageId":"<msgId>" }` |
| POST | `/messages/react` | `{ "chatId":"<id>", "messageId":"<msgId>", "emoji":"👍" }` (empty `emoji` removes) |
| GET | `/messages/:chatId/:messageId/reactions` | list reactions |
| POST | `/messages/delete` | `{ "chatId":"<id>", "messageId":"<msgId>", "forEveryone?":true }` |
| POST | `/messages/send-bulk` | `{ "batchId?":"", "messages":[...≤100], "options?":{...} }` |
| GET | `/messages/batch/:batchId` | bulk batch status |
| POST | `/messages/batch/:batchId/cancel` | cancel bulk batch |

**Media body** (image/video/audio/document/sticker) — provide `url` **or** `base64`:
```json
{
  "chatId": "25377330376@c.us",
  "url": "https://example.com/file.jpg",
  "base64": "<base64 data, alternative to url>",
  "mimetype": "image/jpeg",
  "filename": "file.jpg",
  "caption": "optional caption (≤1024)"
}
```

**Bulk** `messages[]` item shape:
```json
{
  "chatId": "25377330376@c.us",
  "type": "text",
  "content": { "text": "Hi {{name}}", "caption": "", "image": {"url":""} },
  "variables": { "name": "Ali" }
}
```
`options`: `{ "delayBetweenMessages": 1000, "randomizeDelay": true, "stopOnError": false }`.

### Sending to a group
Just use any send endpoint with `chatId = "<groupId>@g.us"`. Get group IDs from
`GET /sessions/:id/groups` or the Groups API below.

---

## 4. Groups — `/api/sessions/:sessionId/groups`

| Method | Path | Body |
|---|---|---|
| GET | `/groups` | list groups |
| GET | `/groups/:groupId` | group details |
| POST | `/groups` | `{ "name":"My Group", "participants":["25377000001@c.us","25377000002@c.us"] }` |
| POST | `/groups/:groupId/participants` | `{ "participants":["...@c.us"] }` — add |
| DELETE | `/groups/:groupId/participants` | `{ "participants":["...@c.us"] }` — remove |
| POST | `/groups/:groupId/participants/promote` | `{ "participants":["...@c.us"] }` — make admin |
| POST | `/groups/:groupId/participants/demote` | `{ "participants":["...@c.us"] }` |
| PUT | `/groups/:groupId/subject` | `{ "subject":"New name" }` |
| PUT | `/groups/:groupId/description` | `{ "description":"New description" }` |
| POST | `/groups/:groupId/leave` | leave group |
| GET | `/groups/:groupId/invite-code` | `{ "inviteCode":"...", "url":"https://chat.whatsapp.com/..." }` |
| POST | `/groups/:groupId/invite-code/revoke` | rotate invite code |

---

## 5. Contacts — `/api/sessions/:sessionId/contacts`

| Method | Path | Notes |
|---|---|---|
| GET | `/contacts` | all contacts |
| GET | `/contacts/:contactId` | one contact |
| GET | `/contacts/check/:number` | is number on WhatsApp → `{ exists, jid }` |
| GET | `/contacts/:contactId/profile-picture` | profile picture URL |
| POST | `/contacts/:contactId/block` | block |
| DELETE | `/contacts/:contactId/block` | unblock |

---

## 6. Labels — `/api/sessions/:sessionId/labels`

| Method | Path | Body |
|---|---|---|
| GET | `/labels` | list labels |
| GET | `/labels/:labelId` | one label |
| GET | `/labels/chat/:chatId` | labels on a chat |
| POST | `/labels/chat/:chatId` | add label to chat |
| DELETE | `/labels/chat/:chatId/:labelId` | remove label from chat |

---

## 7. Status / Stories — `/api/sessions/:sessionId/status`

> ⚠️ This is the WhatsApp **Stories** feature, **not** session connection state.
> For connection state use `GET /api/sessions/:id`.

| Method | Path | Notes |
|---|---|---|
| GET | `/status` | view statuses |
| GET | `/status/:contactId` | a contact's status |
| POST | `/status/send-text` | post a text story |
| POST | `/status/send-image` | post an image story |
| POST | `/status/send-video` | post a video story |
| DELETE | `/status/:statusId` | delete a story |

---

## 8. Channels — `/api/sessions/:sessionId/channels`

| Method | Path | Body |
|---|---|---|
| GET | `/channels` | list channels |
| GET | `/channels/:channelId` | channel details |
| GET | `/channels/:channelId/messages` | channel messages |
| POST | `/channels/subscribe` | `{ "inviteCode":"..." }` |
| DELETE | `/channels/:channelId` | unsubscribe |

---

## 9. Catalog / Products — `/api/sessions/:sessionId`

| Method | Path | Body |
|---|---|---|
| GET | `/catalog` | business catalog |
| GET | `/catalog/products` | query `?page=1&limit=20` |
| GET | `/catalog/products/:productId` | one product |
| POST | `/messages/send-product` | `{ "chatId":"<id>", "productId":"...", "body?":"" }` |
| POST | `/messages/send-catalog` | `{ "chatId":"<id>", "body?":"" }` |

---

## 10. Webhooks — receive incoming events

Register per session: `/api/sessions/:sessionId/webhooks` (OPERATOR).

| Method | Path | Body |
|---|---|---|
| POST | `/webhooks` | `{ "url":"https://app/webhook", "events":[...], "secret?":"", "headers?":{}, "retryCount?":3 }` |
| GET | `/webhooks` | list this session's webhooks |
| GET | `/webhooks/:id` | one |
| PUT | `/webhooks/:id` | update (`active`, `events`, `url`, `secret`, `headers`, `retryCount`) |
| POST | `/webhooks/:id/test` | send a test delivery |
| DELETE | `/webhooks/:id` | delete |
| GET | `/api/webhooks` | list ALL webhooks (global) |

**Event types:** `message.received`, `message.sent`, `message.ack`, `message.revoked`,
`session.status`, `session.qr`, `session.authenticated`, `session.disconnected`,
`group.join`, `group.leave`, `group.update`.

**Delivery** — OpenWA `POST`s JSON to your URL:
```json
{
  "event": "message.received",
  "timestamp": "2026-10-11T04:30:00.000Z",
  "sessionId": "efdf1e87-...",
  "idempotencyKey": "…",
  "deliveryId": "…",
  "data": { "...": "event-specific payload" }
}
```
Request headers OpenWA sends:
- `X-OpenWA-Signature: sha256=<hex>` — HMAC-SHA256 of the **raw JSON body** using your `secret`
- `X-OpenWA-Delivery-Id`, `X-OpenWA-Idempotency-Key`, `X-OpenWA-Retry-Count`

**Verify the signature** (Node):
```js
const crypto = require('crypto');
const expected = 'sha256=' + crypto.createHmac('sha256', SECRET).update(rawBody).digest('hex');
const ok = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-openwa-signature']));
```
PHP (Laravel):
```php
$expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
abort_unless(hash_equals($expected, $request->header('X-OpenWA-Signature')), 403);
```
Use `idempotencyKey` to dedupe retries. Respond `2xx` quickly; non-2xx triggers retry (up to `retryCount`).

---

## 11. Utility endpoints

| Area | Endpoint |
|---|---|
| Health | `GET /api/health`, `/api/health/live`, `/api/health/ready` |
| Stats | `GET /api/stats/overview`, `/api/stats/messages`, `/api/stats/sessions/:sessionId` |
| Audit | `GET /api/audit` |
| Settings | `GET /api/settings`, `PUT /api/settings` (ADMIN) |
| API keys | `/api/auth/api-keys` CRUD + `POST /:id/revoke` (ADMIN) |
| Plugins | `/api/plugins`, `/:id`, `/:id/enable`, `/:id/disable`, `/:id/config`, `/:id/health` |
| Infra | `/api/infra/status`, `/engines`, `/config` (ADMIN), `/restart` (ADMIN), export/import (ADMIN) |
| Swagger | `GET /api/docs` (interactive, authorize with `X-API-Key`) |

---

## 12. Integration quickstart

### Minimal cURL (send text, incl. to a group)
```bash
KEY="owa_k1_..."
BASE="https://wa.sahansolution.com/api"
SID="efdf1e87-989a-47e3-9caf-fb5c227b3e15"

# to a person
curl -s -X POST "$BASE/sessions/$SID/messages/send-text" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"chatId":"25377330376@c.us","text":"Hello"}'

# to a group (groupId from GET /sessions/$SID/groups)
curl -s -X POST "$BASE/sessions/$SID/messages/send-text" \
  -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"chatId":"120363020000000000@g.us","text":"Hello group"}'
```

### PHP / Laravel client (fits the other projects here)
```php
use Illuminate\Support\Facades\Http;

class OpenWA
{
    public function __construct(
        private string $base = 'https://wa.sahansolution.com/api',
        private string $key  = '',          // put in config/services.php + .env
        private string $sid  = '',          // session UUID
    ) {}

    private function http() {
        return Http::withHeaders(['X-API-Key' => $this->key])->acceptJson();
    }

    public function sendText(string $chatId, string $text) {
        return $this->http()->post("{$this->base}/sessions/{$this->sid}/messages/send-text",
            ['chatId' => $chatId, 'text' => $text])->throw()->json();
    }

    public function sendImage(string $chatId, string $url, ?string $caption = null) {
        return $this->http()->post("{$this->base}/sessions/{$this->sid}/messages/send-image",
            ['chatId' => $chatId, 'url' => $url, 'caption' => $caption])->throw()->json();
    }

    public function sendToGroup(string $groupId, string $text) {
        return $this->sendText("{$groupId}@g.us", $text);
    }

    public function createGroup(string $name, array $participants) {
        return $this->http()->post("{$this->base}/sessions/{$this->sid}/groups",
            ['name' => $name, 'participants' => $participants])->throw()->json();
    }
}

// helper: phone -> chatId
function waChatId(string $phone): string {
    return preg_replace('/\D/', '', $phone) . '@c.us';   // "253 77..." -> "25377...@c.us"
}
```

### Node / TypeScript client
```ts
const BASE = 'https://wa.sahansolution.com/api';
const KEY  = process.env.OPENWA_KEY!;
const SID  = process.env.OPENWA_SESSION!;

async function call(path: string, init: RequestInit = {}) {
  const res = await fetch(`${BASE}${path}`, {
    ...init,
    headers: { 'X-API-Key': KEY, 'Content-Type': 'application/json', ...(init.headers||{}) },
  });
  if (!res.ok) throw new Error(`${res.status} ${await res.text()}`);
  return res.json();
}

export const wa = {
  sendText: (chatId: string, text: string) =>
    call(`/sessions/${SID}/messages/send-text`, { method: 'POST', body: JSON.stringify({ chatId, text }) }),
  sendToGroup: (groupId: string, text: string) =>
    wa.sendText(`${groupId}@g.us`, text),
  groups: () => call(`/sessions/${SID}/groups`),
};
```

### Laravel webhook receiver (incoming messages)
```php
// routes/api.php
Route::post('/wa/webhook', [WaWebhookController::class, 'handle']);

// app/Http/Controllers/WaWebhookController.php
public function handle(Request $request) {
    $secret   = config('services.openwa.webhook_secret');
    $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
    abort_unless(hash_equals($expected, (string) $request->header('X-OpenWA-Signature')), 403);

    $event = $request->input('event');
    $data  = $request->input('data', []);
    // dedupe with $request->input('idempotencyKey') before processing
    if ($event === 'message.received') {
        // store / react to $data (from, body, etc.)
    }
    return response()->json(['ok' => true]);   // 2xx = delivered
}
```
Register it:
```bash
curl -s -X POST "$BASE/sessions/$SID/webhooks" -H "X-API-Key: $KEY" -H "Content-Type: application/json" \
  -d '{"url":"https://your-app/api/wa/webhook","events":["message.received","message.ack"],"secret":"<shared-secret>"}'
```

---

## 13. Error model

Standard NestJS errors: `{ "message": "...", "error": "...", "statusCode": 4xx/5xx }`.
Common cases: `400` session not active / validation; `401/403` bad or insufficient key;
`404` not found; `409` name conflict; `429` rate limited; `500` engine not connected yet.
