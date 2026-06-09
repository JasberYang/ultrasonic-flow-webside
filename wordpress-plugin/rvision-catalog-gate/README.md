# Rvision Catalog Gate

WordPress plugin for protected YK-C catalog downloads.

## Features

- Adds `/catalog-download/`, `/catalog-register/`, and `/catalog-verify/` routes.
- Captures required lead fields: company, email, phone, last name.
- Captures optional fields: first name, department, need type.
- Sends an email verification link before allowing download.
- Keeps verified visitors signed for 90 days by secure cookie.
- Streams `assets/catalog.pdf` only through the protected download route.
- Adds a WordPress admin lead list and CSV export.
- Adds a settings page for SMTP and catalog file path.

## Install

1. Upload `rvision-catalog-gate` to `wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Open `Rvision 下载名单 -> 下载设置`.
4. Fill SMTP settings:
   - SMTP host: `smtp.qq.com`
   - Port: `465`
   - Encryption: `SSL`
   - Username: `JackMa@rvisiontek.com`
   - SMTP password: paste the mailbox authorization code in WordPress admin only.
5. Open `设置 -> 固定链接` and save once if `/catalog-download/` does not work.

Do not commit SMTP authorization codes to Git.
