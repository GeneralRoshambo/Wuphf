# Wuphf

A tiny Twitter-style microblog: 140 characters, plain text only (links are rejected), no video, and no images except profile photos.
Plain PHP + SQLite, no Composer, no build step, no database to set up.

## Features
Sign up / log in, post (140 chars; the first 5 @usernames in a post don't count), delete own posts, replies and threads, #hashtags and @mentions, search (people, posts, #tags), a public feed anyone can read without logging in, follow / unfollow, "Following" and "Everyone" timelines, profiles with bio, photo (animated GIFs supported, up to 1 MB / 512 px) and one profile link, pagination.

Links are blocked in posts and bios. The single profile link field is the only place a URL is allowed; it is shown with `rel="nofollow ugc"`.

## Install on cPanel
1. Upload everything in this folder to `public_html` (or a subfolder / subdomain root) with File Manager or FTP.
2. Make sure `data/` and `avatars/` are writable by PHP (permissions `755`, or `775` if needed).
3. In cPanel > **Select PHP Version**, use PHP 8.1+ and enable `pdo_sqlite` and `gd` (usually already on; `mbstring` is optional).
4. Visit the site and sign up. The database file is created automatically in `data/`.

Requirements: Apache with `.htaccess` (the stock cPanel setup), PHP 8.1+.

## Install as an app (PWA)
Wuphf is an installable web app: a manifest, icons and a service worker are included. Browsers only offer "Install" / "Add to Home Screen" over **HTTPS**, so enable the free AutoSSL certificate in cPanel and use the `https://` address. If you host in a subfolder, it works as-is because all paths are relative.
The service worker caches only static files (CSS, JS, icons). Pages are always fetched live, so nothing personal is stored on the device; offline, a small "You're offline" page is shown. After changing static files, bump `V` in `sw.js` so installed copies refresh.

## Security notes
- `data/` (the database) is blocked from the web by `.htaccess`. If your host ignores `.htaccess`, move `data/` above `public_html` and change `db_file` in `config.php`.
- Passwords are hashed, forms are CSRF-protected, all output is escaped, and uploaded photos are re-encoded to 128×128 JPEGs.
- Link blocking is best-effort (URL schemes, `www.`, and common domain endings).

## Config
See `config.php` (site name, close registration, page size, post cooldown).
