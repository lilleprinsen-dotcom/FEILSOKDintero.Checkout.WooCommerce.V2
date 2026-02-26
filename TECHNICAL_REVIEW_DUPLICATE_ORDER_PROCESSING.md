# Technical review: possible duplicate first-time order processing

## Conclusion (short)
The plugin has a realistic race condition where **the same order can be confirmed twice at placement time**, which can in turn run `payment_complete()` twice (or equivalent first-time processing logic twice). The most likely path is two near-simultaneous calls into `dintero_confirm_order()` from different entry points (customer redirect + callback processing, or duplicate redirect hits), both passing the non-atomic `get_date_paid()` guard before either write is persisted.

## Evidence in code

### 1) Two independent entry points can confirm the same order
- Redirect success path calls `dintero_confirm_order()` immediately when customer is returned from Dintero. (`Dintero_Checkout_Redirect::handle_success`) 
- Callback handler also calls `dintero_confirm_order()` for `AUTHORIZED` and `CAPTURED` statuses. (`Dintero_Checkout_Callback::handle_callback`)

So the same Woo order can be processed through more than one execution path at order-placement time.

### 2) First-time guard is not atomic
- In `dintero_process_authorized_order()`, the only early guard is:
  - `if ( ! empty( $order->get_date_paid() ) ) { return; }`
- Then it writes the exact note you reported:
  - `The order was placed successfully via Dintero Checkout. Transaction ID: %s`
- Then it calls `$order->payment_complete( $transaction_id )` (if configured status is `processing`) or sets paid/status manually.

This check-then-act sequence is not protected by a lock, transaction, or idempotency marker that is set **before** side effects.

### 3) Note is written before completion call
Because the note is added before `payment_complete()`, two concurrent executions can both add the same note even if one later exits/short-circuits deeper in Woo internals.

## Exact duplicate flow that can happen
1. Request A and Request B both enter order confirmation path for the same order nearly at the same time.
2. Both load an order object where `date_paid` is still empty.
3. Both pass the guard in `dintero_process_authorized_order()`.
4. Both add order note: “The order was placed successfully via Dintero Checkout. Transaction ID: …”.
5. Both call `payment_complete()` (or equivalent manual paid/status path).
6. Result can include duplicated first-time effects, including duplicate stock reduction depending on Woo timing and stock-reduction meta race.

## Why this matches your observations
- **Only at placement time:** the duplicate path is tied to redirect/callback confirmation timing, not later capture/refund status handling.
- **Intermittent/frequent:** races are timing-dependent and won’t trigger on every order.
- **Vipps-only tendency:** if Vipps causes tighter timing between redirect and status callback or more repeated return hits, this race becomes more likely.
- **Exact duplicated note text:** that text is emitted in the same function that triggers completion.

## Confidence
- **High** that the plugin has a real duplication race at first confirmation.
- **Medium-high** that this is your primary production root cause (fits symptoms strongly, but final proof requires correlated logs with timestamps/request IDs).

## Recommended fix
Implement explicit idempotency around first confirmation processing, not just `get_date_paid()`.

### Definitive approach (recommended)
Use an atomic lock/idempotency marker before adding notes or calling `payment_complete()`.
- Example strategy:
  - Try to create a unique post meta key (e.g. `_dintero_initial_confirmation_started`) using `add_post_meta( $order_id, key, value, true )`.
  - If insert fails (already exists), return immediately.
  - Perform confirmation logic.
  - Set `_dintero_initial_confirmation_done` on success.

Why this works:
- `add_post_meta(..., true)` is effectively atomic at DB level for uniqueness semantics in WordPress metadata APIs.
- It prevents concurrent workers from both running first-time side effects.

### Additional hardening
- Add a second idempotency check keyed by transaction id (e.g. `_dintero_confirmed_txn_{id}`) to avoid duplicate work if callback/redirect replay occurs.
- Move the “placed successfully” note write to occur only after completion is definitely accepted, or behind the idempotency lock.

## Is fix definitive or mitigation?
- Proper atomic idempotency lock is **definitive for plugin-side duplication**.
- It does not prevent external duplicate requests; it makes them harmless.
- Merely re-checking `date_paid` in more places is only a **mitigation**, not definitive under concurrency.
