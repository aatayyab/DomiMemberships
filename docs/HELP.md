# WP Memberships: Help Guide

This guide explains how to set up and use WP Memberships, written in plain language.

## Contents

1. [Getting started](#1-getting-started)
2. [For administrators](#2-for-administrators)
3. [For members](#3-for-members)
4. [For visitors](#4-for-visitors)
5. [Menus](#5-menus)
6. [Shortcodes reference](#6-shortcodes-reference)
7. [Troubleshooting](#7-troubleshooting)
8. [Uninstalling](#8-uninstalling)

---

## 1. Getting started

1. Go to **Plugins → Add New Plugin → Upload Plugin** and choose the ZIP.
2. Click **Install Now**, then **Activate**.
3. Three pages are created automatically:
   - **Join**: the application form
   - **Member Directory**: the searchable list of members
   - **Member Portal**: the private area for approved members
4. Open **Memberships** in the left menu.

## 2. For administrators

All administration happens on the **Memberships** page, which has five tabs.

### Applications
New applicants appear here. Click **Approve** to let them log in, or **Reject** to decline.

### Members
Everyone who is a member, with their status and organization.
- **Change level:** pick Individual or Corporate, then click **Save**.
- **Posting:** click **Enable posting** or **Disable posting** to control who can write content.
- **Suspend:** deactivates a member without deleting them. Use **Reinstate** to bring them back.

### Add Member
Create an account yourself.
- Choose **Active** to let the member log in straight away, or **Waiting for approval** to send them to the Applications list first.
- No email is sent. Share the login details with the member yourself.

### Member Content
Everything members have written.
- Items **awaiting approval** appear first. Click **Approve** to publish them as a regular blog post, or **Reject** to move them to the Trash.
- A red number on the tab shows how many items are waiting.
- Your own posts publish immediately.

### Settings
- **Accept new applications:** turn the Join form on or off.
- **Directory visibility:** choose which membership levels logged-out visitors can see.
- **Minimum password length:** the shortest password a member can use.
- **Waiting message:** the message shown to applicants who are not yet approved.
- **Pages:** shows whether each page exists, and recreates missing pages without changing existing ones.

## 3. For members

### Logging in
Log in with the email and password you were given. You land on the Member Portal.

### The menu bar
At the top of the portal you will find:
- **Member Portal**: the home page
- **Add Your Content**: jumps to the writing tool
- **My Details**: your profile form
- **Member Directory**: the list of members
- **My Membership**: your dashboard page
- **Log out**

### Uploading a profile photo
1. Go to **My Details** (on the portal) or **My Membership** (in the dashboard).
2. Under **Profile photo**, choose a JPG, PNG, or WebP image (up to 5 MB).
3. Click **Save My Details**.

Your photo is resized automatically. To remove it, tick **Remove current photo** and save.

### Writing content
1. Go to **Add Your Content** on the portal.
2. Enter a **title**.
3. Write in the **content box**. You can use the toolbar, or type Markdown:

| You type | You get |
|---|---|
| `## Heading` | A heading |
| `**bold**` | **bold** |
| `*italic*` | *italic* |
| `- item` | A bulleted list |
| `1. item` | A numbered list |
| `> quote` | A quote |
| `[link text](https://example.com)` | A link |

4. Click **Preview** to see how it will look.
5. Choose who can see it while it waits: **Members only** or **Private**.
6. Click **Submit for Approval**.

Your post will be published once the administrator approves it. You can't edit it after approval. To change it, delete it and submit a new version.

### Keyboard shortcuts in the editor
- **Ctrl+B** (Cmd+B on Mac): bold
- **Ctrl+I** (Cmd+I on Mac): italic

### Your content list
- **Awaiting approval**: waiting for the administrator.
- **Published**: live on the site. Click **View** to read it.
- **Edit** and **Delete** are available for items still awaiting approval. Delete is available for everything you have written.

## 4. For visitors

- **Join:** fill in the application form. You will see a message that your application is under review.
- **Member Directory:** search members by name or keyword. The public list shows only the membership levels the administrator has chosen.
- **Member posts:** published posts are regular blog posts and can be read by anyone.

## 5. Menus

- **Logged out:** the menu shows **Join** and **Login**.
- **Logged in:** **Join** and **Login** are hidden, and a **Logout** link appears.
- **Member Directory:** always visible.

If Join or Login still shows after logging in, clear your browser cache (Ctrl+F5) and check that the plugin is up to date.

## 6. Shortcodes reference

| Shortcode | What it does |
|---|---|
| `[wpm_register]` | The application form |
| `[wpm_directory]` | The searchable member directory |
| `[wpm_member_nav]` | The member menu bar |
| `[wpm_my_content]` | The Markdown editor and content lists |
| `[wpm_profile]` | Photo, details, and password form |
| `[wpm_restrict tier="individual"]…[/wpm_restrict]` | Content for individual and corporate members |
| `[wpm_restrict tier="corporate"]…[/wpm_restrict]` | Content for corporate members only |

Example:

[wpm_restrict tier="corporate"] Corporate members: download the templates below. [/wpm_restrict]


## 7. Troubleshooting

| Problem | What to do |
|---|---|
| The Memberships menu is missing | Log in with an administrator account. |
| Pages show raw text such as `[wpm_register]` | Activate the plugin on the Plugins screen. |
| A member can't log in | Check **Memberships → Members** to confirm they are not Inactive or Pending. |
| The Add Your Content form is missing | Check that posting is enabled for the member. |
| The photo upload fails | Ask your host to enable the GD image library. Check that `wp-content/uploads` is writable. |
| The photo says "could not be processed" | Re-save the image as a standard JPG and try again. |
| Preview is blank | Check your internet connection. The preview loads its tools from the web. |
| Join still shows after logging in | Clear your browser cache. If it still shows, tell the plugin maintainers which theme you use. |
| Emails are not arriving | The plugin does not send emails. Share login details directly. |

## 8. Uninstalling

1. Go to **Plugins**, click **Deactivate**, then **Delete**.
2. The plugin removes its roles, settings, member profile data, profile photos, and unapproved submissions.
3. Approved posts stay on your site as normal posts.
4. Pages stay in place. Delete them under **Pages** if you no longer need them.

---

*Need more help? Open an issue on the project's GitHub page.*
