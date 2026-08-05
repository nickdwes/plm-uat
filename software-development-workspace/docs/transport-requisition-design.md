# Transport requisition — design

## The rule, and what it changes

> Transport entitlement should be mileage-driven and cost-driven, not
> grade-driven.

This contradicts something already shipped. `Transport` is currently an expense
category priced at **KES 3,000 per day for every grade**, checked as a ceiling
like meals or accommodation. That has to go: a 40 km errand and a 600 km round
trip are not the same entitlement, and no grade table can tell them apart.

It does **not** follow that the whole allowance model is wrong. Per diem and
accommodation are properly grade-driven — a unit head does get a better hotel.
The change is that **transport is computed, not looked up**.

So the rate card keeps its role for what money is *allowed*, and transport
gains a calculator for what a journey *costs*.

## The structural decision

The requirement describes a "transport request form" with purpose, destination
and itinerary — fields a travel request already has. But it also describes
delivering an item, where nobody travels at all.

**Recommendation: a transport leg on a request, not a parallel module.**

```
travel_requests
  └── travel_transport_legs   one per journey and mode
```

Reasons:

- Approval chain, thresholds, delegation, reconciliation, attachments, tenancy
  and the activity trail all exist and are tested. A second module means a
  second approval engine, and two engines diverge.
- A real trip is often several legs: a flight to Kampala, then taxis locally.
  One leg per mode expresses that; one request per mode does not.
- Delivery is a *purpose* with no traveller allowance, not a different kind of
  request. It still needs approving, costing and reconciling.

The cost against a decision: a pure delivery run carries travel fields it does
not use. That is a smaller price than duplicating the workflow.

**This is the one assumption worth confirming before the UI is built.** The
schema below is the same either way; only where the form lives changes.

## What is configured, and what is entered per request

Fuel price is deliberately **not** configuration. The requirement says "current
fuel price at the point of request", and a pump price stored centrally is stale
within a week — every request would be costed against a number nobody updated.

| Configured once | Entered per request |
| --- | --- |
| Mileage rate per km (effective-dated) | Estimated distance |
| Vehicle types and their fuel consumption | Fuel price today |
| Standard bus fares per route | Days, itinerary, purpose |
| — | Ticket and visa quotes |

## Calculation by mode

| Mode | Estimate | Reconciled against |
| --- | --- | --- |
| Self-drive | `distance × rate_per_km` | actual distance |
| Hired vehicle | `(distance ÷ km_per_litre) × fuel_price` | actual fuel receipts |
| Bus | standard round-trip fare for the route | ticket |
| Taxi / ride-hail | no estimate — actual only | receipts |
| Flight | quoted round-trip ticket | ticket or invoice |
| Visa | quoted application fee | receipt |

Two of these have no meaningful estimate. A taxi fare is not knowable in
advance, and pretending otherwise produces a number the approver cannot judge
and the traveller cannot meet. Those are entered as an intent to claim and
settled on evidence — which is why reconciliation already distinguishes who
supplies what.

## Reconciliation

Fuel and mileage reconcile against **actual distance**, not against a receipt
alone: a fuel receipt proves money was spent, not that the journey happened.
Recording actual kilometres is what makes the comparison meaningful, and it is
the one number the driver always knows.

Taxi and flight reconcile on receipts, as they already do.

## What this does not change

The evidence policy per category, the four-stage approval chain, thresholds,
delegation and closing all apply unchanged. A transport request is a travel
request whose costing happens to be computed.

## Sequencing

1. Configuration and the calculator — no UI dependency, testable in isolation
2. Transport legs on a request, and costing from them
3. The request form: mode-dependent fields
4. Reconciliation of actual distance against estimate

Step 1 is the same under either structural answer, so it is safe to build
before that decision is confirmed.
