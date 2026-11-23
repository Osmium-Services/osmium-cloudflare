---
title: Clear the Cloudflare cache automatically
tags: cloudflare, cache, purge, development mode, api token, zone id
---

If your site is behind Cloudflare, **Cloudflare** clears its cache for you, so visitors see your changes straight away.

## Set it up

1. In Cloudflare, create an API token under **My Profile > API Tokens** with the **Zone > Cache Purge** permission. Add **Zone Settings > Edit** for development mode and **Zone > Zone > Read** for the status line.
2. Open **Cloudflare** settings and enter the **API token** and **Zone ID**.
3. In **Hostnames**, list this site's addresses, one per line, such as `www.example.com`. Staging and live can share a zone, and each site lists only its own.
4. Tick **Enabled** and press **Save Settings**.

Once on, every change saved in the admin or by an assistant clears the cache, at most four times a minute because Cloudflare's free plan allows about five.

Use **Test connection** to check the setup. The **Status** area shows the zone and whether development mode is on.
