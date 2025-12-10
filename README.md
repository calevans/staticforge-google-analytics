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
# Google Analytics Configuration
google_analytics:
  enabled: true
  # tracking_id is set in .env as GOOGLE_ANALYTICS_ID
```

And add your tracking ID to your `.env` file:

```dotenv
GOOGLE_ANALYTICS_ID="UA-XXXXX-Y"
```
