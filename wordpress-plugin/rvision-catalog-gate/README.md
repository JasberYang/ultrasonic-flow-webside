# Rvision Catalog Gate

WordPress plugin for public YK-C catalog downloads.

## Features

- Adds `/catalog-download/` for direct public PDF download.
- Redirects old `/catalog-register/`, `/catalog-login/`, and `/catalog-verify/` links to the direct download.
- Does not require login, registration, email verification, or lead capture.
- Streams `assets/catalog.pdf` through the download route.
- Adds a settings page for the catalog file path.

## Install

1. Upload `rvision-catalog-gate` to `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Open `Rvision 目录下载`.
4. Leave the catalog path empty to use `assets/catalog.pdf`, or set a custom PDF path.
5. Open `设置 -> 固定链接` and save once if `/catalog-download/` does not work.
