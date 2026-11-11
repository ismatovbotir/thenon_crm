# THE NON ↔ 1C: integration spec

1C works on its own schedule (for example every 5–10 minutes). THE NON never calls 1C.

Flow: an operator accepts an order in the CRM → 1C fetches it, creates the document on its side and answers with the document number → if someone later edits the document in 1C and presses the update button, 1C sends the changed lines again. Only the **lines** can change (added, removed, quantity, price). Nothing else about an order is changed from 1C.

```
1C ──POST /api/v1/1c/items──────────────▶ THE NON   (item list)
1C ──GET  /api/v1/orders?pending_1c=1───▶ THE NON   (orders to take)
1C ──POST /api/v1/1c/orders/{uuid}──────▶ THE NON   (number after creating; later, line changes)
```

**Rule: 1C always wins.** What 1C sends overwrites what is stored in THE NON.

## Basics

- Base URL: `https://<host>/api/v1`
- Auth: `Authorization: Bearer <token>`. The token is created in the CRM (Настройки → API-токены) and is shown once.
- Headers: `Accept: application/json`, `Content-Type: application/json`
- Limit: 120 requests/minute per token (HTTP 429 beyond that).
- Errors: 401 bad or missing token, 404 unknown uuid, 422 validation (`{"message": "...", "errors": {"field": ["..."]}}`).
- All calls are idempotent: repeating one is safe.
- The order identifier is our `uuid` (36 characters). There is no separate 1C uuid.

## 1. Send items

`POST /api/v1/1c/items`

```json
{
  "full_sync": false,
  "items": [
    {
      "code": "000123",
      "name_ru": "Лепёшка",
      "name_uz": "Non",
      "unit": "pcs",
      "step": 1,
      "category": "Лепёшки",
      "is_active": true
    }
  ]
}
```

| Field | Required | Notes |
|---|---|---|
| `items` | yes | 1–1000 per request; split larger lists into batches |
| `items[].code` | yes | Unique key, up to 50 characters. Matches an existing item or creates a new one |
| `name_ru`, `name_uz` | one of the two | If only one is sent for a new item, the other is filled from it |
| `unit` | no | `pcs` or `pack` |
| `step` | no | Order quantity step, 1–1000 |
| `category` | no | Free text |
| `is_active` | no | `false` hides the item from ordering |
| `full_sync` | no | `true`: active items not present in this list are switched off. It is never a delete. Use only when the request carries the **whole** catalog (send all batches before you rely on it) |

Fields you leave out are not changed. Prices are not sent here: operators maintain them in the company price lists.

Response `200`:

```json
{ "data": { "created": 3, "updated": 120, "deactivated": 0 } }
```

## 2. Fetch orders to take

`GET /api/v1/orders?pending_1c=1`

Returns orders in status `accepted` that 1C has not answered yet. After the answer in step 3 an order drops out of this list for good, even if it is edited in 1C later (edits go through step 3). Without the filter you get all orders.

Response `200`:

```json
{
  "data": [
    {
      "id": 42,
      "uuid": "9b1f2c34-5d6e-4f70-8a91-b2c3d4e5f607",
      "one_c_number": null,
      "date": "2026-11-11",
      "status": "accepted",
      "company": { "id": 3, "name": "ООО Ромашка" },
      "location": { "id": 7, "name": "Магазин №1" },
      "synced_1c_at": null,
      "updated_at": "2026-11-10T09:12:44+00:00",
      "lines": [
        {
          "item_code": "000123",
          "name_ru": "Лепёшка",
          "name_uz": "Non",
          "unit": "pcs",
          "quantity": 40,
          "is_active": true,
          "comment": null,
          "price": 4500.0,
          "total": 180000.0
        }
      ]
    }
  ]
}
```

- `date` is the delivery date.
- Lines with `is_active: false` were switched off by an operator: do not put them in the 1C document.
- `comment` on a line is an operator note for that line.
- `price` can be `null` when the company has no price for the item.
- Response fields not listed above (`preview_url`, `completed_at`, and so on) can be ignored. The list is paginated: follow `links.next` until it is empty.

## 3. Answer for one order

`POST /api/v1/1c/orders/{uuid}`

```json
{
  "one_c_number": "РН-000457",
  "lines": [
    { "item_code": "000123", "quantity": 38, "price": 4500, "is_active": true, "comment": "согласовано" }
  ]
}
```

| Field | Required | Notes |
|---|---|---|
| `one_c_number` | **yes** | Document number in 1C, up to 50 characters. It is shown in the CRM and on the printed form |
| `lines` | no | If present, this is the **complete** list of lines. Each line: `item_code` (must exist), `quantity` (integer ≥ 1), `price` (optional), `is_active` (optional, default `true`), `comment` (optional) |

How 1C wins:
- A line that 1C sends is created or overwritten (quantity, price, active flag, comment).
- A line that is in THE NON but missing from `lines` is **switched off** (`is_active: false`), not deleted, so the history stays.
- Omit `lines` entirely to only set the number. The lines stay as they are.
- **Editing in 1C:** when the document changes (line added, removed, quantity or price changed), send the order again with the complete current `lines`. The same rules apply and the new values replace the old ones. This works for any non-draft order, however far it has moved.
- `status` is not accepted from 1C: it is ignored. Statuses are managed in the CRM and the Telegram bot.

Side effects:
- `synced_1c_at` is set on the first answer (the order now shows as "В 1С" in the CRM) and not touched again.

Rejected with `422`:
- the order is still a draft;
- an `item_code` that does not exist (send the item via step 1 first);
- a missing `one_c_number`, or a repeated `item_code` inside `lines`.

Response `200`: the order in the same shape as in step 2, with the new values.

## Recommended 1C scheduled job

1. `POST /1c/items` (when the catalog changed; `full_sync` only if you want to switch off the missing ones).
2. `GET /orders?pending_1c=1` and follow the pagination.
3. For each order: create the document in 1C, then `POST /1c/orders/{uuid}` with its number (and `lines` if the document differs from what was ordered).
4. If the POST fails or times out, do nothing special: the order stays in the pending list and is fetched again on the next run. Repeating the POST is safe.
5. When someone edits the document in 1C and presses the update button, send it again with the same `uuid` and the complete current `lines`.
