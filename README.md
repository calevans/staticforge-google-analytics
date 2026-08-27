#  [StaticForge](https://calevans.com/staticforge) GoogleAnalytics

A StaticForge feature package.

Copyright 2025, Cal Evans<br />
License: MIT<br />

## Installation

```bash
composer require calevans/staticforge-google-analytics
php vendor/bin/staticforge feature:setup GoogleAnalytics

```

## Configuration

Add the following to your `siteconfig.yaml`:

```yaml
google_analytics:
  enabled: true                  # bool.   Default false (absent == off)
  tracking_id: G-XXXXXXXXXX      # string. Optional. Overridden by GOOGLE_ANALYTICS_ID
  debug: false                   # bool.   Default false
  exclude:                       # list of globs. Default []
    - drafts/*
```

And add your tracking ID to your `.env` file:

```dotenv
GOOGLE_ANALYTICS_ID="G-XXXXXXXXXX"
```

Set the ID in **either** `.env` or `siteconfig.yaml`, not both — see below.

### Tracking ID

Both GA4 (`G-XXXXXXXXXX`) and Google Tag Manager (`GTM-XXXXXXX`) container IDs
are accepted. The ID is resolved from two possible sources, in order:

1. `GOOGLE_ANALYTICS_ID` in `.env` (trimmed, non-empty)
2. `google_analytics.tracking_id` in `siteconfig.yaml` (trimmed, non-empty)

If neither source provides an ID, injection is skipped for the whole build
and a warning is logged. If an ID is provided but does not match the
expected `G-`/`GTM-` format, injection is also skipped and an error is
logged — a malformed ID would otherwise produce a page that looks
instrumented but silently collects nothing.

### Kill switch

`GOOGLE_ANALYTICS_ENABLED` in `.env` can be set to `false`, `0`, `off`, or
`no` (case-insensitive) to force-disable injection regardless of what
`siteconfig.yaml` says. This is a one-way switch: there is no
`GOOGLE_ANALYTICS_ENABLED=true` that turns the feature on — it only
suppresses. It exists so a non-production environment (e.g. a staging
`.env`) can guarantee analytics never fires without having to also edit
`siteconfig.yaml`. Any other value, including an empty string or the
variable being absent entirely, leaves injection unaffected. That last part
is deliberate: `.env` values are always strings, so a `GOOGLE_ANALYTICS_ENABLED=""`
left behind by a copied `.env.example` must not silently switch analytics off
in production.

### Debug mode

Setting `google_analytics.debug: true` adds `{ 'debug_mode': true }` to the
`gtag('config', ...)` call, which enables Google Analytics DebugView.

### Excluding pages

`google_analytics.exclude` is a list of glob patterns matched against each
page's output path, relative to `OUTPUT_DIR`, with backslashes normalized to
forward slashes. Matching uses `fnmatch()` without `FNM_PATHNAME`, so `*`
crosses `/` — `drafts/*` excludes every page under `drafts/`, including
`drafts/2024/post.html`, not just files directly inside `drafts/`. Matching
is case-sensitive.
