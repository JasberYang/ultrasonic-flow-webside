# Rvision Catalog Gate

WordPress plugin for the R vision YK-C product homepage, member accounts, and email-based catalog delivery.

## Features

- Serves the product homepage directly from bundled site files.
- Redirects `www.rvisiontek.com` requests to `rvisiontek.com`.
- Opens an email form for catalog requests and sends `assets/catalog.pdf` as an attachment.
- Keeps member login, registration, and password reset available but separate from catalog delivery.
- Redirects `/catalog-download/` to the homepage catalog request form.
- Adds a settings page for the catalog file path and SMTP configuration.

## Install

1. Upload `rvision-catalog-gate` to `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Open `Rvision 会员`.
4. Leave the catalog path empty to use `assets/catalog.pdf`, or set a custom PDF path.
5. Open `设置 -> 固定链接` and save once if `/catalog-download/` does not work.
