# SSO (Single Sign On) Providers

SSOs are used to simplify the registration flow. You authorize the server to use an existing account from one
of the available SSO providers.

Mbin supports a multitude of SSO providers:

- Google
- Facebook
- GitHub
- Keycloak
- Zitadel
- SimpleLogin
- Discord
- Authentik
- Privacy Portal
- Azure

To enable an SSO provider you (usually) have to create a developer account on the specific platform, create an app
and provide the app/client ID and a secret. These have to be entered in the correct environment variable
in the `.env`|`.env.local` file

### Google

https://developers.google.com/

```ini
OAUTH_GOOGLE_ID=AS2easdioh912 # your client ID
OAUTH_GOOGLE_SECRET=sdfpsajh329ura39ßseaoßjf30u # your client secret
```

### Facebook

https://developers.facebook.com

```ini
OAUTH_FACEBOOK_ID=AS2easdioh912 # your client ID
OAUTH_FACEBOOK_SECRET=sdfpsajh329ura39ßseaoßjf30u # your client secret
```

### GitHub

You need a GitHub account, if you do no have one, yet, go and create one: https://github.com/signup

1. Go to https://github.com/settings/developers
2. Click on "New OAuth App"
3. Enter the app name, description and Homepage URL (just your instance URL)
4. Insert `https://YOURINSTANCE/oauth/github/verify` as the "Authorization callback URL" (replace `YOURINSTANCE` with the URL of your instance)
5. Scroll down and click "Register application"
6. Now you have the chance to upload an icon (at the bottom of the page)
7. Click "Generate a new client secret"
8. Insert the "Client ID" and the generated client secret into the `.env` file:

```ini
OAUTH_GITHUB_ID=AS2easdioh912 # your client ID
OAUTH_GITHUB_SECRET=sdfpsajh329ura39ßseaoßjf30u # your client secret
```

### Keycloak

Self-hosted, https://www.keycloak.org/

```ini
OAUTH_KEYCLOAK_ID=AS2easdioh912 # your client ID
OAUTH_KEYCLOAK_SECRET=sdfpsajh329ura39ßseaoßjf30u # your client secret
OAUTH_KEYCLOAK_URI=
OAUTH_KEYCLOAK_REALM=
OAUTH_KEYCLOAK_VERSION=
```

### Zitadel

Self-hosted, https://zitadel.com/

```ini
OAUTH_ZITADEL_ID=AS2easdioh912 # your client ID
OAUTH_ZITADEL_SECRET=sdfpsajh329ura39ßseaoßjf30u # your client secret
OAUTH_ZITADEL_BASE_URL=
```

### SimpleLogin

You need a SimpleLogin account, if you do not have one, yet, go and create one: https://app.simplelogin.io/auth/register

1. Go to https://app.simplelogin.io/developer and click on "New website"
2. Enter the name of your instance and the url to your instance
3. Choose an icon (if you want to)
4. Click on "OAuth Settings" on the right
5. Insert the client ID ("AppID / OAuth2 Client ID") and the client secret ("AppSecret / OAuth2 Client Secret")
   in your `.env` file

```ini
OAUTH_SIMPLELOGIN_ID=gehirneimer.de-vycjfiaznc # your client ID
OAUTH_SIMPLELOGIN_SECRET=fdiuasdfusdfsdfpsdagofweopf # your client secret
```

6. Back in the browser, scroll down to "Authorized Redirect URIs" and click on "Add new uri"

### Discord

You need a Discord account, if you do not have one, yet, go and create one: https://discord.com/register

1. Go to https://discord.com/developers/applications and create a new application. If you want, add an image and a description.
2. Click the "OAuth2" tab on the left
3. Under "Client information" click "Reset Secret"
4. The newly generated secret and the "Client ID" need to go in our `.env` file:

```ini
OAUTH_DISCORD_ID=3245498543 # your client ID
OAUTH_DISCORD_SECRET=xJHGApsadOPUIAsdoih # your client secret
```

5. Back in the browser: click on "Add Redirect"
6. enter the URL: `https://YOURINSTANCE/oauth/discord/verify`, replace `YOURINSTANCE` with your instance domain
7. If you are on docker, restart the containers, on bare metal execute the `post-upgrade` script
8. When you go to the login page you should see a button to "Continue with Discord"

### Authentik

Self-hosted, https://goauthentik.io/

```ini
OAUTH_AUTHENTIK_ID=3245498543 # your client ID
OAUTH_AUTHENTIK_SECRET=xJHGApsadOPUIAsdoih # your client secret
OAUTH_AUTHENTIK_BASE_URL=
```

### Privacy Portal

You need a Privacy Portal account, if you do not have one, yet, go and create one: https://app.privacyportal.org/

1. Go to https://app.privacyportal.org/settings/developers and create a new application. Add a meaningful name.
   - Insert `https://YOURINSTANCE` as the "Homepage URL" (replace `YOURINSTANCE` with the URL of your instance).
   - Insert `https://YOURINSTANCE/oauth/privacyportal/verify` as the "Callback URL" (replace `YOURINSTANCE` with the URL of your instance).
2. Click "Register" to save the application.
3. You may change icon, homepage URL and callback URL in the "App info" tab.
4. Enable "Public access" in the "Access management" tab, so other Privacy Portal users can log into your instance.
5. In the "Credentials" tab, generate a new secret. This secret and the client ID from the same tab will go into your `.env` file:

```ini
OAUTH_PRIVACYPORTAL_ID=3245498543 # your client ID
OAUTH_PRIVACYPORTAL_SECRET=xJHGApsadOPUIAsdoih # your client secret
```

### Azure

https://login.microsoftonline.com

```ini
OAUTH_AZURE_ID=3245498543 # your client ID
OAUTH_AZURE_SECRET=xJHGApsadOPUIAsdoih # your client secret
OAUTH_AZURE_TENANT=
```

### Any OpenID Connect provider

The providers above are each bound to one product. This one is configured
rather than compiled in, so it works with any provider that implements OpenID
Connect: Pocket ID, Kanidm, Dex, Ory Hydra, or an in-house issuer.

Register a confidential client with your provider, with the callback URL
`https://YOURINSTANCE/oauth/oidc/verify`, then set three variables:

```ini
OAUTH_OIDC_ISSUER=https://idp.example.com # the issuer, exactly as the provider spells it
OAUTH_OIDC_ID=3245498543 # your client ID
OAUTH_OIDC_SECRET=xJHGApsadOPUIAsdoih # your client secret
```

The authorization, token, userinfo and JWKS endpoints are read from
`{issuer}/.well-known/openid-configuration`, which is cached for a day. The
username is taken from the `preferred_username` claim unless
`OAUTH_OIDC_USERNAME_CLAIM` names another one.

#### The login button

Set `OAUTH_OIDC_DISPLAY_NAME` to whatever your members call the place they are
signing in to. It is worth setting: the fallback is the words "OpenID
Connect", which is accurate and means nothing to anyone who does not already
know what OIDC is.

```ini
OAUTH_OIDC_DISPLAY_NAME=Example Ltd
```

The name cannot be discovered. Neither OpenID Connect Discovery nor RFC 8414
gives a provider anywhere to publish its own name or logo (`client_name` and
`logo_uri` are client metadata, describing your application to the provider,
not the other way round), so this has to be configuration.

The icon is a different matter, and Mbin goes looking. It tries
`{issuer}/favicon.ico`, then the `<link rel="icon">` of the issuer's own home
page, caches what it finds for a day, and inlines it on the button. The second
attempt matters more than it sounds: a single-page provider often serves its
application shell for any unknown path, so `/favicon.ico` answers 200 with HTML
and no icon while the shell names the real one. A declared icon is only
followed when it stays on the issuer's own origin, and redirects are not
followed at all, so the provider cannot send this fetch anywhere else.
The image is embedded rather than linked, so rendering the login page makes no
request to your provider and it works when the provider is reachable from the
Mbin container but not from a member's browser. Anything that is not a small
image (wrong content type, larger than 32 KB, an error, a timeout) falls back
to a generic lock icon, and a failed fetch is retried an hour later rather than
on every page load.

To skip the fetch entirely:

```ini
OAUTH_OIDC_FETCH_ICON=false
```

PKCE is always used, and the `id_token` is verified: its signature against the
provider's published keys, its issuer against the value you configured, its
audience against your client ID, its expiry (which must be present), and its
nonce against the value Mbin sent. The subject in the userinfo response must
match the subject in the token.

The discovery document must name the same issuer you configured. A document
that claims another issuer is rejected, as OpenID Connect Discovery requires.

#### Matching on email

A member who has never signed in through this provider is matched to an
existing account by email address, but only when the provider sends
`email_verified: true` for that address. Without it, the login is refused and
the reason is logged. Otherwise anyone who could register an unverified
address at your provider could sign in as whichever member owns it. If your
provider does not verify addresses, or does not send the claim, existing
members cannot be matched this way: link them yourself, as described below.

#### Letting your provider appoint administrators

By default it cannot, and that is the safe default: `OAUTH_OIDC_ADMIN_GROUP` is
empty, no group claim is requested or read, and the only way to make someone an
administrator is inside Mbin.

```sh
php bin/console mbin:user:admin <username>
```

That matches every other login provider Mbin ships. A provider tells Mbin who
somebody is; it does not tell Mbin what they may do.

If you would rather your provider decided, name the group:

```ini
OAUTH_OIDC_ADMIN_GROUP=mbin-admins
```

Mbin then requests the `groups` scope, and anyone whose group list contains that
name is granted `ROLE_ADMIN` when they log in. The list is read from the
verified `id_token` first and from the userinfo response only if the token
carries no `groups` claim at all. Matching is exact and case sensitive.

**Promotion is one-way.** Taking someone out of the group in your provider does
not take away their Mbin admin. This is deliberate. On an instance with
`MBIN_SSO_ONLY_MODE` there is no password login to fall back on, so a provider
that stops sending the claim (a renamed group, a scope that quietly stopped
being granted, a migration) would lock every administrator out of the instance
at the same moment. Removing admin stays a local, deliberate act:

```sh
php bin/console mbin:user:admin --remove <username>
```

Two things follow from this that are worth being explicit about. Whoever
controls that group in your provider can appoint Mbin administrators, so it
should be as closely held as the Mbin admin role itself. And because the claim
is read from a token whose signature, issuer, audience and nonce have all been
checked, this is only as trustworthy as the issuer you configured: point
`OAUTH_OIDC_ISSUER` at something you control.

#### When discovery is not enough

Some providers publish a discovery document that names addresses your instance
cannot reach, typically when Mbin and the provider are containers on the same
host. Any endpoint can be overridden, and an override always wins:

```ini
OAUTH_OIDC_AUTHORIZE_URL=
OAUTH_OIDC_TOKEN_URL=
OAUTH_OIDC_USERINFO_URL=http://idp:8080/userinfo
OAUTH_OIDC_JWKS_URL=
```

Setting all four skips the discovery request entirely. `OAUTH_OIDC_ISSUER` is
still required in that case, because it is what the `iss` claim is checked
against.

#### Moving an existing integration onto this provider

Accounts are linked per provider, so an account created through, say, the
Keycloak provider is not recognised by this one on sight. The next login falls
back to matching on email address and relinks the account, which is usually
enough.

If you would rather it were deterministic, copy the identifiers yourself before
the first login:

```sql
UPDATE "user" SET oauth_oidc_id = oauth_keycloak_id WHERE oauth_keycloak_id IS NOT NULL;
```

Only do this when both clients point at the same issuer. A subject identifier
is unique within one provider and means nothing outside it, so copying between
two different providers would link accounts to the wrong people.

#### Changing the issuer

The same rule applies when you point `OAUTH_OIDC_ISSUER` at a different
provider. The subject identifiers already stored belong to the old provider,
and the new one may hand out the same values to different people (sequential
numbers are common), which would sign them into the wrong accounts. Clear the
stored identifiers before the first login against the new provider:

```sql
UPDATE "user" SET oauth_oidc_id = NULL WHERE oauth_oidc_id IS NOT NULL;
```

Members are then matched again by verified email on their next login, or can
be linked by hand as above.
