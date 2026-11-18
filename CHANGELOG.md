

---


## File: `CHANGELOG.md` (release notes)


```markdown
# Changelog

All notable changes to WP Memberships are listed here, newest first.

## 3.1.0

**Markdown editor for member content**
- The WordPress editor is replaced by a Markdown editor with a formatting toolbar (bold, italic, headings, lists, quote, code, link, table).
- Write and Preview tabs, live rendering, word counter.
- Keyboard shortcuts: Ctrl+B (bold) and Ctrl+I (italic).
- The Format dropdown is removed. All content is Markdown.

**Safety**
- All member content is sanitized before saving, including content from administrators.
- If the editor's libraries fail to load, the server converts the Markdown itself, so submissions are never lost.

## 3.0.0

**Menus**
- Join and Login links are hidden for every logged-in user, including administrators.
- A Logout link appears for logged-in users. It can be hidden with `wpm_show_logout_in_menu`.
- Member Directory stays visible to everyone.
- Works with classic menus, block navigation, and a footer fallback for other themes.

**Content**
- Approved submissions become regular WordPress posts with their own address.
- Published posts are visible to everyone, logged in or not.
- Content approved under earlier versions is converted automatically on first run.

## 2.9.0

- Approved content is published as regular posts.
- Member portal and profile pages list published posts.
- Menu: Join hidden and Login replaced with Logout for logged-in users (first attempt).

## 2.8.1

- Small photos are no longer enlarged. Thumbnails adapt to small images.

## 2.8.0

- Profile photos are converted to JPG before resizing, which fixes most unsupported-image errors.
- Specific error messages for each photo step, with optional debug logging.

## 2.7.0

- Profile photos: thumbnail for the directory, full size on member profiles.
- Photos are stored in `wp-content/uploads/wpm-avatars/` and deleted on removal or uninstall.

## 2.6.0

- Per-member posting permission.
- Rich editor with HTML and Markdown options.
- Content moderation: submissions wait for administrator approval.

## 2.5.0

- Manual member creation from the dashboard, with optional "waiting for approval" status.

## 2.4.0

- Individual pages for each member post, with access rules.
- Member profiles in the directory list the member's content.
- Welcome heading in the portal.

## 2.3.0

- Member Portal builds its own content automatically, so the stored page text no longer matters.

## 2.2.0

- Member menu bar, content form, and login redirect to the portal.

## 2.1.0

- Front-end details form on the Member Portal.

## 2.0.0

- Dashboard-based administration: Applications, Members, and Settings tabs.
- "My Membership" page for every member.
- Email features removed.

## 1.0.0

- First release: applications, approvals, content gating, and a member directory.
