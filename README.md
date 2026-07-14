# Gothem Swish Status

Thirty bees module that receives verified Swish payment matches from `smsqueue.php` and applies the payment status flow inside the shop.

The callback validates the shared key, order id, and order reference, stores the Swish payer name as a private order message, then changes the order state using `OrderHistory`:

1. Payment accepted status
2. Delivered status

Because this runs inside thirty bees, normal order status hooks can create receipts and other follow-up actions.

## Callback

The module exposes:

```text
https://gothem.net/butik/module/gothemswishstatus/callback
```

Expected POST fields:

- `key`: shared key, either the module callback key or `SMS_QUEUE_KEY`.
- `id_order`: thirty bees order id.
- `reference`: order reference parsed from the Swish notification.
- `payer_name`: normalized Swish payer name.

The callback returns JSON with separate `payer`, `payment`, and `delivered` results. This makes failures easy to trace without using the old external `taskerupdate.php` flow.

## Related flow

```text
Swish app notification
  -> Gothem SMS Bridge
  -> smsqueue.php?action=swish
  -> gothemswishstatus callback
  -> Payment accepted
  -> tbskvreceipt creates or reuses the payment receipt
  -> smsnotification can send the receipt SMS
  -> Delivered
```
