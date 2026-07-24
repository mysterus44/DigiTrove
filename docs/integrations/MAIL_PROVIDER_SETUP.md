# Mail provider setup (P4-C0, D-035)

The secure delivery pipeline sends the download e-mail **synchronously inside the
queue worker** (the Mailable never implements `ShouldQueue`), so the raw tokens
never touch Redis, `jobs`, `failed_jobs`, the cache or a log. That safety only
holds if the transport is a real SMTP/API provider — **never `MAIL_MAILER=log`**
in any environment that emits real links, because the message body (with tokens)
would be written to `storage/logs`.

All examples below are **commented and inactive**. Put real secrets in `.env`
only; never commit them.

## SMTP (generic)

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
```

Laravel 13 reads `MAIL_SCHEME`. With `smtp`, Symfony Mailer negotiates STARTTLS
when the server advertises it; do not disable automatic TLS. Use `smtps` with
port 465 only when the provider's official documentation requires implicit TLS.

## Brevo (SMTP)

```dotenv
# MAIL_MAILER=smtp
# MAIL_SCHEME=smtp
# MAIL_HOST=smtp-relay.brevo.com
# MAIL_PORT=587
# MAIL_USERNAME=<brevo-login>
# MAIL_PASSWORD=<brevo-smtp-key>
```

## Mailgun (SMTP)

```dotenv
# MAIL_MAILER=smtp
# MAIL_SCHEME=smtp
# MAIL_HOST=smtp.mailgun.org
# MAIL_PORT=587
# MAIL_USERNAME=<postmaster@your-domain>
# MAIL_PASSWORD=<mailgun-smtp-password>
```

## Postmark (SMTP)

```dotenv
# MAIL_MAILER=smtp
# MAIL_SCHEME=smtp
# MAIL_HOST=smtp.postmarkapp.com
# MAIL_PORT=587
# MAIL_USERNAME=<postmark-server-token>
# MAIL_PASSWORD=<postmark-server-token>
```

## Resend (SMTP or API)

Resend's API transport requires the official Laravel transport package to be
installed first. Until it is, use its SMTP endpoint.

```dotenv
# MAIL_MAILER=smtp
# MAIL_SCHEME=smtp
# MAIL_HOST=smtp.resend.com
# MAIL_PORT=587
# MAIL_USERNAME=resend
# MAIL_PASSWORD=<resend-api-key>
```

## TODO(PRODUCTION) — for every provider

- create the merchant account;
- retrieve host/port/user/password or the API token;
- put the secrets in `.env` (never in versioned config);
- verify the sending domain;
- configure SPF, DKIM and DMARC;
- run a sandbox send;
- never commit the secrets.

No proprietary provider API is coded here, and no aggregator endpoint is invented.
