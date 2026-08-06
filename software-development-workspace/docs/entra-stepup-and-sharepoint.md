# Two enhancements: Entra step-up on commercials, and SharePoint

Neither is built. Both are recorded here with what they cost and what they
conflict with, so the Pipeline UI is built in a way that accommodates them.

---

## 1. Forcing an authenticator prompt to view commercials

**Yes, this works, and it is the right mechanism.** Entra calls it
**Authentication Context**, and the pattern is step-up authentication.

### How it works

1. Define an authentication context in Entra — say `c1` labelled
   "View commercial data".
2. Write a Conditional Access policy targeting `c1` that requires MFA, and
   optionally a short sign-in frequency (`Every time`, or 15 minutes).
3. When someone asks for a commercial figure and their token does not carry the
   context, the API answers **401 with a claims challenge** rather than 403:

   ```
   WWW-Authenticate: Bearer error="insufficient_claims",
     claims="eyJhY2Nlc3NfdG9rZW4iOnsiYWNycyI6eyJlc3NlbnRpYWwiOnRydWUsInZhbHVlIjoiYzEifX19"
   ```

   Decoded, that is `{"access_token":{"acrs":{"essential":true,"value":"c1"}}}`.
4. The SPA re-runs the Entra authorize request passing that `claims` parameter.
   Entra evaluates the CA policy, prompts the authenticator, and returns a token
   whose `acrs` claim contains `c1`.
5. The API validates `acrs` and releases the figures.

### What it costs us

The awkward part is our own auth, not Entra's. After SSO we issue a **Sanctum
bearer token**, and the Entra token is discarded — so there is nothing carrying
`acrs` to check. Three ways out, in order of preference:

- **Record the step-up on our side.** On a successful re-auth, the SPA posts
  the fresh Entra token to a `/auth/azure/step-up` endpoint; the backend
  validates it, confirms `acrs` contains `c1`, and stamps a short-lived
  `commercials_verified_until` on the token or session. The elevation check then
  becomes *permission AND recent step-up*. Smallest change, keeps Sanctum.
- **Pass the Entra token through** for commercial endpoints only. Cleanest
  semantically, most disruptive to the API.
- **Drop Sanctum for Entra tokens entirely.** Correct long-term, a separate
  project.

I would take the first. It is roughly a day, and it composes with what exists:
`pipeline.view_commercials` says *who may*, the step-up says *prove it is you,
now*. Both are needed — the permission alone survives a stolen laptop, and the
prompt alone would let anyone with an authenticator see margins.

### What the Pipeline UI must do to be ready

Treat **401 with `insufficient_claims` as "step up", not "logged out"**. That
is the one thing which is expensive to retrofit, because a blanket
401-means-logout interceptor will silently sign people out instead of prompting
them. The UI is being built to distinguish the two.

---

## 2. Pushing documents to SharePoint as a batch job

Feasible — Microsoft Graph, `PUT /drives/{id}/items/{path}:/content`, app-only
with `Sites.Selected` scoped to one site so a token leak does not reach the
tenant's whole SharePoint. A nightly job over documents not yet mirrored is
straightforward.

**But it collides head-on with the encryption we just built,** and the collision
should be decided rather than discovered.

### The conflict

Documents are encrypted before they reach storage. Push the ciphertext to
SharePoint and it is unopenable there — no preview, no search, no co-authoring,
which are the only reasons to want it in SharePoint. Push the plaintext and
SharePoint becomes a second, unencrypted copy of the tender responses and
signed contracts, governed by SharePoint's permissions rather than ours. That
undoes the property we were asked to guarantee.

### The three honest options

| | What it gives | What it costs |
|---|---|---|
| **Ciphertext mirror** | Off-platform backup, key stays ours | Useless as SharePoint — files cannot be opened |
| **Plaintext mirror** | Real SharePoint: preview, search, co-authoring | A second uncontrolled copy; our access control no longer governs it |
| **Plaintext, with mirroring by document class** | The useful subset in SharePoint, the sensitive set never leaves | Someone must decide the classes, and the decision has to hold |

### Recommendation

**The third.** Mirror what SharePoint is actually wanted for — scopes of work,
specifications, proposals, delivery notes — and never mirror bids, bid bonds,
financial workings, signed contracts or anything carrying customer PII. The
`stage` and `document_type` columns on `opportunity_documents` already carry
enough to express that rule, which is a happy accident of having modelled
documents as rows rather than seventeen path strings.

If SharePoint is wanted as **backup** rather than as a workspace, the answer is
different and simpler: mirror ciphertext, and the conflict disappears.

**The question to settle before building it:** is SharePoint wanted as a place
people work on these files, or as a second copy in case ours is lost? They lead
to opposite designs.
