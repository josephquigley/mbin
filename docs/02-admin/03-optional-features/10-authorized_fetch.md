# Authorized fetch

> [!WARNING]
> This is a **draft** feature. Its behaviour may still change, and it has not
> been exercised against a wide range of peer implementations.

By default, Mbin serves the ActivityPub representation of your instance to
anybody who asks for it. Any client on the open internet can read actor
profiles, thread bodies, comment bodies, outboxes and follower collections by
sending an ActivityPub `Accept` header, and neither private instance mode nor
the federation allowlist changes that.

Authorized fetch requires a valid HTTP signature on every inbound ActivityPub
`GET`, and then checks the requesting instance against your federation
allowlist or ban list. It is the read-side counterpart of the signature
checking Mbin already performs on inbox deliveries.

Mastodon ships the same idea as `AUTHORIZED_FETCH` (also called secure mode)
and GoToSocial as `instance-federation-mode: allowlist`.

## Turning it on

Set the environment variable in your `.env`:

```
MBIN_AUTHORIZED_FETCH=true
```

Or toggle it in the admin panel, under **Federation**, where it sits beside the
allowlist setting. The default is `false`, and leaving it at `false` keeps the
behaviour Mbin has always had.

After changing `.env`, clear the cache and restart your workers.

## What is checked

When the setting is on, an inbound ActivityPub `GET` must carry:

- a `Signature` header naming a `keyId`, the signed headers, and the signature;
- a `Date` header;
- every other header the signature says it covers.

Mbin fetches the public key from the actor document named by `keyId`, rebuilds
the signing string (with `(request-target)` as `get <path and query>`), and
verifies. A `GET` has no body, so no `Digest` is computed: if the signature
claims to cover a digest that the request did not send, the request is refused
rather than the digest being invented.

The requesting instance is then checked with the same allowlist or ban list
that governs deliveries. That check happens **before** the key is fetched, so a
defederated instance cannot make your server open a connection to it.

A request that fails any of this gets `401 Unauthorized`.

## What is not gated

These stay readable without a signature even when the setting is on, because
gating them would break federation rather than protect anything:

| Route | Why |
|---|---|
| `/i/actor` | Your instance actor carries the public key a peer needs to verify anything you send. Gating it deadlocks the first signed request in both directions. |
| `/.well-known/webfinger` | Handle discovery. A peer needs it before it knows your actor URLs. |
| `/.well-known/host-meta` | Discovery. |
| `/.well-known/nodeinfo`, `/nodeinfo/2.x` | Server software and counts. No user content. |
| `/contexts.jsonld` | A static JSON-LD context document with no instance-specific data. |

The REST API under `/api`, the web UI's own endpoints under `/ajax`, and every
ordinary HTML page are untouched: they are not ActivityPub routes and the gate
never sees them. Inbox deliveries are `POST` requests and keep their existing,
unchanged signature checking.

## What this does not do

Be clear about what you are buying, because a security feature that is
oversold is worse than none:

- It **authenticates the sender** of a request. It does not encrypt anything.
- It is **only as good as the key fetch behind it**. The public key is fetched
  over HTTPS from the host named in `keyId`. If that fetch can be spoofed, the
  verification is worth nothing.
- It **does not stop a determined reader**. Anybody who runs an ActivityPub
  instance, or a script with a keypair and an actor document, can sign a `GET`.
  Only the allowlist narrows that down to instances you have named.
- It **does not unpublish** anything you have already federated out. Remote
  copies stay where they are.
- It **does not hide your usernames**: WebFinger is not gated.
- It **does not check the freshness of the `Date` header**, so a captured
  signed request can in principle be replayed. This matches the existing
  behaviour of inbox signature checking.

## What it costs

- **Federation with peers that do not sign GET requests will break.** They will
  see `401` where they used to see your content. This is the reason the setting
  is off by default, and the reason to think before turning it on.
- The first request from a given key means a synchronous outbound fetch of that
  actor document, inside a request the peer is waiting on. Results are cached.
- Caching proxies in front of your instance become less effective for
  ActivityPub responses, because the response now depends on who is asking.
