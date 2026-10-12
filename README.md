# Wuphf

A tiny Twitter-style microblog: 140 characters, plain text only (links are rejected), no video, and no images except profile photos.
Plain PHP + SQLite, no Composer, no build step, no database to set up. The site starts empty: no sample accounts or posts.

## Features
Sign up / log in, post (140 chars; the first 5 @usernames in a post don't count), delete own posts, replies and threads, #hashtags and @mentions, search (people, posts, #tags), a public feed anyone can read without logging in, like / dislike / repost / share buttons (reposts appear in your followers' feeds), follow / unfollow, "Following" and "Everyone" timelines, profiles with bio, photo (animated GIFs supported, up to 2 MB / 800 px; frames and loop are preserved and the file is rebuilt from its image data only) and one profile link, pagination.

Links are blocked in posts and bios. The single profile link field is the only place a URL is allowed; it is shown with `rel="nofollow ugc"`.

## Pages, blog and SEO (Admin > Content, SEO, Redirects)
Admins write **pages** (`/about`) and **blog posts** (`/blog/my-post`) in Markdown, with full control of how they appear in search:
- **Headings:** the page gets exactly one **H1** (its own field, or the title). In the body write `## H2`, `### H3`, `#### H4` exactly as you want them; they get anchor ids, and an optional table of contents. The editor shows a live **heading outline** and warns about extra H1s, skipped levels, empty or duplicate headings.
- **Slug:** editable, auto-generated from the title, checked for clashes. Changing the slug of a published item leaves an automatic **301 redirect**.
- **Meta:** title tag (with `%title%` / `%site%`), meta description, robots (index/noindex, follow/nofollow, noarchive and friends), canonical URL, Open Graph and Twitter card fields, focus keyword, featured image with alt text, excerpt, author name.
- **Structured data:** automatic JSON-LD (WebPage or BlogPosting, breadcrumbs, organization) or paste your own.
- **Checks while you type:** a live search-result preview, character counters and an SEO checklist (title and description length, keyword placement, alt text, internal links, word count).
- **Publishing:** draft, publish, or schedule for later; admins can preview drafts. The last 20 versions are kept and can be restored.
- **Links in the body:** `[text](https://example.com){nofollow sponsored ugc newtab}`. Raw HTML is never allowed in content; it is escaped.
- **Site-wide settings (Admin > SEO):** title template, home and blog titles and descriptions, default share image, Twitter handle, organization schema, Google and Bing verification, extra `robots.txt` lines, a "hide the whole site" switch for testing, custom code for the head and footer, pretty URLs on/off.
- **Redirects (Admin > Redirects):** 301/302 from any old path, with hit counts.
- **Generated for you:** `/sitemap.xml` (live, indexable pages, posts, blog and member profiles), `/robots.txt`, a blog feed at `/blog/feed.xml`, canonical tags, and `noindex` on login, settings and other private screens.
Submit `https://yourdomain/sitemap.xml` in Google Search Console and Bing Webmaster Tools.

**Pretty URLs** need Apache rewrites, which the included `.htaccess` provides (standard on cPanel). If your host blocks them, turn off "Pretty URLs" in Admin > SEO and the `/?p=...` addresses keep working. Member profiles also answer at `/Username`.

**Reserved names redirect to one member** (Admin > Names, default `Ryan`): visiting a reserved address such as `/support` sends people to that member's profile with a 302. A page you publish at the same address takes priority.

## Safety, moderation and admin
- **Block and mute** (three-dot menu on posts and profiles). Blocking removes follows both ways and stops the person following, replying to, or reacting to you; you stop seeing each other's posts. Muting just hides their posts from your feeds, silently. Manage both from **Settings > Account**.
- **Reports:** "Report post" or "Report account". Reports go to a private queue at `/?p=admin`; set `admin_email` to also get an email for each one.
- **Admins** are listed by username in `config.local.php` on the server (this file is never overwritten by deploys):
  ```php
  <?php return ['admins' => ['yourusername'], 'admin_email' => 'you@example.com', 'contact_email' => 'you@example.com'];
  ```
  **Everything else is in the browser:** open **Admin > Names** to reserve or release usernames (with a one-click set of common names such as admin, support and moderator), and **Admin > Accounts** to rename an account, make or remove admins, suspend or delete. Names in `config.local.php` show as *locked* and can only be changed in that file, as a safety net so you can never lock yourself out.
  An admin must have a **confirmed email**. A name in the admins list is **reserved**: nobody can sign up or rename to it while it is listed, so create or rename the account first (Settings > Account > Change username), confirm its email, and only then add the name to `config.local.php`.
  Admins can remove reported posts, suspend or restore accounts (suspended people are signed out at once and their posts are hidden), delete accounts, and download a database backup.
- **Rules and Privacy** pages live in `pages.php`. They are a plain-language starting point; have them reviewed for your situation.

## Backups
The whole site is one file, `data/wuphf.sqlite`. Two ways to protect it:
1. **Automatic copies on the server.** In cPanel > **Cron Jobs**, add a daily job: `/usr/local/bin/php /home/YOURUSER/public_html/backup.php` (adjust the path). It keeps the newest 14 copies in `data/backups/` (blocked from the web). cPanel's own account backups include them.
2. **Off-server copy.** As an admin, open `/?p=admin&tab=backup` and download one now and then.

## Notifications, bookmarks and more
Replies, mentions, likes, reposts and follows appear under the bell icon (with an unread count). Bookmarks are in the three-dot menu and the bookmark icon. Also included: a friendly 404 page, RSS (`/?p=rss`, or `&u=name` for one person), trending hashtags, "who to follow" suggestions, and `robots.txt`.

## Accounts and email
- Sign-up asks for an email and sends a confirmation link. Unconfirmed accounts can read but cannot post or follow.
- "Forgot your password?" emails a one-time link that expires in 1 hour. Using it also confirms the email, and it cancels every other outstanding link and code.
- Account page (**Settings > Account**): change password (signs out other devices), change email (confirmed at the new address first), download your data, delete your account.
- Optional two-step login: members turn on "Ask for a login code by email" in Settings, or set `force_2fa` in `config.php` for everyone. Codes are 6 digits, last 10 minutes, and allow 5 tries.
- Sign-up, login, reset and resend are rate limited. Tokens are stored hashed. Reset and confirm pages need a button press, so email link scanners cannot use them up.
- Email is sent with PHP `mail()` from `mail_from` in `config.php`. In cPanel, open **Email Deliverability** and make sure the domain shows valid SPF and DKIM, or messages will land in spam. To test without sending, set `mail_mode` to `log` and read `data/outbox.log`.

## Install on cPanel
1. Upload everything in this folder to `public_html` (or a subfolder / subdomain root) with File Manager or FTP.
2. Make sure `data/` and `avatars/` are writable by PHP (permissions `755`, or `775` if needed).
3. In cPanel > **Select PHP Version**, use PHP 8.1+ and enable `pdo_sqlite` and `gd` (usually already on; `mbstring` is optional).
4. Check `config.php`: `site_url` and `mail_from` must match your domain.
5. Visit the site and sign up. The database file is created automatically in `data/`.

Requirements: Apache with `.htaccess` (the stock cPanel setup), PHP 8.1+.

## Install as an app (PWA)
Wuphf is an installable web app: a manifest, icons and a service worker are included. Browsers only offer "Install" / "Add to Home Screen" over **HTTPS**, so enable the free AutoSSL certificate in cPanel and use the `https://` address. If you host in a subfolder, it works as-is because all paths are relative.
The service worker caches only static files (CSS, JS, icons). Pages are always fetched live, so nothing personal is stored on the device; offline, a small "You're offline" page is shown. After changing static files, bump `V` in `sw.js` so installed copies refresh.

## Updating the live site through the cPanel API
`tools/deploy.sh` uploads the app files with cPanel's `Fileman::upload_files` API, using an API token (cPanel > **Manage API Tokens**). It never uploads `data/`, uploaded photos, or `config.local.php`, so updates cannot touch your database or per-server settings. Put anything server specific (a different `site_url`, `mail_from`, `force_2fa`) in `config.local.php` on the server; it overrides `config.php`.

```
export CPANEL_HOST='your-server-hostname'
export CPANEL_USER='your-cpanel-username'
export CPANEL_TOKEN='paste-token-here'
export DEPLOY_DIR='public_html'
export SITE_URL='https://wuphf.lol'
./tools/deploy.sh
```
`CPANEL_HOST` is the cPanel server (often not the site's own domain), and `DEPLOY_DIR` is the web root relative to your home directory. Add `DRY_RUN=1` before `./tools/deploy.sh` to preview. The script runs on macOS and Linux.
To deploy automatically on every push to `main` (and the working branch listed in the workflow), add those values as repository secrets (`CPANEL_HOST`, `CPANEL_USER`, `CPANEL_TOKEN`, optional `DEPLOY_DIR`, `SITE_URL`) and the workflow in `.github/workflows/deploy.yml` does the rest. Each deploy stamps a new cache name into `sw.js`, so installed copies of the app refresh.

## Security notes
- `data/` (the database) is blocked from the web by `.htaccess`. If your host ignores `.htaccess`, move `data/` above `public_html` and change `db_file` in `config.php`.
- Passwords are hashed, forms are CSRF-protected, all output is escaped, and uploaded photos are re-encoded to 128×128 JPEGs.
- Link blocking is best-effort (URL schemes, `www.`, and common domain endings).

## Config
See `config.php` (site name, close registration, page size, post cooldown).
