# WP Memberships

A WordPress plugin for membership sites. Visitors apply online or are added by you. Members get a private portal with a profile photo, a Markdown editor, and an approval pipeline that publishes their posts to your blog.

- **Landing page:** https://aatayyab.github.io/wpmemberships/
- **Brochure:** https://aatayyab.github.io/wpmemberships/brochure.html
- **Download:** https://github.com/aatayyab/wpmemberships/releases/latest
- **Help:** [docs/HELP.md](docs/HELP.md)
- **Release notes:** [CHANGELOG.md](CHANGELOG.md)

## Features

- Online application form, plus manual member creation
- Approval queue, membership levels (Individual, Corporate), suspend and reinstate
- Per-member posting permission
- Member portal with personal welcome, menu bar, and details form
- Profile photos, resized automatically (thumbnail and full size)
- Markdown editor with toolbar, Write and Preview tabs, and keyboard shortcuts
- Approved content becomes regular WordPress posts
- Searchable member directory with photo cards and profiles
- Menus hide Join and Login for logged-in users
- Clean uninstall

## Requirements

- WordPress 6.0 or newer
- PHP 8.1 or newer
- GD or Imagick image library (GD is usually enabled on most hosts)

## Installation

### Option 1: Download the ZIP (recommended)

1. Go to the [Releases page](https://github.com/aatayyab/wpmemberships/releases/latest).
2. Download `wp-memberships-3.1.0.zip`.
3. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**.
4. Choose the ZIP, click **Install Now**, then **Activate**.

### Option 2: Build the ZIP yourself

```bash
git clone https://github.com/aatayyab/wpmemberships.git
cd wpmemberships
bash scripts/build-zip.sh
