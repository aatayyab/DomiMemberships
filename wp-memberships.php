<?php
/**
 * Plugin Name:       WP Memberships
 * Description:       Membership applications, manual member creation, profile photos, per-member posting permissions, a Markdown editor for member content, approved content published as site posts, a member directory, and member-only pages, all managed from one dashboard page.
 * Version:           3.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-memberships
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WPM_VERSION' ) ) {
	define( 'WPM_VERSION', '3.1.0' );
}

/* =========================================================================
 * 0. SETTINGS & HELPERS
 * ========================================================================= */

/** Default values for every setting. */
function wpm_defaults(): array {
	return array(
		'registration_open' => 1,
		'public_tiers'      => array( 'corporate' ),
		'min_password'      => 8,
		'pending_message'   => 'Your membership application is currently under review by the secretariat.',
	);
}

/** Read one setting, falling back to its default. */
function wpm_setting( string $key ) {
	$saved = get_option( 'wpm_settings', array() );
	$all   = array_merge( wpm_defaults(), is_array( $saved ) ? $saved : array() );
	return $all[ $key ] ?? null;
}

/** Read a raw string from $_POST or $_GET. Sanitize at the point of use. */
function wpm_input( string $key, string $method = 'post' ): string {
	$source = ( 'get' === $method ) ? $_GET : $_POST; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
		return '';
	}
	return wp_unslash( $source[ $key ] );
}

/** Membership level => role. */
function wpm_tier_roles(): array {
	return array(
		'individual' => 'wpm_individual',
		'corporate'  => 'wpm_corporate',
	);
}

/** Human-readable label for a status or level. */
function wpm_tier_label( string $tier ): string {
	$labels = array(
		'individual' => 'Individual',
		'corporate'  => 'Corporate',
		'pending'    => 'Pending',
		'rejected'   => 'Inactive',
	);
	return $labels[ $tier ] ?? '—';
}

/** Returns 'individual', 'corporate', 'pending', 'rejected', or '' (not a member). */
function wpm_get_user_tier( ?WP_User $user = null ): string {
	$user = $user ?? wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}
	$roles = (array) $user->roles;
	if ( in_array( 'wpm_pending', $roles, true ) ) {
		return 'pending';
	}
	if ( in_array( 'wpm_rejected', $roles, true ) ) {
		return 'rejected';
	}
	foreach ( wpm_tier_roles() as $tier => $role ) {
		if ( in_array( $role, $roles, true ) ) {
			return $tier;
		}
	}
	return '';
}

/** True for approved members (individual or corporate). */
function wpm_is_active_member(): bool {
	return in_array( wpm_get_user_tier(), array( 'individual', 'corporate' ), true );
}

/**
 * Can this user create content?
 * Administrators always can. Members can only if the admin has switched their permission on.
 */
function wpm_user_can_post( ?WP_User $user = null ): bool {
	$user = $user ?? wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return true;
	}
	if ( ! in_array( wpm_get_user_tier( $user ), array( 'individual', 'corporate' ), true ) ) {
		return false;
	}
	return (bool) get_user_meta( $user->ID, '_wpm_can_post', true );
}

/**
 * Can the current visitor see content gated at $required level?
 * Administrators always pass. Corporate content needs corporate members.
 */
function wpm_user_can_access( string $required = 'individual' ): bool {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	$tier = wpm_get_user_tier();
	if ( 'corporate' === $required ) {
		return 'corporate' === $tier;
	}
	return in_array( $tier, array( 'individual', 'corporate' ), true );
}

/** Slugs of pages that only approved members can open. Filterable. */
function wpm_protected_slugs(): array {
	return (array) apply_filters( 'wpm_protected_slugs', array( 'member-portal' ) );
}

/** Levels visible to logged-out visitors in the directory. Filterable. */
function wpm_public_tiers(): array {
	return (array) apply_filters( 'wpm_public_tiers', (array) wpm_setting( 'public_tiers' ) );
}

/** URL of the Member Portal page, with a fallback if it was deleted. */
function wpm_portal_url(): string {
	$page = get_page_by_path( 'member-portal' );
	return $page ? get_permalink( $page ) : home_url( '/member-portal/' );
}

/** URL of the Member Directory page, with a fallback if it was deleted. */
function wpm_directory_url(): string {
	$page = get_page_by_path( 'member-directory' );
	return $page ? get_permalink( $page ) : home_url( '/member-directory/' );
}

/** Messages shown after an action. Keyed by the code passed in the URL. */
function wpm_message_text( string $code ): string {
	$messages = array(
		'registered'     => 'Thank you! Your application has been received and is under review.',
		'closed'         => 'New applications are currently closed.',
		'missing'        => 'Please fill in all required fields.',
		'invalid_email'  => 'Please enter a valid email address.',
		'weak_password'  => 'Your password does not meet the minimum length.',
		'bad_tier'       => 'Please choose a valid membership level.',
		'exists'         => 'An account with that email address already exists.',
		'pw_wrong'       => 'Your current password is incorrect.',
		'profile_saved'  => 'Your details have been saved.',
		'photo_too_big'  => 'The photo is too large. The maximum size is 5 MB.',
		'photo_type'     => 'Please upload a JPG, PNG, or WebP image.',
		'photo_upload'   => 'The photo did not finish uploading. Try a smaller file or a different image.',
		'photo_editor'   => 'The server could not open this image. Try re-saving it as a standard JPG from your phone or photo editor.',
		'photo_resize'   => 'The server could not resize this image. Try a different photo.',
		'photo_save'     => 'The resized photo could not be saved. Check that the wp-content/uploads/wpm-avatars folder is writable.',
		'entry_saved'    => 'Your content has been published as a site post.',
		'entry_pending'  => 'Your content has been submitted and is waiting for approval.',
		'entry_approved' => 'Content approved and published as a site post.',
		'entry_rejected' => 'Content rejected and moved to the Trash.',
		'entry_deleted'  => 'The content has been deleted.',
		'missing_title'  => 'Please enter a title.',
		'member_created' => 'Member created.',
		'approved'       => 'Applicant approved. They can now log in.',
		'rejected'       => 'Application rejected.',
		'suspended'      => 'Membership suspended.',
		'reinstated'     => 'Membership reinstated.',
		'tier_changed'   => 'Membership level updated.',
		'posting_on'     => 'Content posting enabled for this member.',
		'posting_off'    => 'Content posting disabled for this member.',
		'settings_saved' => 'Settings saved.',
		'pages_created'  => 'Missing pages have been created.',
		'not_allowed'    => 'That action is not allowed.',
		'error'          => 'Something went wrong. Please try again.',
	);
	return $messages[ $code ] ?? '';
}

/** Message codes that should display as success (green). */
function wpm_success_codes(): array {
	return array(
		'registered', 'profile_saved', 'entry_saved', 'entry_pending', 'entry_approved', 'entry_rejected',
		'entry_deleted', 'member_created', 'approved', 'rejected', 'suspended', 'reinstated',
		'tier_changed', 'posting_on', 'posting_off', 'settings_saved', 'pages_created',
	);
}

/** Send the user back to the page they came from, with a message code. */
function wpm_back( string $code ): void {
	$url = wp_get_referer() ?: home_url( '/' );
	$url = add_query_arg( 'wpm_msg', $code, remove_query_arg( array( 'wpm_msg', 'wpm_edit' ), $url ) );
	wp_safe_redirect( $url );
	exit;
}

/** Render the message from the URL once per page, styled for the front end or the dashboard. */
function wpm_notice_html( string $context = 'front' ): string {
	static $shown = false;
	if ( $shown ) {
		return '';
	}
	$code = isset( $_GET['wpm_msg'] ) ? sanitize_key( wp_unslash( $_GET['wpm_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$text = wpm_message_text( $code );
	if ( '' === $text ) {
		return '';
	}
	$shown   = true;
	$success = in_array( $code, wpm_success_codes(), true );

	if ( 'admin' === $context ) {
		return '<div class="notice ' . ( $success ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}
	return '<div class="wpm-notice ' . ( $success ? 'wpm-success' : 'wpm-error' ) . '">' . esc_html( $text ) . '</div>';
}

/** Front-end CSS, printed once per page. */
function wpm_styles(): string {
	static $printed = false;
	if ( $printed ) {
		return '';
	}
	$printed = true;

	return '<style>
		.wpm{--wpm-accent:#1d4ed8;--wpm-border:#e2e8f0;--wpm-muted:#64748b;max-width:760px;margin:1.5rem auto;line-height:1.5;color:inherit}
		.wpm *{box-sizing:border-box}
		.wpm-form{display:grid;gap:.9rem;padding:1.5rem;border:1px solid var(--wpm-border);border-radius:12px;background:#fff;color:#0f172a}
		.wpm label{display:grid;gap:.3rem;font-weight:600;font-size:.92rem}
		.wpm label.wpm-check{display:flex;align-items:center;gap:.5rem;font-weight:400}
		.wpm input[type=text],.wpm input[type=email],.wpm input[type=password],.wpm input[type=number],.wpm select,.wpm textarea{width:100%;padding:.6rem .75rem;border:1px solid #cbd5e1;border-radius:8px;font:inherit;font-weight:400;background:#fff;color:#0f172a}
		.wpm input[type=file]{font:inherit}
		.wpm textarea{min-height:110px;resize:vertical}
		.wpm button{padding:.65rem 1.1rem;border:0;border-radius:8px;background:var(--wpm-accent);color:#fff;font:inherit;font-weight:600;cursor:pointer;justify-self:start}
		.wpm-notice{padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.95rem}
		.wpm-success{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
		.wpm-error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
		.wpm-info{background:#f1f5f9;border:1px solid var(--wpm-border);color:#334155}
		.wpm-info a{color:var(--wpm-accent)}
		.wpm-nav{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem}
		.wpm-nav a{padding:.5rem .95rem;border:1px solid var(--wpm-border);border-radius:999px;text-decoration:none;color:var(--wpm-accent);background:#fff;font-weight:600;font-size:.9rem}
		.wpm-nav a:hover{background:#eff6ff}
		.wpm-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;align-items:end;margin-bottom:1.25rem}
		.wpm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:1rem}
		.wpm-card,.wpm-entry{border:1px solid var(--wpm-border);border-radius:12px;padding:1rem;background:#fff;color:#0f172a}
		.wpm-card h3,.wpm-entry h4{margin:0 0 .25rem}
		.wpm-card h3 a{color:inherit;text-decoration:none}
		.wpm-card h3 a:hover{color:var(--wpm-accent);text-decoration:underline}
		.wpm-card h3{font-size:1.05rem}
		.wpm-entry{margin-bottom:1rem}
		.wpm-entry h4{font-size:1rem}
		.wpm-entry h4 a{color:inherit;text-decoration:none}
		.wpm-entry h4 a:hover{color:var(--wpm-accent);text-decoration:underline}
		.wpm-entry-actions{margin-top:.6rem;font-size:.9rem}
		.wpm-entry-actions a{margin-right:.9rem;color:var(--wpm-accent)}
		.wpm-entry-body{margin:1rem 0}
		.wpm-entry-body img{max-width:100%;height:auto}
		.wpm-photo-thumb,.wpm-photo-preview{width:120px;height:120px;border-radius:50%;object-fit:cover;display:block;margin-bottom:.5rem}
		.wpm-photo-full{display:block;max-width:100%;height:auto;border-radius:12px;margin:.5rem 0 1rem}
		.wpm-meta{color:var(--wpm-muted);font-size:.88rem;margin:.15rem 0}
		.wpm-meta a{color:var(--wpm-accent)}
		.wpm-badge{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:.75rem;font-weight:600;background:#e0e7ff;color:#3730a3;margin-top:.4rem}
		.wpm-badge.wpm-corp{background:#fef3c7;color:#92400e}
		.wpm-badge.wpm-waiting{background:#fef9c3;color:#854d0e}
		.wpm-section-title{margin:1.5rem 0 .5rem}
		@media (max-width:480px){.wpm-form{padding:1rem}}
	</style>';
}

/** Sort a list of posts newest first. */
function wpm_sort_newest( array $posts ): array {
	usort(
		$posts,
		static function ( $a, $b ) {
			return strtotime( $b->post_date ) <=> strtotime( $a->post_date );
		}
	);
	return $posts;
}

/* =========================================================================
 * 1. ROLES, CONTENT TYPE, ACTIVATION & DEFAULT PAGES
 * ========================================================================= */

/** Register the membership roles. Runs on every load because it is cheap. */
function wpm_register_roles(): void {
	if ( ! get_role( 'wpm_pending' ) ) {
		add_role( 'wpm_pending', 'Pending Applicant', array( 'read' => false ) );
	}
	if ( ! get_role( 'wpm_rejected' ) ) {
		add_role( 'wpm_rejected', 'Inactive Member', array( 'read' => false ) );
	}
	if ( ! get_role( 'wpm_individual' ) ) {
		add_role( 'wpm_individual', 'Individual Member', array( 'read' => true ) );
	}
	if ( ! get_role( 'wpm_corporate' ) ) {
		add_role( 'wpm_corporate', 'Corporate Member', array( 'read' => true ) );
	}
}
add_action( 'init', 'wpm_register_roles' );

/**
 * Pending member submissions are stored as "wpm_entry" items until approved.
 * Approved items are converted to regular posts (see wpm_publish_entry_as_post()).
 */
function wpm_register_entry_type(): void {
	register_post_type(
		'wpm_entry',
		array(
			'labels'              => array(
				'name'          => 'Member Submissions',
				'singular_name' => 'Member Submission',
			),
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'query_var'           => true,
			'rewrite'             => array(
				'slug'       => 'member-content',
				'with_front' => false,
			),
			'supports'            => array( 'title', 'editor', 'author' ),
		)
	);
}
add_action( 'init', 'wpm_register_entry_type' );

/** Flush permalinks once after an update, so the new content addresses work straight away. */
function wpm_maybe_flush_rewrites(): void {
	if ( get_option( 'wpm_rewrite_version' ) !== WPM_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'wpm_rewrite_version', WPM_VERSION, false );
	}
}
add_action( 'init', 'wpm_maybe_flush_rewrites', 99 );

register_activation_hook( __FILE__, 'wpm_activate' );

/** Runs once, when the owner clicks Activate. */
function wpm_activate(): void {
	wpm_register_roles();
	wpm_register_entry_type();
	if ( false === get_option( 'wpm_settings', false ) ) {
		add_option( 'wpm_settings', wpm_defaults() );
	}
	wpm_create_pages();
	flush_rewrite_rules( false );
	update_option( 'wpm_rewrite_version', WPM_VERSION, false );
	set_transient( 'wpm_just_activated', 1, 5 * MINUTE_IN_SECONDS );
}

/** The three pages the plugin needs. */
function wpm_default_pages(): array {
	return array(
		'join'             => array(
			'title'   => 'Join',
			'content' => '[wpm_register]',
		),
		'member-directory' => array(
			'title'   => 'Member Directory',
			'content' => '[wpm_directory]',
		),
		'member-portal'    => array(
			'title'   => 'Member Portal',
			'content' => '[wpm_member_nav]',
		),
	);
}

/** Create any missing pages. Existing pages are never overwritten here. */
function wpm_create_pages(): void {
	foreach ( wpm_default_pages() as $slug => $page ) {
		if ( get_page_by_path( $slug ) ) {
			continue;
		}
		wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $page['title'],
				'post_name'    => $slug,
				'post_content' => $page['content'],
				'post_author'  => get_current_user_id(),
			)
		);
	}
}

/** Show a "ready" notice right after activation. */
function wpm_activation_notice(): void {
	if ( ! get_transient( 'wpm_just_activated' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_transient( 'wpm_just_activated' );
	echo '<div class="notice notice-success is-dismissible"><p><strong>WP Memberships is ready.</strong> '
		. 'Open <a href="' . esc_url( admin_url( 'admin.php?page=wpm-memberships' ) ) . '">Memberships</a> in the left menu to manage everything.</p></div>';
}
add_action( 'admin_notices', 'wpm_activation_notice' );

/** Send approved members to the Member Portal after they log in. */
function wpm_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && ! is_wp_error( $user ) && ! user_can( $user, 'manage_options' ) && in_array( wpm_get_user_tier( $user ), array( 'individual', 'corporate' ), true ) ) {
		return wpm_portal_url();
	}
	return $redirect_to;
}
add_filter( 'login_redirect', 'wpm_login_redirect', 10, 3 );

/** Show "Welcome, [name]" as the Member Portal heading instead of the page title. */
function wpm_portal_title( $title, $post_id = 0 ) {
	if ( ! is_user_logged_in() || ! in_the_loop() ) {
		return $title;
	}
	$page = get_page_by_path( 'member-portal' );
	if ( ! $page || (int) $post_id !== (int) $page->ID ) {
		return $title;
	}
	return 'Welcome, ' . wp_get_current_user()->display_name;
}
add_filter( 'the_title', 'wpm_portal_title', 10, 2 );

/* =========================================================================
 * 2. MENU: JOIN AND LOGIN HIDDEN FOR LOGGED-IN USERS
 * ========================================================================= */

/**
 * Show a Logout link in the menu for logged-in users?
 * To hide Logout as well, add this to a snippet or functions.php:
 *   add_filter( 'wpm_show_logout_in_menu', '__return_false' );
 */
function wpm_show_logout_in_menu(): bool {
	return (bool) apply_filters( 'wpm_show_logout_in_menu', true );
}

/** Last part of a link's path, e.g. "https://site.com/join/" gives "join". */
function wpm_href_last_segment( string $href ): string {
	$path = (string) wp_parse_url( $href, PHP_URL_PATH );
	return strtolower( basename( rtrim( $path, '/' ) ) );
}

/** True if a menu link is a logout action. These are never removed. */
function wpm_href_is_logout( string $href ): bool {
	return false !== stripos( $href, 'action=logout' );
}

/** True if a menu link points to the Join page. */
function wpm_href_is_join( string $href ): bool {
	return 'join' === wpm_href_last_segment( $href );
}

/** True if a menu link points to a login screen (not a logout link). */
function wpm_href_is_login( string $href ): bool {
	if ( wpm_href_is_logout( $href ) ) {
		return false;
	}
	if ( false !== stripos( $href, 'wp-login.php' ) ) {
		return true;
	}
	return in_array( wpm_href_last_segment( $href ), array( 'login', 'log-in' ), true );
}

/** Classic menus: remove Join and Login for logged-in users. Member Directory is never touched. */
function wpm_filter_classic_menu( $items ) {
	if ( ! is_user_logged_in() || ! is_array( $items ) ) {
		return $items;
	}
	$out = array();
	foreach ( $items as $item ) {
		$href = (string) $item->url;

		if ( wpm_href_is_join( $href ) ) {
			continue;
		}
		if ( wpm_href_is_login( $href ) ) {
			if ( ! wpm_show_logout_in_menu() ) {
				continue;
			}
			$item->url   = wp_logout_url( home_url( '/' ) );
			$item->title = 'Logout';
		}
		$out[] = $item;
	}
	return $out;
}
add_filter( 'wp_nav_menu_objects', 'wpm_filter_classic_menu' );

/**
 * Block themes: remove the list item containing a Join or Login link from navigation
 * and page-list output, for logged-in users only.
 */
function wpm_filter_nav_html( string $html ): string {
	if ( ! class_exists( 'DOMDocument' ) || false === stripos( $html, '<a' ) ) {
		return $html;
	}

	$dom  = new DOMDocument( '1.0', 'UTF-8' );
	$prev = libxml_use_internal_errors( true );
	$dom->loadHTML( '<?xml encoding="UTF-8"?><div id="wpm-nav-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xpath = new DOMXPath( $dom );
	$root  = $xpath->query( "//*[@id='wpm-nav-root']" )->item( 0 );
	if ( ! $root ) {
		return $html;
	}

	$links = iterator_to_array( $root->getElementsByTagName( 'a' ) );
	foreach ( $links as $a ) {
		$href   = (string) $a->getAttribute( 'href' );
		$remove = wpm_href_is_join( $href );

		if ( ! $remove && wpm_href_is_login( $href ) ) {
			if ( wpm_show_logout_in_menu() ) {
				$a->setAttribute( 'href', wp_logout_url( home_url( '/' ) ) );
				$a->nodeValue = 'Logout';
			} else {
				$remove = true;
			}
		}

		if ( $remove ) {
			// Remove the whole list item if there is one, otherwise just the link.
			$target = $a;
			$node   = $a->parentNode;
			while ( $node && $node !== $root ) {
				if ( 'li' === strtolower( $node->nodeName ) ) {
					$target = $node;
					break;
				}
				$node = $node->parentNode;
			}
			if ( $target->parentNode ) {
				$target->parentNode->removeChild( $target );
			}
		}
	}

	$out = '';
	foreach ( $root->childNodes as $child ) {
		$out .= $dom->saveHTML( $child );
	}
	return $out;
}

/** Block themes: filter navigation and page-list output for logged-in users. */
function wpm_filter_block_nav( $content, $block ) {
	if ( ! is_user_logged_in() || ! is_array( $block ) ) {
		return $content;
	}
	$name = $block['blockName'] ?? '';
	if ( ! in_array( $name, array( 'core/navigation', 'core/page-list', 'core/navigation-link' ), true ) ) {
		return $content;
	}
	return wpm_filter_nav_html( (string) $content );
}
add_filter( 'render_block', 'wpm_filter_block_nav', 10, 2 );

/**
 * Fallback for themes whose menus the server-side filters can't reach.
 * For logged-in users only, removes any remaining Join or Login link from header and navigation areas.
 * Logout links and the Member Directory are left alone.
 */
function wpm_hide_join_login_fallback(): void {
	if ( ! is_user_logged_in() ) {
		return;
	}
	?>
	<script>
	(function () {
		var selectors = 'header a[href], nav a[href], .wp-block-navigation a[href], .wp-block-page-list a[href], .menu a[href], .main-navigation a[href]';
		document.querySelectorAll(selectors).forEach(function (a) {
			var href = a.getAttribute('href') || '';
			if (href.indexOf('action=logout') !== -1) { return; }
			var path = '';
			try { path = new URL(href, location.origin).pathname.replace(/\/+$/, ''); } catch (e) { return; }
			var last = (path.split('/').pop() || '').toLowerCase();
			var isJoin = (last === 'join');
			var isLogin = (last === 'login' || last === 'log-in' || last === 'wp-login.php');
			if (!isJoin && !isLogin) { return; }
			var target = a.closest('li') || a;
			if (target.parentNode) { target.parentNode.removeChild(target); }
		});
	})();
	</script>
	<?php
}
add_action( 'wp_footer', 'wpm_hide_join_login_fallback', 99 );

/* =========================================================================
 * 3. REGISTRATION & LOGIN GATEKEEPING
 * ========================================================================= */

/** Handle the public application form. Works for logged-out visitors. */
function wpm_handle_registration(): void {
	if ( is_user_logged_in() ) {
		wpm_back( 'error' );
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wpm_input( '_wpnonce' ) ), 'wpm_register' ) ) {
		wpm_back( 'error' );
	}
	if ( ! wpm_setting( 'registration_open' ) ) {
		wpm_back( 'closed' );
	}

	$name    = sanitize_text_field( wpm_input( 'wpm_name' ) );
	$email   = sanitize_email( wpm_input( 'wpm_email' ) );
	$pass    = wpm_input( 'wpm_password' ); // Not sanitized: sanitizing would alter the password.
	$tier    = sanitize_key( wpm_input( 'wpm_tier' ) );
	$org     = sanitize_text_field( wpm_input( 'wpm_org' ) );
	$country = sanitize_text_field( wpm_input( 'wpm_country' ) );
	$min     = (int) wpm_setting( 'min_password' );

	if ( '' === $name || '' === $email || '' === $pass || '' === $country ) {
		wpm_back( 'missing' );
	}
	if ( ! is_email( $email ) ) {
		wpm_back( 'invalid_email' );
	}
	if ( strlen( $pass ) < $min ) {
		wpm_back( 'weak_password' );
	}
	if ( ! array_key_exists( $tier, wpm_tier_roles() ) ) {
		wpm_back( 'bad_tier' );
	}
	if ( 'corporate' === $tier && '' === $org ) {
		wpm_back( 'missing' );
	}
	if ( email_exists( $email ) || username_exists( $email ) ) {
		wpm_back( 'exists' );
	}

	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => $name,
			'role'         => 'wpm_pending',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		wpm_back( 'error' );
	}

	update_user_meta( $user_id, '_wpm_tier', $tier );
	update_user_meta( $user_id, '_wpm_org', $org );
	update_user_meta( $user_id, '_wpm_country', $country );
	update_user_meta( $user_id, '_wpm_applied', time() );

	wpm_back( 'registered' );
}
add_action( 'admin_post_nopriv_wpm_register', 'wpm_handle_registration' );
add_action( 'admin_post_wpm_register', 'wpm_handle_registration' );

/** Block applicants and inactive members from logging in. */
function wpm_block_inactive_login( $user ) {
	if ( ! $user instanceof WP_User ) {
		return $user;
	}
	$tier = wpm_get_user_tier( $user );
	if ( 'pending' === $tier ) {
		return new WP_Error( 'wpm_pending', esc_html( (string) wpm_setting( 'pending_message' ) ) );
	}
	if ( 'rejected' === $tier ) {
		return new WP_Error( 'wpm_rejected', esc_html__( 'Your membership is not active. Please contact the secretariat.', 'wp-memberships' ) );
	}
	return $user;
}
add_filter( 'authenticate', 'wpm_block_inactive_login', 30, 1 );

/** Log out anyone who is still signed in but has become pending or inactive. */
function wpm_kick_inactive_sessions(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() ) {
		return;
	}
	if ( in_array( wpm_get_user_tier(), array( 'pending', 'rejected' ), true ) ) {
		wp_logout();
	}
}
add_action( 'init', 'wpm_kick_inactive_sessions', 20 );

/* =========================================================================
 * 4. MARKDOWN HANDLING
 * ========================================================================= */

/**
 * Convert a small, safe subset of Markdown to HTML on the server.
 * Used only as a fallback when the browser could not render the Markdown.
 * Output is passed through wp_kses_post() afterwards.
 */
function wpm_markdown_to_html( string $markdown ): string {
	$text = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
	$out  = array();
	$list = null; // 'ul' or 'ol' while inside a list.

	$close_list = static function () use ( &$out, &$list ) {
		if ( $list ) {
			$out[] = '</' . $list . '>';
			$list  = null;
		}
	};

	$inline = static function ( string $s ): string {
		$s = esc_html( $s );
		$s = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $s );
		$s = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s );
		$s = preg_replace( '/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $s );
		$s = preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( $m[2] ) . '">' . $m[1] . '</a>';
			},
			$s
		);
		return $s;
	};

	$paragraph  = array();
	$flush_para = static function () use ( &$out, &$paragraph, $inline ) {
		if ( $paragraph ) {
			$out[]     = '<p>' . $inline( implode( ' ', $paragraph ) ) . '</p>';
			$paragraph = array();
		}
	};

	foreach ( explode( "\n", $text ) as $line ) {
		$trim = trim( $line );

		if ( '' === $trim ) {
			$flush_para();
			$close_list();
			continue;
		}
		if ( preg_match( '/^(#{1,4})\s+(.+)$/', $trim, $m ) ) {
			$flush_para();
			$close_list();
			$level = strlen( $m[1] ) + 1; // Markdown # becomes h2, so the page title stays h1.
			$out[] = '<h' . $level . '>' . $inline( $m[2] ) . '</h' . $level . '>';
			continue;
		}
		if ( preg_match( '/^[-*]\s+(.+)$/', $trim, $m ) ) {
			$flush_para();
			if ( 'ul' !== $list ) {
				$close_list();
				$out[] = '<ul>';
				$list  = 'ul';
			}
			$out[] = '<li>' . $inline( $m[1] ) . '</li>';
			continue;
		}
		if ( preg_match( '/^\d+[.)]\s+(.+)$/', $trim, $m ) ) {
			$flush_para();
			if ( 'ol' !== $list ) {
				$close_list();
				$out[] = '<ol>';
				$list  = 'ol';
			}
			$out[] = '<li>' . $inline( $m[1] ) . '</li>';
			continue;
		}
		$close_list();
		$paragraph[] = $trim;
	}

	$flush_para();
	$close_list();

	return wp_kses_post( implode( "\n", $out ) );
}

/**
 * Markdown editor for member content: toolbar, Write/Preview tabs, live rendering.
 * Renders Markdown in the browser (marked + DOMPurify). The rendered HTML and the raw Markdown
 * are both submitted; the server sanitizes the HTML and keeps the Markdown for editing.
 */
function wpm_markdown_editor_html( string $markdown ): string {
	static $assets_printed = false;
	$out = '';

	if ( ! $assets_printed ) {
		$assets_printed = true;
		$out           .= '<style>
			.wpm .wpm-md{border:1px solid #cbd5e1;border-radius:8px;overflow:hidden;background:#fff}
			.wpm .wpm-md-toolbar{display:flex;flex-wrap:wrap;gap:4px;padding:6px;background:#1e2330;align-items:center}
			.wpm .wpm-md-toolbar button{background:#2a3145;color:#c9d1e0;border:0;border-radius:4px;padding:4px 9px;font:600 13px/1.4 system-ui,sans-serif;cursor:pointer;justify-self:auto}
			.wpm .wpm-md-toolbar button:hover{background:#4f8ef7;color:#fff}
			.wpm .wpm-md-toolbar button.is-on{background:#8e44ad;color:#fff}
			.wpm .wpm-md-spacer{flex:1}
			.wpm .wpm-md-text{display:block;width:100%;min-height:260px;border:0;padding:12px;font:14px/1.6 ui-monospace,Consolas,monospace;resize:vertical;outline:none;color:#0f172a;background:#fff}
			.wpm .wpm-md-preview{padding:12px 16px;min-height:260px;color:#0f172a;background:#fff;line-height:1.7;overflow-wrap:anywhere}
			.wpm .wpm-md-preview img{max-width:100%;height:auto}
			.wpm .wpm-md-preview pre{background:#1e2330;color:#c9d1e0;padding:12px;border-radius:6px;overflow:auto}
			.wpm .wpm-md-preview code{background:#f1f3f8;padding:1px 5px;border-radius:4px}
			.wpm .wpm-md-preview pre code{background:none;padding:0}
			.wpm .wpm-md-preview blockquote{border-left:4px solid #4f8ef7;margin:1em 0;padding:4px 14px;background:#f4f7ff}
			.wpm .wpm-md-preview table{border-collapse:collapse;width:100%;margin:1em 0}
			.wpm .wpm-md-preview th,.wpm .wpm-md-preview td{border:1px solid #d8dde8;padding:6px 10px;text-align:left}
			.wpm .wpm-md-foot{display:flex;justify-content:space-between;gap:8px;padding:6px 10px;font-size:12px;color:#64748b;background:#f8fafc;border-top:1px solid #e2e8f0}
		</style>';
		$out .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/12.0.1/marked.min.js"></script>';
		$out .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.0.8/purify.min.js"></script>';
	}

	ob_start();
	?>
	<div class="wpm-md">
		<div class="wpm-md-toolbar" role="toolbar" aria-label="Formatting">
			<button type="button" data-cmd="bold" title="Bold (Ctrl+B)"><b>B</b></button>
			<button type="button" data-cmd="italic" title="Italic (Ctrl+I)"><i>I</i></button>
			<button type="button" data-cmd="h2" title="Heading">H2</button>
			<button type="button" data-cmd="ul" title="Bulleted list">&bull; List</button>
			<button type="button" data-cmd="ol" title="Numbered list">1. List</button>
			<button type="button" data-cmd="quote" title="Quote">&ldquo; Quote</button>
			<button type="button" data-cmd="code" title="Inline code">&lt;/&gt;</button>
			<button type="button" data-cmd="link" title="Link">Link</button>
			<button type="button" data-cmd="table" title="Table">Table</button>
			<span class="wpm-md-spacer"></span>
			<button type="button" data-view="write" class="is-on">Write</button>
			<button type="button" data-view="preview">Preview</button>
		</div>
		<textarea class="wpm-md-text" name="entry_markdown" rows="14" spellcheck="true" placeholder="Write in Markdown. Use ## for headings, **bold**, *italic*, - for lists, and [text](https://example.com) for links."><?php echo esc_textarea( $markdown ); ?></textarea>
		<div class="wpm-md-preview"></div>
		<input type="hidden" name="entry_html" value="">
		<div class="wpm-md-foot">
			<span class="wpm-md-count">0 words</span>
			<span>Formatted when you submit.</span>
		</div>
	</div>
	<script>
	(function () {
		var root = document.currentScript.previousElementSibling;
		if (!root) { return; }

		var ta        = root.querySelector('.wpm-md-text');
		var pv        = root.querySelector('.wpm-md-preview');
		var htmlField = root.querySelector('input[name="entry_html"]');
		var counter   = root.querySelector('.wpm-md-count');
		var form      = ta.form;

		pv.style.display = 'none';

		function render() {
			var src = ta.value;
			var html = '';
			if (window.marked && window.DOMPurify) {
				html = DOMPurify.sanitize(marked.parse(src, { gfm: true, breaks: true }));
			}
			// Empty means the server converts the Markdown itself.
			htmlField.value = html;
			pv.innerHTML = html || '<p><em>Preview needs the editor libraries to load. Your Markdown will still be saved.</em></p>';

			var words = src.trim() ? src.trim().split(/\s+/).length : 0;
			counter.textContent = words + (words === 1 ? ' word' : ' words');
		}

		function wrap(before, after, placeholder) {
			var s = ta.selectionStart, e = ta.selectionEnd;
			var sel = ta.value.slice(s, e) || placeholder;
			ta.setRangeText(before + sel + after, s, e, 'select');
			ta.selectionStart = s + before.length;
			ta.selectionEnd   = s + before.length + sel.length;
			ta.focus();
			render();
		}

		function lines(fn) {
			var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
			var ls = s > 0 ? v.lastIndexOf('\n', s - 1) + 1 : 0;
			var le = v.indexOf('\n', e);
			if (le === -1) { le = v.length; }
			var out = v.slice(ls, le).split('\n').map(fn).join('\n');
			ta.setRangeText(out, ls, le, 'select');
			ta.focus();
			render();
		}

		var cmds = {
			bold:   function () { wrap('**', '**', 'bold text'); },
			italic: function () { wrap('*', '*', 'italic text'); },
			h2:     function () { lines(function (l) { return '## ' + l.replace(/^#+\s*/, ''); }); },
			ul:     function () { lines(function (l) { return '- ' + l; }); },
			ol:     function () { lines(function (l, i) { return (i + 1) + '. ' + l; }); },
			quote:  function () { lines(function (l) { return '> ' + l; }); },
			code:   function () { wrap('`', '`', 'code'); },
			link:   function () { wrap('[', '](https://)', 'link text'); },
			table:  function () {
				var s = ta.selectionStart;
				ta.setRangeText('\n| Column | Column |\n| --- | --- |\n| Cell | Cell |\n', s, s, 'end');
				ta.focus();
				render();
			}
		};

		root.querySelectorAll('[data-cmd]').forEach(function (b) {
			b.addEventListener('click', function () { cmds[b.dataset.cmd](); });
		});

		root.querySelectorAll('[data-view]').forEach(function (b) {
			b.addEventListener('click', function () {
				var preview = (b.dataset.view === 'preview');
				ta.style.display = preview ? 'none' : '';
				pv.style.display = preview ? 'block' : 'none';
				root.querySelectorAll('[data-view]').forEach(function (x) {
					x.classList.toggle('is-on', x === b);
				});
				if (preview) { render(); }
			});
		});

		ta.addEventListener('keydown', function (e) {
			if (!(e.ctrlKey || e.metaKey)) { return; }
			var k = e.key.toLowerCase();
			if (k === 'b') { e.preventDefault(); cmds.bold(); }
			if (k === 'i') { e.preventDefault(); cmds.italic(); }
		});

		ta.addEventListener('input', render);
		if (form) { form.addEventListener('submit', render); }

		render();
	})();
	</script>
	<?php
	return $out . ob_get_clean();
}

/* =========================================================================
 * 5. APPROVED CONTENT BECOMES REGULAR POSTS
 * ========================================================================= */

/**
 * Convert a member submission into a normal, published WordPress post.
 * A direct database update avoids running the already-filtered content through kses again,
 * which matters when this runs for a visitor with no user session.
 */
function wpm_publish_entry_as_post( int $entry_id ): bool {
	global $wpdb;

	$post = get_post( $entry_id );
	if ( ! $post || 'wpm_entry' !== $post->post_type ) {
		return false;
	}

	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->posts,
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		),
		array( 'ID' => $entry_id )
	);

	update_post_meta( $entry_id, '_wpm_member_post', 1 );
	clean_post_cache( $entry_id );
	return true;
}

/** One-time conversion of items approved under older versions. Runs on the first load after the update. */
function wpm_migrate_entries_to_posts(): void {
	if ( '1' === get_option( 'wpm_posts_migrated' ) ) {
		return;
	}
	$old = get_posts(
		array(
			'post_type'   => 'wpm_entry',
			'post_status' => 'publish',
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);
	foreach ( $old as $id ) {
		wpm_publish_entry_as_post( (int) $id );
	}
	update_option( 'wpm_posts_migrated', '1', false );
}
add_action( 'init', 'wpm_migrate_entries_to_posts', 20 );

/** Approved member posts, optionally filtered by author or excluding an author. */
function wpm_member_posts( array $args = array() ): array {
	$defaults = array(
		'post_type'   => 'post',
		'post_status' => 'publish',
		'meta_key'    => '_wpm_member_post', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'numberposts' => 100,
		'orderby'     => 'date',
		'order'       => 'DESC',
	);
	return get_posts( array_merge( $defaults, $args ) );
}

/* =========================================================================
 * 6. PROFILE PHOTOS
 * ========================================================================= */

/** Folder and web address for profile photos. Created on first use. */
function wpm_photo_dir(): array {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'wpm-avatars';
	$url = trailingslashit( $up['baseurl'] ) . 'wpm-avatars';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
		// Stops directory listings on servers that would otherwise show the folder contents.
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	return array(
		'dir' => $dir,
		'url' => $url,
	);
}

/**
 * Output an <img> for a member's photo.
 * $size is 'thumb' (directory cards) or 'full' (member profile). Returns '' if no photo.
 */
function wpm_photo_html( int $user_id, string $size, string $alt, string $class ): string {
	$url = (string) get_user_meta( $user_id, '_wpm_photo_' . $size, true );
	if ( '' === $url ) {
		return '';
	}
	return '<img class="' . esc_attr( $class ) . '" src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy">';
}

/** Delete a member's photo files and their references. */
function wpm_delete_photo( int $user_id ): void {
	foreach ( array( 'thumb', 'full' ) as $size ) {
		$path = (string) get_user_meta( $user_id, '_wpm_photo_' . $size . '_path', true );
		if ( '' !== $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}
		delete_user_meta( $user_id, '_wpm_photo_' . $size );
		delete_user_meta( $user_id, '_wpm_photo_' . $size . '_path' );
	}
}

/** Write a diagnostic line to the WordPress debug log (only when WP_DEBUG_LOG is on). */
function wpm_photo_log( string $message ): void {
	if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		error_log( 'WP Memberships photo: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

/**
 * Accept an uploaded photo, convert it to a plain JPG, create a thumbnail and a full view,
 * and store both. Returns '' on success, or a specific message code on failure.
 *
 * Sizing rules:
 * - Thumbnail: a square crop, 160 x 160 at most. For small photos, the square is the smaller side.
 * - Full view: at most 640 px on the longest side. Photos already smaller than that are never enlarged.
 */
function wpm_save_photo( int $user_id ): string {
	if ( ! isset( $_FILES['wpm_photo'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['wpm_photo']['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return '';
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$file = $_FILES['wpm_photo']; // phpcs:ignore WordPress.Security.NonceVerification

	if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
		wpm_photo_log( 'PHP upload error code ' . (int) $file['error'] );
		return 'photo_upload';
	}
	if ( (int) $file['size'] > 5 * 1024 * 1024 ) {
		return 'photo_too_big';
	}

	// Step 1: accept only real JPG, PNG, or WebP files, checked by content, not by file name.
	$check = wp_check_filetype_and_ext(
		$file['tmp_name'],
		$file['name'],
		array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
		)
	);
	if ( empty( $check['type'] ) ) {
		wpm_photo_log( 'file type not accepted: ' . $file['name'] );
		return 'photo_type';
	}

	// Step 2: move the upload into a temporary file we control.
	$dir    = wpm_photo_dir();
	$stamp  = $user_id . '-' . time();
	$source = $dir['dir'] . '/tmp-' . $stamp . '-source';
	if ( ! @move_uploaded_file( $file['tmp_name'], $source ) && ! @copy( $file['tmp_name'], $source ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		wpm_photo_log( 'could not move upload to ' . $source );
		return 'photo_save';
	}

	// Step 3: decode with the server's image library and re-encode as a plain JPG.
	$converted = $dir['dir'] . '/tmp-' . $stamp . '-plain.jpg';
	$editor    = wp_get_image_editor( $source );
	if ( is_wp_error( $editor ) ) {
		wpm_photo_log( 'editor open failed: ' . $editor->get_error_message() );
		wp_delete_file( $source );
		return 'photo_editor';
	}
	$editor->set_quality( 90 );
	$plain = $editor->save( $converted, 'image/jpeg' );
	if ( is_wp_error( $plain ) || empty( $plain['path'] ) ) {
		$detail = is_wp_error( $plain ) ? $plain->get_error_message() : 'no path returned';
		wpm_photo_log( 'convert to JPG failed: ' . $detail );
		wp_delete_file( $source );
		return 'photo_editor';
	}
	wp_delete_file( $source );
	$converted = $plain['path'];

	// Step 4: build the two sizes from the plain JPG.
	$made    = array();
	$cleanup = static function () use ( $converted, &$made ) {
		wp_delete_file( $converted );
		foreach ( $made as $item ) {
			wp_delete_file( $item['path'] );
		}
	};

	foreach ( array( 'thumb', 'full' ) as $key ) {
		$editor = wp_get_image_editor( $converted );
		if ( is_wp_error( $editor ) ) {
			wpm_photo_log( $key . ' editor open failed: ' . $editor->get_error_message() );
			$cleanup();
			return 'photo_editor';
		}
		$editor->set_quality( 85 );

		$size = $editor->get_size();
		$w    = (int) $size['width'];
		$h    = (int) $size['height'];

		if ( 'thumb' === $key ) {
			// Square crop. Never larger than 160, and never larger than the photo's smaller side.
			$side = min( 160, $w, $h );
			if ( $w !== $side || $h !== $side ) {
				$resized = $editor->resize( $side, $side, true );
				if ( is_wp_error( $resized ) ) {
					wpm_photo_log( 'thumb resize failed: ' . $resized->get_error_message() );
					$cleanup();
					return 'photo_resize';
				}
			}
		} else {
			// Full view: shrink only if the longest side is over 640. Never enlarge.
			if ( max( $w, $h ) > 640 ) {
				$resized = $editor->resize( 640, 640, false );
				if ( is_wp_error( $resized ) ) {
					wpm_photo_log( 'full resize failed: ' . $resized->get_error_message() );
					$cleanup();
					return 'photo_resize';
				}
			}
		}

		$target = $dir['dir'] . '/wpm-' . $stamp . '-' . $key . '.jpg';
		$saved  = $editor->save( $target, 'image/jpeg' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
			$detail = is_wp_error( $saved ) ? $saved->get_error_message() : 'no path returned';
			wpm_photo_log( $key . ' save failed: ' . $detail );
			$cleanup();
			return 'photo_save';
		}

		$made[ $key ] = array(
			'path' => $saved['path'],
			'url'  => $dir['url'] . '/' . basename( $saved['path'] ),
		);
	}

	// Step 5: the temporary plain JPG is no longer needed.
	wp_delete_file( $converted );

	// Step 6: replace any older photo and store the new one.
	wpm_delete_photo( $user_id );
	foreach ( $made as $key => $item ) {
		update_user_meta( $user_id, '_wpm_photo_' . $key, $item['url'] );
		update_user_meta( $user_id, '_wpm_photo_' . $key . '_path', $item['path'] );
	}

	return '';
}

/* =========================================================================
 * 7. FRONT-END SHORTCODES
 * ========================================================================= */

/** [wpm_register]: the application form. */
function wpm_shortcode_register(): string {
	if ( is_user_logged_in() ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info">You are already logged in.</div></div>';
	}
	if ( ! wpm_setting( 'registration_open' ) ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info">New applications are currently closed.</div></div>';
	}

	$min = (int) wpm_setting( 'min_password' );

	ob_start();
	?>
	<div class="wpm">
		<?php echo wpm_notice_html( 'front' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpm-form">
			<input type="hidden" name="action" value="wpm_register">
			<?php wp_nonce_field( 'wpm_register' ); ?>

			<label>Full Name *
				<input type="text" name="wpm_name" required>
			</label>

			<label>Email *
				<input type="email" name="wpm_email" required>
			</label>

			<label>Password * (min. <?php echo esc_html( (string) $min ); ?> characters)
				<input type="password" name="wpm_password" minlength="<?php echo esc_attr( (string) $min ); ?>" required>
			</label>

			<label>Requested Membership Level *
				<select name="wpm_tier" required>
					<option value="individual">Individual</option>
					<option value="corporate">Corporate</option>
				</select>
			</label>

			<label>Organization Name (required for Corporate)
				<input type="text" name="wpm_org">
			</label>

			<label>Country *
				<input type="text" name="wpm_country" required>
			</label>

			<button type="submit">Submit Application</button>
		</form>
		<p class="wpm-meta">Already a member? <a href="<?php echo esc_url( wp_login_url() ); ?>">Log in</a></p>
	</div>
	<?php
	return wpm_styles() . ob_get_clean();
}
add_shortcode( 'wpm_register', 'wpm_shortcode_register' );

/** [wpm_restrict tier="corporate"]...[/wpm_restrict] */
function wpm_shortcode_restrict( $atts, $content = '' ): string {
	$atts     = shortcode_atts( array( 'tier' => 'individual' ), $atts, 'wpm_restrict' );
	$required = sanitize_key( $atts['tier'] );
	if ( ! in_array( $required, array( 'individual', 'corporate' ), true ) ) {
		$required = 'individual';
	}

	if ( wpm_user_can_access( $required ) ) {
		return wpm_styles() . '<div class="wpm">' . do_shortcode( (string) $content ) . '</div>';
	}

	$label = ( 'corporate' === $required ) ? 'Corporate' : 'Individual or Corporate';
	$tier  = wpm_get_user_tier();

	if ( ! is_user_logged_in() ) {
		$msg = sprintf(
			'This content is for %s members. <a href="%s">Log in</a> to view it, or apply for membership.',
			esc_html( $label ),
			esc_url( wp_login_url() )
		);
	} elseif ( 'pending' === $tier ) {
		$msg = esc_html( (string) wpm_setting( 'pending_message' ) );
	} else {
		$msg = sprintf( 'This content is for %s members. Contact the secretariat to upgrade your membership.', esc_html( $label ) );
	}

	return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info">' . $msg . '</div></div>';
}
add_shortcode( 'wpm_restrict', 'wpm_shortcode_restrict' );

/** [wpm_member_nav]: the menu bar members see at the top of the portal. */
function wpm_shortcode_member_nav(): string {
	if ( ! is_user_logged_in() || ! wpm_is_active_member() ) {
		return '';
	}
	$portal = wpm_portal_url();
	$links  = array(
		array( $portal, 'Member Portal' ),
		array( $portal . '#wpm-my-content', 'Add Your Content' ),
		array( $portal . '#wpm-profile', 'My Details' ),
		array( wpm_directory_url(), 'Member Directory' ),
		array( admin_url( 'admin.php?page=wpm-my-membership' ), 'My Membership' ),
		array( wp_logout_url( home_url( '/' ) ), 'Log out' ),
	);

	$html = wpm_styles() . '<nav class="wpm wpm-nav" aria-label="Member menu">';
	foreach ( $links as $link ) {
		$html .= '<a href="' . esc_url( $link[0] ) . '">' . esc_html( $link[1] ) . '</a>';
	}
	return $html . '</nav>';
}
add_shortcode( 'wpm_member_nav', 'wpm_shortcode_member_nav' );

/** Render one content item in a list. Titles link to the item's own page. */
function wpm_entry_card( WP_Post $post, bool $owner_view ): string {
	$is_post    = ( 'post' === $post->post_type );
	$author     = get_userdata( (int) $post->post_author );
	$waiting    = ( 'pending' === $post->post_status );
	$visibility = (string) get_post_meta( $post->ID, '_wpm_visibility', true );

	if ( $is_post ) {
		$badge = 'Published';
	} else {
		$badge = ( 'members' === $visibility ) ? 'Members only' : 'Private';
	}

	$html  = '<article class="wpm-entry" id="wpm-entry-' . esc_attr( (string) $post->ID ) . '">';
	$html .= '<h4>' . ( $waiting ? esc_html( $post->post_title ) : '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $post->post_title ) . '</a>' ) . '</h4>';
	$html .= '<p class="wpm-meta">';
	if ( ! $owner_view && $author ) {
		$html .= '<a href="' . esc_url( add_query_arg( 'wpm_member', (int) $author->ID, wpm_directory_url() ) ) . '">'
			. esc_html( $author->display_name ) . '</a> · ';
	}
	$html .= esc_html( get_the_date( '', $post ) )
		. ' · <span class="wpm-badge">' . esc_html( $badge ) . '</span>';
	if ( $owner_view && $waiting ) {
		$html .= ' <span class="wpm-badge wpm-waiting">Awaiting approval</span>';
	}
	$html .= '</p>';
	$html .= '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ) ) . '</p>';

	if ( $owner_view ) {
		$html .= '<p class="wpm-entry-actions">';
		if ( ! $waiting ) {
			$html .= '<a href="' . esc_url( get_permalink( $post ) ) . '">View</a>';
		}
		if ( ! $is_post ) {
			$edit_url = add_query_arg( 'wpm_edit', $post->ID, wpm_portal_url() ) . '#wpm-my-content';
			$html    .= '<a href="' . esc_url( $edit_url ) . '">Edit</a>';
		}
		$delete_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wpm_delete_entry&entry_id=' . $post->ID ),
			'wpm_delete_entry_' . $post->ID
		);
		$html .= '<a href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'Delete this content?\');">Delete</a></p>';
	}

	return $html . '</article>';
}

/** [wpm_my_content]: the Markdown editor, plus the member's own items and items published by others. */
function wpm_shortcode_my_content(): string {
	if ( ! is_user_logged_in() ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info"><a href="'
			. esc_url( wp_login_url( wpm_portal_url() ) ) . '">Log in</a> to add your content.</div></div>';
	}
	if ( ! wpm_is_active_member() ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info">' . esc_html( (string) wpm_setting( 'pending_message' ) ) . '</div></div>';
	}

	$uid      = get_current_user_id();
	$can_post = wpm_user_can_post();
	$edit_id  = absint( wpm_input( 'wpm_edit', 'get' ) );
	$editing  = null;
	if ( $edit_id ) {
		// Only items still awaiting approval can be edited. Approved items are regular posts.
		$candidate = get_post( $edit_id );
		if ( $candidate && 'wpm_entry' === $candidate->post_type && (int) $candidate->post_author === $uid ) {
			$editing = $candidate;
		}
	}

	// Text shown in the editor: the Markdown source if we have it, otherwise the saved content.
	$editor_text = '';
	if ( $editing ) {
		$editor_text = (string) get_post_meta( $editing->ID, '_wpm_markdown_source', true );
		if ( '' === $editor_text ) {
			$editor_text = (string) $editing->post_content;
		}
	}

	$waiting = get_posts(
		array(
			'post_type'   => 'wpm_entry',
			'post_status' => array( 'pending', 'draft' ),
			'author'      => $uid,
			'numberposts' => 50,
		)
	);
	$approved = wpm_member_posts( array( 'author' => $uid, 'numberposts' => 50 ) );
	$mine     = wpm_sort_newest( array_merge( $waiting, $approved ) );

	$shared = wpm_member_posts(
		array(
			'author__not_in' => array( $uid ),
			'numberposts'    => 50,
		)
	);

	ob_start();
	?>
	<div class="wpm" id="wpm-my-content">
		<?php echo wpm_notice_html( 'front' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<h3 class="wpm-section-title"><?php echo $editing ? 'Edit your content' : 'Add your content'; ?></h3>

		<?php if ( ! $can_post ) : ?>
			<div class="wpm-notice wpm-info">
				Posting is turned off for your account. The administrator can enable it for you.
			</div>
		<?php else : ?>
			<p class="wpm-meta">Write in Markdown. New and edited content is checked by the administrator, and once approved it is published as a regular post on the site.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpm-form">
				<input type="hidden" name="action" value="wpm_save_entry">
				<input type="hidden" name="entry_id" value="<?php echo esc_attr( (string) ( $editing ? $editing->ID : 0 ) ); ?>">
				<?php wp_nonce_field( 'wpm_save_entry' ); ?>

				<label>Title *
					<input type="text" name="entry_title" required value="<?php echo esc_attr( $editing ? $editing->post_title : '' ); ?>">
				</label>

				<div>
					<span class="wpm-meta" style="display:block;font-weight:600;margin-bottom:.3rem">Content</span>
					<?php echo wpm_markdown_editor_html( $editor_text ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>

				<label>Who can see this while it is waiting?
					<select name="entry_visibility">
						<?php $current_vis = $editing ? (string) get_post_meta( $editing->ID, '_wpm_visibility', true ) : 'members'; ?>
						<option value="members" <?php selected( $current_vis, 'members' ); ?>>Members only</option>
						<option value="private" <?php selected( $current_vis, 'private' ); ?>>Private (only me)</option>
					</select>
				</label>

				<button type="submit"><?php echo $editing ? 'Submit Changes' : 'Submit for Approval'; ?></button>
				<?php if ( $editing ) : ?>
					<p class="wpm-meta"><a href="<?php echo esc_url( wpm_portal_url() . '#wpm-my-content' ); ?>">Cancel editing</a></p>
				<?php endif; ?>
			</form>
		<?php endif; ?>

		<h3 class="wpm-section-title">Your content</h3>
		<?php if ( empty( $mine ) ) : ?>
			<p class="wpm-notice wpm-info">You haven't added any content yet.</p>
		<?php else : ?>
			<?php foreach ( $mine as $post ) {
				echo wpm_entry_card( $post, true ); // phpcs:ignore WordPress.Security.EscapeOutput
			} ?>
		<?php endif; ?>

		<h3 class="wpm-section-title">Published by other members</h3>
		<?php if ( empty( $shared ) ) : ?>
			<p class="wpm-notice wpm-info">No member posts have been published yet.</p>
		<?php else : ?>
			<?php foreach ( $shared as $post ) {
				echo wpm_entry_card( $post, false ); // phpcs:ignore WordPress.Security.EscapeOutput
			} ?>
		<?php endif; ?>
	</div>
	<?php
	return wpm_styles() . ob_get_clean();
}
add_shortcode( 'wpm_my_content', 'wpm_shortcode_my_content' );

/** Photo fields shared by the portal form. */
function wpm_photo_fields_html( int $user_id ): string {
	$thumb = wpm_photo_html( $user_id, 'thumb', 'Your current profile photo', 'wpm-photo-preview' );
	$html  = '<div><span class="wpm-meta" style="display:block;font-weight:600;margin-bottom:.3rem">Profile photo</span>';
	if ( '' !== $thumb ) {
		$html .= $thumb;
		$html .= '<label class="wpm-check"><input type="checkbox" name="wpm_photo_remove" value="1"> Remove current photo</label>';
	}
	$html .= '<label>Upload a new photo<input type="file" name="wpm_photo" accept="image/jpeg,image/png,image/webp"></label>';
	$html .= '<p class="wpm-meta">JPG, PNG, or WebP, up to 5 MB. It is resized automatically for the directory and your profile.</p></div>';
	return $html;
}

/** [wpm_profile]: the member's details, photo, and password form. */
function wpm_shortcode_profile(): string {
	if ( ! is_user_logged_in() ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info"><a href="'
			. esc_url( wp_login_url( wpm_portal_url() ) )
			. '">Log in</a> to manage your membership details.</div></div>';
	}

	$user = wp_get_current_user();
	$tier = wpm_get_user_tier( $user );

	if ( in_array( $tier, array( 'pending', 'rejected' ), true ) ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info">'
			. esc_html( (string) wpm_setting( 'pending_message' ) ) . '</div></div>';
	}

	$org     = (string) get_user_meta( $user->ID, '_wpm_org', true );
	$country = (string) get_user_meta( $user->ID, '_wpm_country', true );
	$bio     = (string) get_user_meta( $user->ID, '_wpm_bio', true );
	$min     = (int) wpm_setting( 'min_password' );

	ob_start();
	?>
	<div class="wpm" id="wpm-profile">
		<h3 class="wpm-section-title">Your details</h3>
		<?php echo wpm_notice_html( 'front' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p class="wpm-meta">Your membership level: <strong><?php echo esc_html( wpm_tier_label( $tier ) ); ?></strong></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpm-form" enctype="multipart/form-data">
			<input type="hidden" name="action" value="wpm_profile">
			<?php wp_nonce_field( 'wpm_profile' ); ?>

			<?php echo wpm_photo_fields_html( $user->ID ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

			<label>Full Name
				<input type="text" name="wpm_name" value="<?php echo esc_attr( $user->display_name ); ?>">
			</label>

			<label>Organization Name
				<input type="text" name="wpm_org" value="<?php echo esc_attr( $org ); ?>">
			</label>

			<label>Country
				<input type="text" name="wpm_country" value="<?php echo esc_attr( $country ); ?>">
			</label>

			<label>Bio
				<textarea name="wpm_bio"><?php echo esc_textarea( $bio ); ?></textarea>
			</label>

			<h3 style="margin:.5rem 0 0">Change password</h3>
			<p class="wpm-meta" style="margin:0">Leave blank to keep your current password.</p>

			<label>Current Password
				<input type="password" name="wpm_current_password" autocomplete="current-password">
			</label>

			<label>New Password (min. <?php echo esc_html( (string) $min ); ?> characters)
				<input type="password" name="wpm_new_password" minlength="<?php echo esc_attr( (string) $min ); ?>" autocomplete="new-password">
			</label>

			<button type="submit">Save My Details</button>
		</form>
	</div>
	<?php
	return wpm_styles() . ob_get_clean();
}
add_shortcode( 'wpm_profile', 'wpm_shortcode_profile' );

/** Build the full Member Portal page: menu, content form and lists, access-gated notes, and details form. */
function wpm_portal_content(): string {
	if ( ! is_user_logged_in() ) {
		return wpm_styles() . '<div class="wpm"><div class="wpm-notice wpm-info"><a href="'
			. esc_url( wp_login_url( wpm_portal_url() ) ) . '">Log in</a> to access the Member Portal.</div></div>';
	}

	$html  = do_shortcode( '[wpm_member_nav]' );
	$html .= do_shortcode( '[wpm_my_content]' );
	$html .= do_shortcode( '[wpm_restrict tier="individual"]<div class="wpm-notice wpm-info">Members-only resources. Your administrator can add shared documents here.</div>[/wpm_restrict]' );
	$html .= do_shortcode( '[wpm_restrict tier="corporate"]<div class="wpm-notice wpm-info">Corporate members only: additional resources for corporate members.</div>[/wpm_restrict]' );
	$html .= do_shortcode( '[wpm_profile]' );

	return $html;
}

/** Swap the Member Portal page body for the full portal, so members always see the tools. */
function wpm_portal_filter( string $content ): string {
	if ( ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( ! is_page( wpm_protected_slugs() ) ) {
		return $content;
	}
	return wpm_portal_content();
}
add_filter( 'the_content', 'wpm_portal_filter' );

/** Send non-members away from protected pages to the login screen. */
function wpm_page_lockdown(): void {
	if ( ! is_page( wpm_protected_slugs() ) ) {
		return;
	}
	if ( wpm_user_can_access( 'individual' ) ) {
		return;
	}
	wp_safe_redirect( wp_login_url( get_permalink( get_queried_object_id() ) ) );
	exit;
}
add_action( 'template_redirect', 'wpm_page_lockdown' );

/* =========================================================================
 * 8. WAITING SUBMISSIONS: ACCESS RULES
 * ========================================================================= */

/**
 * Submissions still awaiting approval are hidden from everyone except the author and administrators.
 * Approved items are regular posts and are not affected by this rule.
 */
function wpm_entry_gate(): void {
	if ( ! is_singular( 'wpm_entry' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$uid = get_current_user_id();
	if ( current_user_can( 'manage_options' ) || ( $uid && $uid === (int) $post->post_author ) ) {
		return;
	}

	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
	$template = get_404_template();
	if ( $template ) {
		include $template;
	}
	exit;
}
add_action( 'template_redirect', 'wpm_entry_gate', 1 );

/** Add a byline and a back link to a waiting submission's page (visible to its author and admins only). */
function wpm_entry_single_content( $content ) {
	if ( ! is_singular( 'wpm_entry' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$post   = get_post();
	$author = get_userdata( (int) $post->post_author );

	$meta = '<p class="wpm-meta">By ' . esc_html( $author ? $author->display_name : 'Member' ) . ' · '
		. esc_html( get_the_date( '', $post ) ) . ' · <span class="wpm-badge wpm-waiting">Awaiting approval</span></p>';

	return wpm_styles() . '<div class="wpm">' . $meta
		. '<div class="wpm-entry-body">' . $content . '</div>'
		. '<p class="wpm-meta"><a href="' . esc_url( wpm_portal_url() ) . '">&larr; Back to Member Portal</a></p></div>';
}
add_filter( 'the_content', 'wpm_entry_single_content', 20 );

/* =========================================================================
 * 9. MEMBER DIRECTORY & MEMBER PROFILES
 * ========================================================================= */

/** Query members by role, with optional keyword (name or organization) and country. */
function wpm_query_members( array $roles, string $kw = '', string $country = '' ): array {
	if ( empty( $roles ) ) {
		return array(); // Guard: an empty role__in would return every user.
	}

	$args = array(
		'role__in' => $roles,
		'orderby'  => 'display_name',
		'order'    => 'ASC',
		'number'   => 200,
	);

	if ( '' !== $country ) {
		$args['meta_query'] = array(
			array(
				'key'   => '_wpm_country',
				'value' => $country,
			),
		);
	}

	if ( '' !== $kw ) {
		$by_name = ( new WP_User_Query(
			array(
				'role__in'       => $roles,
				'search'         => '*' . $kw . '*',
				'search_columns' => array( 'display_name' ),
				'fields'         => 'ID',
				'number'         => -1,
			)
		) )->get_results();

		$by_org = ( new WP_User_Query(
			array(
				'role__in'   => $roles,
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => array(
					array(
						'key'     => '_wpm_org',
						'value'   => $kw,
						'compare' => 'LIKE',
					),
				),
			)
		) )->get_results();

		$ids = array_values( array_unique( array_map( 'intval', array_merge( $by_name, $by_org ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$args['include'] = $ids;
	}

	return ( new WP_User_Query( $args ) )->get_results();
}

/**
 * A single member's profile. Photo and bio show to approved members, administrators, and the member.
 * Published posts by the member are listed for everyone.
 */
function wpm_member_profile_view( int $member_id ): string {
	$back   = '<p class="wpm-meta"><a href="' . esc_url( wpm_directory_url() ) . '">&larr; Back to Member Directory</a></p>';
	$member = get_userdata( $member_id );
	$tier   = $member ? wpm_get_user_tier( $member ) : '';

	if ( ! $member || ! in_array( $tier, array( 'individual', 'corporate' ), true ) ) {
		return wpm_styles() . '<div class="wpm">' . $back
			. '<div class="wpm-notice wpm-info">This member profile is not available.</div></div>';
	}

	$viewer_id = get_current_user_id();
	$is_admin  = current_user_can( 'manage_options' );
	$is_self   = ( $viewer_id && $viewer_id === $member->ID );
	$can_see   = ( wpm_is_active_member() || $is_admin || $is_self );

	$org     = (string) get_user_meta( $member->ID, '_wpm_org', true );
	$country = (string) get_user_meta( $member->ID, '_wpm_country', true );
	$bio     = (string) get_user_meta( $member->ID, '_wpm_bio', true );

	$html  = wpm_styles() . '<div class="wpm">' . $back;
	$html .= '<h2 style="margin:.25rem 0">' . esc_html( $member->display_name ) . '</h2>';
	$html .= '<p class="wpm-meta">' . esc_html( wpm_tier_label( $tier ) )
		. ( '' !== $org ? ' · ' . esc_html( $org ) : '' )
		. ( '' !== $country ? ' · ' . esc_html( $country ) : '' ) . '</p>';

	if ( $can_see ) {
		$html .= wpm_photo_html( $member->ID, 'full', $member->display_name, 'wpm-photo-full' );
		if ( '' !== $bio ) {
			$html .= '<p>' . nl2br( esc_html( $bio ) ) . '</p>';
		}
	} else {
		$html .= '<div class="wpm-notice wpm-info"><a href="' . esc_url( wp_login_url( wpm_directory_url() ) )
			. '">Log in</a> as an approved member to see this member\'s photo and bio.</div>';
	}

	$items = wpm_member_posts( array( 'author' => $member->ID ) );

	$html .= '<h3 class="wpm-section-title">Posts</h3>';
	if ( empty( $items ) ) {
		$html .= '<p class="wpm-notice wpm-info">No published posts yet.</p>';
	}
	foreach ( $items as $post ) {
		$html .= '<article class="wpm-entry"><h4><a href="' . esc_url( get_permalink( $post ) ) . '">'
			. esc_html( $post->post_title ) . '</a></h4>'
			. '<p class="wpm-meta">' . esc_html( get_the_date( '', $post ) ) . '</p>'
			. '<p>' . esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ) ) . '</p></article>';
	}

	return $html . '</div>';
}

/** [wpm_directory]: the searchable member list, or a single member's profile when one is selected. */
function wpm_shortcode_directory(): string {
	$view_id = absint( wpm_input( 'wpm_member', 'get' ) );
	if ( $view_id ) {
		return wpm_member_profile_view( $view_id );
	}

	$full_access = wpm_user_can_access( 'individual' );
	$tier_roles  = wpm_tier_roles();
	$dir_url     = wpm_directory_url();

	$visible_tiers = $full_access
		? array_keys( $tier_roles )
		: array_values( array_intersect( wpm_public_tiers(), array_keys( $tier_roles ) ) );
	$visible_roles = array_map( fn( $t ) => $tier_roles[ $t ], $visible_tiers );

	$kw      = sanitize_text_field( wpm_input( 'wpm_q', 'get' ) );
	$country = sanitize_text_field( wpm_input( 'wpm_country', 'get' ) );
	$tier    = sanitize_key( wpm_input( 'wpm_tier', 'get' ) );
	if ( ! in_array( $tier, $visible_tiers, true ) ) {
		$tier = '';
	}

	$filter_roles = $tier ? array( $tier_roles[ $tier ] ) : $visible_roles;

	$countries = array();
	foreach ( wpm_query_members( $visible_roles ) as $m ) {
		$c = (string) get_user_meta( $m->ID, '_wpm_country', true );
		if ( '' !== $c ) {
			$countries[ $c ] = $c;
		}
	}
	ksort( $countries );

	$members = wpm_query_members( $filter_roles, $kw, $country );

	ob_start();
	?>
	<div class="wpm">
		<?php if ( ! $full_access ) : ?>
			<div class="wpm-notice wpm-info">
				<?php if ( is_user_logged_in() ) : ?>
					Your membership is not yet active, so you're seeing public listings only.
				<?php else : ?>
					<a href="<?php echo esc_url( wp_login_url( $dir_url ) ); ?>">Log in</a>
					as an approved member to see the full directory.
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<form method="get" action="" class="wpm-filters">
			<label>Name or Keyword
				<input type="text" name="wpm_q" value="<?php echo esc_attr( $kw ); ?>">
			</label>

			<label>Country
				<select name="wpm_country">
					<option value="">All countries</option>
					<?php foreach ( $countries as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $country, $c ); ?>>
							<?php echo esc_html( $c ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label>Membership Level
				<select name="wpm_tier">
					<option value="">All levels</option>
					<?php foreach ( $visible_tiers as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $tier, $t ); ?>>
							<?php echo esc_html( wpm_tier_label( $t ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<button type="submit">Search</button>
		</form>

		<?php if ( empty( $members ) ) : ?>
			<p class="wpm-notice wpm-info">No members match your search.</p>
		<?php else : ?>
			<div class="wpm-grid">
				<?php foreach ( $members as $m ) :
					$m_tier = wpm_get_user_tier( $m );
					$org    = (string) get_user_meta( $m->ID, '_wpm_org', true );
					$loc    = (string) get_user_meta( $m->ID, '_wpm_country', true );
					$bio    = (string) get_user_meta( $m->ID, '_wpm_bio', true );
					$thumb  = wpm_photo_html( $m->ID, 'thumb', $m->display_name, 'wpm-photo-thumb' );
					?>
					<article class="wpm-card">
						<?php if ( '' !== $thumb ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'wpm_member', $m->ID, $dir_url ) ); ?>"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
						<?php endif; ?>
						<h3><a href="<?php echo esc_url( add_query_arg( 'wpm_member', $m->ID, $dir_url ) ); ?>"><?php echo esc_html( $m->display_name ); ?></a></h3>
						<?php if ( '' !== $org ) : ?>
							<p class="wpm-meta"><?php echo esc_html( $org ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $loc ) : ?>
							<p class="wpm-meta"><?php echo esc_html( $loc ); ?></p>
						<?php endif; ?>
						<span class="wpm-badge <?php echo ( 'corporate' === $m_tier ) ? 'wpm-corp' : ''; ?>">
							<?php echo esc_html( wpm_tier_label( $m_tier ) ); ?>
						</span>
						<?php if ( $full_access && '' !== $bio ) : ?>
							<p><?php echo nl2br( esc_html( $bio ) ); ?></p>
						<?php endif; ?>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
	return wpm_styles() . ob_get_clean();
}
add_shortcode( 'wpm_directory', 'wpm_shortcode_directory' );

/* =========================================================================
 * 10. DASHBOARD: MENUS & ACCESS CONTROL
 * ========================================================================= */

/** Build the dashboard menus. Admins get the full panel; every member gets My Membership. */
function wpm_admin_menus(): void {
	if ( current_user_can( 'manage_options' ) ) {
		add_menu_page( 'WP Memberships', 'Memberships', 'manage_options', 'wpm-memberships', 'wpm_render_admin_page', 'dashicons-groups', 30 );
	}
	add_menu_page( 'My Membership', 'My Membership', 'read', 'wpm-my-membership', 'wpm_render_my_membership', 'dashicons-id', 31 );
}
add_action( 'admin_menu', 'wpm_admin_menus' );

/** Non-admins see only My Membership in the sidebar. */
function wpm_trim_menus_for_members(): void {
	if ( current_user_can( 'manage_options' ) ) {
		return;
	}
	foreach ( array( 'index.php', 'edit.php', 'upload.php', 'edit.php?post_type=page', 'edit-comments.php', 'themes.php', 'plugins.php', 'users.php', 'tools.php', 'options-general.php', 'profile.php' ) as $menu ) {
		remove_menu_page( $menu );
	}
}
add_action( 'admin_menu', 'wpm_trim_menus_for_members', 999 );

/** Members may only open My Membership (plus the form handlers) in the dashboard. */
function wpm_restrict_dashboard_for_members(): void {
	if ( wp_doing_ajax() || wp_doing_cron() || ! is_user_logged_in() || current_user_can( 'manage_options' ) ) {
		return;
	}
	global $pagenow;
	if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
		return;
	}
	if ( 'admin.php' === $pagenow && isset( $_GET['page'] ) && 'wpm-my-membership' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=wpm-my-membership' ) );
	exit;
}
add_action( 'admin_init', 'wpm_restrict_dashboard_for_members', 1 );

/** Hide the top admin bar for everyone except administrators. */
function wpm_admin_bar_for_members( $show ) {
	return current_user_can( 'manage_options' ) ? $show : false;
}
add_filter( 'show_admin_bar', 'wpm_admin_bar_for_members' );

/** Dashboard styling for the status badges and photo previews. */
function wpm_admin_styles(): void {
	if ( ! isset( $_GET['page'] ) || 0 !== strpos( sanitize_key( wp_unslash( $_GET['page'] ) ), 'wpm-' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	echo '<style>.wpm-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;background:#e0e7ff;color:#3730a3}.wpm-status.wpm-status-pending{background:#fef9c3;color:#854d0e}.wpm-status.wpm-status-inactive{background:#fee2e2;color:#991b1b}.wpm-status.wpm-status-corporate{background:#fef3c7;color:#92400e}.wpm-status.wpm-status-off{background:#f1f5f9;color:#475569}.wpm-status.wpm-status-live{background:#dcfce7;color:#166534}.wpm-count{display:inline-block;margin-left:4px;padding:0 7px;border-radius:999px;background:#d63638;color:#fff;font-size:11px;line-height:18px;vertical-align:middle}.wpm-photo-preview{width:120px;height:120px;border-radius:50%;object-fit:cover;display:block;margin-bottom:8px}</style>';
}
add_action( 'admin_head', 'wpm_admin_styles' );

/* =========================================================================
 * 11. DASHBOARD PAGE: MEMBERSHIPS (ADMINS)
 * ========================================================================= */

/** Number of content items waiting for approval. */
function wpm_pending_entry_count(): int {
	$query = new WP_Query(
		array(
			'post_type'              => 'wpm_entry',
			'post_status'            => 'pending',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);
	return (int) $query->found_posts;
}

/** Render the single-window Memberships panel with its tabs. */
function wpm_render_admin_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-memberships' ), 403 );
	}

	$pending_count = wpm_pending_entry_count();
	$tabs          = array(
		'applications' => 'Applications',
		'members'      => 'Members',
		'add'          => 'Add Member',
		'content'      => 'Member Content' . ( $pending_count ? ' <span class="wpm-count">' . $pending_count . '</span>' : '' ),
		'settings'     => 'Settings',
	);
	$tab = sanitize_key( wpm_input( 'tab', 'get' ) );
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'applications';
	}

	echo '<div class="wrap"><h1>WP Memberships</h1>';
	echo wpm_notice_html( 'admin' ); // phpcs:ignore WordPress.Security.EscapeOutput

	echo '<nav class="nav-tab-wrapper">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="nav-tab %s">%s</a>',
			esc_url( admin_url( 'admin.php?page=wpm-memberships&tab=' . $slug ) ),
			$tab === $slug ? 'nav-tab-active' : '',
			$label // Labels are fixed strings defined above, with an escaped count.
		);
	}
	echo '</nav><br>';

	if ( 'applications' === $tab ) {
		wpm_tab_applications();
	} elseif ( 'members' === $tab ) {
		wpm_tab_members();
	} elseif ( 'add' === $tab ) {
		wpm_tab_add_member();
	} elseif ( 'content' === $tab ) {
		wpm_tab_content();
	} else {
		wpm_tab_settings();
	}

	echo '</div>';
}

/** Tab: applications waiting for review. */
function wpm_tab_applications(): void {
	$users = ( new WP_User_Query(
		array(
			'role'    => 'wpm_pending',
			'orderby' => 'registered',
			'order'   => 'DESC',
			'number'  => 200,
		)
	) )->get_results();

	echo '<h2>Applications waiting for review</h2>';
	if ( empty( $users ) ) {
		echo '<p>No applications are waiting for review.</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>
		<th>Applicant</th><th>Requested level</th><th>Organization</th><th>Country</th><th>Applied</th><th>Decision</th>
		</tr></thead><tbody>';

	foreach ( $users as $u ) {
		$id      = (int) $u->ID;
		$applied = (int) get_user_meta( $id, '_wpm_applied', true );
		$approve = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_member_action&do=approve&user_id=' . $id ), 'wpm_member_' . $id );
		$reject  = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_member_action&do=reject&user_id=' . $id ), 'wpm_member_' . $id );

		printf(
			'<tr><td><strong>%s</strong><br><span class="description">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>
				<a class="button button-primary" href="%s">Approve</a>
				<a class="button" href="%s" onclick="return confirm(\'Reject this application?\');">Reject</a>
			</td></tr>',
			esc_html( $u->display_name ),
			esc_html( $u->user_email ),
			esc_html( wpm_tier_label( (string) get_user_meta( $id, '_wpm_tier', true ) ) ),
			esc_html( (string) get_user_meta( $id, '_wpm_org', true ) ?: '—' ),
			esc_html( (string) get_user_meta( $id, '_wpm_country', true ) ?: '—' ),
			esc_html( $applied ? wp_date( get_option( 'date_format' ), $applied ) : '—' ),
			esc_url( $approve ),
			esc_url( $reject )
		);
	}
	echo '</tbody></table>';
}

/** Tab: add a member manually from the dashboard. No emails are sent. */
function wpm_tab_add_member(): void {
	$min = (int) wpm_setting( 'min_password' );
	?>
	<h2>Add a member</h2>
	<p class="description">Create an account yourself. Choose <strong>Active</strong> to let them log in straight away, or <strong>Waiting for approval</strong> to place them in the Applications list first. No email is sent, so share the login details with them yourself.</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wpm_create_member">
		<?php wp_nonce_field( 'wpm_create_member' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpm_new_name">Full name *</label></th>
				<td><input type="text" id="wpm_new_name" name="wpm_name" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_email">Email (used as login) *</label></th>
				<td><input type="email" id="wpm_new_email" name="wpm_email" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_password">Password *</label></th>
				<td><input type="text" id="wpm_new_password" name="wpm_password" minlength="<?php echo esc_attr( (string) $min ); ?>" class="regular-text" required>
					<p class="description">At least <?php echo esc_html( (string) $min ); ?> characters. It is shown here so you can pass it on.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_tier">Membership level *</label></th>
				<td>
					<select id="wpm_new_tier" name="wpm_tier">
						<option value="individual">Individual</option>
						<option value="corporate">Corporate</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_org">Organization</label></th>
				<td><input type="text" id="wpm_new_org" name="wpm_org" class="regular-text">
					<p class="description">Required for Corporate members.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_country">Country *</label></th>
				<td><input type="text" id="wpm_new_country" name="wpm_country" class="regular-text" required></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_new_status">Starting status *</label></th>
				<td>
					<select id="wpm_new_status" name="wpm_status">
						<option value="active">Active (can log in now)</option>
						<option value="waiting">Waiting for approval</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">Can post content</th>
				<td><label><input type="checkbox" name="wpm_can_post" value="1"> Allow this member to create content</label></td>
			</tr>
		</table>

		<?php submit_button( 'Create Member' ); ?>
	</form>
	<?php
}

/** Handle the Add Member form. */
function wpm_handle_create_member(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-memberships' ), 403 );
	}
	check_admin_referer( 'wpm_create_member' );

	$name     = sanitize_text_field( wpm_input( 'wpm_name' ) );
	$email    = sanitize_email( wpm_input( 'wpm_email' ) );
	$pass     = wpm_input( 'wpm_password' ); // Not sanitized: sanitizing would alter the password.
	$tier     = sanitize_key( wpm_input( 'wpm_tier' ) );
	$org      = sanitize_text_field( wpm_input( 'wpm_org' ) );
	$country  = sanitize_text_field( wpm_input( 'wpm_country' ) );
	$status   = sanitize_key( wpm_input( 'wpm_status' ) );
	$can_post = empty( $_POST['wpm_can_post'] ) ? 0 : 1; // phpcs:ignore WordPress.Security.NonceVerification
	$min      = (int) wpm_setting( 'min_password' );

	if ( '' === $name || '' === $email || '' === $pass || '' === $country ) {
		wpm_back( 'missing' );
	}
	if ( ! is_email( $email ) ) {
		wpm_back( 'invalid_email' );
	}
	if ( strlen( $pass ) < $min ) {
		wpm_back( 'weak_password' );
	}
	if ( ! array_key_exists( $tier, wpm_tier_roles() ) ) {
		wpm_back( 'bad_tier' );
	}
	if ( 'corporate' === $tier && '' === $org ) {
		wpm_back( 'missing' );
	}
	if ( email_exists( $email ) || username_exists( $email ) ) {
		wpm_back( 'exists' );
	}

	$role = ( 'active' === $status ) ? wpm_tier_roles()[ $tier ] : 'wpm_pending';

	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $pass,
			'display_name' => $name,
			'role'         => $role,
		)
	);
	if ( is_wp_error( $user_id ) ) {
		wpm_back( 'error' );
	}

	update_user_meta( $user_id, '_wpm_tier', $tier );
	update_user_meta( $user_id, '_wpm_org', $org );
	update_user_meta( $user_id, '_wpm_country', $country );
	update_user_meta( $user_id, '_wpm_applied', time() );
	update_user_meta( $user_id, '_wpm_can_post', $can_post );

	wpm_back( 'member_created' );
}
add_action( 'admin_post_wpm_create_member', 'wpm_handle_create_member' );

/** Tab: active and inactive members. Change levels, content permission, suspend, or reinstate. */
function wpm_tab_members(): void {
	$users = ( new WP_User_Query(
		array(
			'role__in' => array( 'wpm_individual', 'wpm_corporate', 'wpm_rejected' ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 200,
		)
	) )->get_results();

	echo '<h2>Members</h2>';
	if ( empty( $users ) ) {
		echo '<p>No members yet. Approved applicants and members you add will appear here.</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>
		<th>Member</th><th>Status</th><th>Organization</th><th>Country</th><th>Membership level</th><th>Can post</th><th>Access</th>
		</tr></thead><tbody>';

	foreach ( $users as $u ) {
		$id        = (int) $u->ID;
		$tier      = wpm_get_user_tier( $u );
		$inactive  = ( 'rejected' === $tier );
		$suspend   = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_member_action&do=suspend&user_id=' . $id ), 'wpm_member_' . $id );
		$reinstate = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_member_action&do=reinstate&user_id=' . $id ), 'wpm_member_' . $id );
		$current   = (string) get_user_meta( $id, '_wpm_tier', true );
		$can_post  = (bool) get_user_meta( $id, '_wpm_can_post', true );

		echo '<tr><td><strong>' . esc_html( $u->display_name ) . '</strong><br><span class="description">' . esc_html( $u->user_email ) . '</span></td>';
		printf(
			'<td><span class="wpm-status %s">%s</span></td>',
			$inactive ? 'wpm-status-inactive' : ( 'corporate' === $tier ? 'wpm-status-corporate' : '' ),
			esc_html( wpm_tier_label( $tier ) )
		);
		echo '<td>' . esc_html( (string) get_user_meta( $id, '_wpm_org', true ) ?: '—' ) . '</td>';
		echo '<td>' . esc_html( (string) get_user_meta( $id, '_wpm_country', true ) ?: '—' ) . '</td>';

		// Level change form (active members only).
		echo '<td>';
		if ( ! $inactive ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wpm_member_action">';
			echo '<input type="hidden" name="do" value="set_tier">';
			echo '<input type="hidden" name="user_id" value="' . esc_attr( (string) $id ) . '">';
			wp_nonce_field( 'wpm_member_' . $id );
			echo '<select name="tier">';
			foreach ( wpm_tier_roles() as $t => $role ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $t ), selected( $tier, $t, false ), esc_html( wpm_tier_label( $t ) ) );
			}
			echo '</select> <button class="button">Save</button></form>';
		} else {
			echo '<span class="description">Level: ' . esc_html( wpm_tier_label( $current ) ) . '</span>';
		}
		echo '</td>';

		// Content permission toggle (active members only).
		echo '<td>';
		if ( ! $inactive ) {
			$toggle_do = $can_post ? 'posting_off' : 'posting_on';
			$toggle    = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_member_action&do=' . $toggle_do . '&user_id=' . $id ), 'wpm_member_' . $id );
			printf(
				'<span class="wpm-status %s">%s</span><br><a class="button button-small" style="margin-top:4px" href="%s">%s</a>',
				$can_post ? '' : 'wpm-status-off',
				$can_post ? 'Allowed' : 'Blocked',
				esc_url( $toggle ),
				$can_post ? 'Disable posting' : 'Enable posting'
			);
		} else {
			echo '<span class="description">—</span>';
		}
		echo '</td>';

		// Access control link.
		echo '<td>';
		if ( $inactive ) {
			printf( '<a class="button button-primary" href="%s">Reinstate</a>', esc_url( $reinstate ) );
		} else {
			printf( '<a class="button" href="%s" onclick="return confirm(\'Suspend this member?\');">Suspend</a>', esc_url( $suspend ) );
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

/** Tab: member content. Waiting items first, then published posts. */
function wpm_tab_content(): void {
	$waiting   = get_posts(
		array(
			'post_type'   => 'wpm_entry',
			'post_status' => array( 'pending', 'draft' ),
			'numberposts' => 200,
		)
	);
	$published = wpm_member_posts( array( 'numberposts' => 200 ) );

	$all = array_merge( $waiting, $published );
	usort(
		$all,
		static function ( $a, $b ) {
			$pa = ( 'pending' === $a->post_status ) ? 0 : 1;
			$pb = ( 'pending' === $b->post_status ) ? 0 : 1;
			if ( $pa !== $pb ) {
				return $pa <=> $pb;
			}
			return strtotime( $b->post_date ) <=> strtotime( $a->post_date );
		}
	);

	echo '<h2>Member content</h2>';
	echo '<p class="description">Approving a submission publishes it as a regular post, visible to everyone on the site. Items awaiting approval are listed first.</p>';

	if ( empty( $all ) ) {
		echo '<p>No member content yet.</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>
		<th>Title</th><th>Author</th><th>Format</th><th>Status</th><th>Date</th><th>Actions</th>
		</tr></thead><tbody>';

	foreach ( $all as $post ) {
		$author  = get_userdata( (int) $post->post_author );
		$format  = (string) get_post_meta( $post->ID, '_wpm_format', true ) ?: 'html';
		$is_post = ( 'post' === $post->post_type );
		$waiting = ( 'pending' === $post->post_status );

		$approve = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_moderate_entry&do=approve&entry_id=' . $post->ID ), 'wpm_moderate_' . $post->ID );
		$reject  = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_moderate_entry&do=reject&entry_id=' . $post->ID ), 'wpm_moderate_' . $post->ID );
		$delete  = wp_nonce_url( admin_url( 'admin-post.php?action=wpm_delete_entry&entry_id=' . $post->ID ), 'wpm_delete_entry_' . $post->ID );

		if ( $is_post ) {
			$status_label = 'Published (public)';
			$status_class = 'wpm-status-live';
		} elseif ( $waiting ) {
			$status_label = 'Awaiting approval';
			$status_class = 'wpm-status-pending';
		} else {
			$status_label = 'Draft';
			$status_class = 'wpm-status-off';
		}

		echo '<tr><td><strong>' . esc_html( $post->post_title ) . '</strong></td>';
		echo '<td>' . esc_html( $author ? $author->display_name : '—' ) . '</td>';
		echo '<td>' . esc_html( 'markdown' === $format ? 'Markdown' : 'HTML' ) . '</td>';
		printf( '<td><span class="wpm-status %s">%s</span></td>', esc_attr( $status_class ), esc_html( $status_label ) );
		echo '<td>' . esc_html( get_the_date( '', $post ) ) . '</td>';
		echo '<td>';
		echo '<a class="button" href="' . esc_url( get_permalink( $post ) ) . '" target="_blank">' . ( $waiting || ! $is_post ? 'Preview' : 'View' ) . '</a> ';
		if ( $waiting ) {
			echo '<a class="button button-primary" href="' . esc_url( $approve ) . '">Approve</a> ';
			echo '<a class="button" href="' . esc_url( $reject ) . '" onclick="return confirm(\'Reject this content? It will be moved to the Trash.\');">Reject</a> ';
		}
		echo '<a class="button" href="' . esc_url( $delete ) . '" onclick="return confirm(\'Permanently delete this content?\');">Delete</a>';
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}

/** Tab: settings, pages, and shortcode reference. */
function wpm_tab_settings(): void {
	$tiers   = wpm_public_tiers();
	$reg_on  = (bool) wpm_setting( 'registration_open' );
	$min     = (int) wpm_setting( 'min_password' );
	$message = (string) wpm_setting( 'pending_message' );
	?>
	<h2>Settings</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wpm_save_settings">
		<?php wp_nonce_field( 'wpm_save_settings' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Accept new applications</th>
				<td><label><input type="checkbox" name="registration_open" value="1" <?php checked( $reg_on ); ?>> Show the Join form to visitors</label></td>
			</tr>
			<tr>
				<th scope="row">Visible to logged-out visitors in the directory</th>
				<td>
					<label><input type="checkbox" name="public_tiers[]" value="individual" <?php checked( in_array( 'individual', $tiers, true ) ); ?>> Individual members</label><br>
					<label><input type="checkbox" name="public_tiers[]" value="corporate" <?php checked( in_array( 'corporate', $tiers, true ) ); ?>> Corporate members</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_min_password">Minimum password length</label></th>
				<td><input type="number" id="wpm_min_password" name="min_password" min="6" max="64" value="<?php echo esc_attr( (string) $min ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpm_pending_message">Message for applicants waiting for approval</label></th>
				<td><textarea id="wpm_pending_message" name="pending_message" rows="3" class="large-text"><?php echo esc_textarea( $message ); ?></textarea></td>
			</tr>
		</table>
		<?php submit_button( 'Save Settings' ); ?>
	</form>

	<h2>Pages</h2>
	<table class="widefat striped" style="max-width:900px">
		<thead><tr><th>Page</th><th>Address</th><th>Status</th><th>Shortcode</th></tr></thead>
		<tbody>
		<?php foreach ( wpm_default_pages() as $slug => $page ) :
			$existing = get_page_by_path( $slug );
			?>
			<tr>
				<td><?php echo esc_html( $page['title'] ); ?></td>
				<td><?php echo $existing ? '<a href="' . esc_url( get_permalink( $existing ) ) . '" target="_blank">/' . esc_html( $slug ) . '/</a>' : '/' . esc_html( $slug ) . '/'; ?></td>
				<td><?php echo $existing ? '<span class="wpm-status">Live</span>' : '<span class="wpm-status wpm-status-inactive">Missing</span>'; ?></td>
				<td><code><?php echo esc_html( $page['content'] ); ?></code></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
		<input type="hidden" name="action" value="wpm_create_pages">
		<?php wp_nonce_field( 'wpm_create_pages' ); ?>
		<button class="button">Create missing pages</button>
	</form>

	<h2>Shortcode reference</h2>
	<table class="widefat striped" style="max-width:900px">
		<thead><tr><th>Shortcode</th><th>What it does</th></tr></thead>
		<tbody>
			<tr><td><code>[wpm_register]</code></td><td>Application form</td></tr>
			<tr><td><code>[wpm_directory]</code></td><td>Searchable member directory with photos, plus a profile view per member</td></tr>
			<tr><td><code>[wpm_member_nav]</code></td><td>Member menu bar</td></tr>
			<tr><td><code>[wpm_my_content]</code></td><td>Markdown editor, plus members' own and published content</td></tr>
			<tr><td><code>[wpm_profile]</code></td><td>Member's photo, details, and password form</td></tr>
			<tr><td><code>[wpm_restrict tier="individual"]...[/wpm_restrict]</code></td><td>Content for individual and corporate members</td></tr>
			<tr><td><code>[wpm_restrict tier="corporate"]...[/wpm_restrict]</code></td><td>Content for corporate members only</td></tr>
		</tbody>
	</table>
	<p class="description">The Member Portal and each member profile build their content automatically, so you don't need to edit those pages.</p>
	<?php
}

/* =========================================================================
 * 12. DASHBOARD ACTIONS (ADMINS)
 * ========================================================================= */

/** Save the settings form. */
function wpm_handle_save_settings(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-memberships' ), 403 );
	}
	check_admin_referer( 'wpm_save_settings' );

	$raw_tiers = isset( $_POST['public_tiers'] ) && is_array( $_POST['public_tiers'] ) ? wp_unslash( $_POST['public_tiers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
	$tiers     = array_values( array_intersect( array_map( 'sanitize_key', $raw_tiers ), array_keys( wpm_tier_roles() ) ) );

	update_option(
		'wpm_settings',
		array(
			'registration_open' => empty( $_POST['registration_open'] ) ? 0 : 1, // phpcs:ignore WordPress.Security.NonceVerification
			'public_tiers'      => $tiers,
			'min_password'      => min( 64, max( 6, absint( wpm_input( 'min_password' ) ) ) ),
			'pending_message'   => sanitize_textarea_field( wpm_input( 'pending_message' ) ),
		)
	);

	wpm_back( 'settings_saved' );
}
add_action( 'admin_post_wpm_save_settings', 'wpm_handle_save_settings' );

/** Create any missing default pages. */
function wpm_handle_create_pages(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-memberships' ), 403 );
	}
	check_admin_referer( 'wpm_create_pages' );
	wpm_create_pages();
	wpm_back( 'pages_created' );
}
add_action( 'admin_post_wpm_create_pages', 'wpm_handle_create_pages' );

/**
 * One handler for approve, reject, suspend, reinstate, level changes, and content permission.
 * Every action is checked for administrator rights and a per-user nonce.
 */
function wpm_handle_member_action(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-memberships' ), 403 );
	}

	$user_id = absint( wp_unslash( $_REQUEST['user_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
	check_admin_referer( 'wpm_member_' . $user_id );

	$user = get_userdata( $user_id );
	if ( ! $user || $user->has_cap( 'manage_options' ) ) {
		wpm_back( 'not_allowed' );
	}

	$do        = sanitize_key( wp_unslash( $_REQUEST['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$tiers     = wpm_tier_roles();
	$requested = (string) get_user_meta( $user_id, '_wpm_tier', true );
	$roles     = (array) $user->roles;
	$is_pend   = in_array( 'wpm_pending', $roles, true );
	$is_inact  = in_array( 'wpm_rejected', $roles, true );
	$is_member = (bool) array_intersect( $roles, array_values( $tiers ) );

	switch ( $do ) {
		case 'approve':
			if ( ! $is_pend || ! isset( $tiers[ $requested ] ) ) {
				wpm_back( 'not_allowed' );
			}
			$user->set_role( $tiers[ $requested ] );
			wpm_back( 'approved' );

		case 'reject':
			if ( ! $is_pend ) {
				wpm_back( 'not_allowed' );
			}
			$user->set_role( 'wpm_rejected' );
			wpm_back( 'rejected' );

		case 'suspend':
			if ( ! $is_member ) {
				wpm_back( 'not_allowed' );
			}
			$user->set_role( 'wpm_rejected' );
			wpm_back( 'suspended' );

		case 'reinstate':
			if ( ! $is_inact || ! isset( $tiers[ $requested ] ) ) {
				wpm_back( 'not_allowed' );
			}
			$user->set_role( $tiers[ $requested ] );
			wpm_back( 'reinstated' );

		case 'set_tier':
			$tier = sanitize_key( wp_unslash( $_REQUEST['tier'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( ! $is_member || ! isset( $tiers[ $tier ] ) ) {
				wpm_back( 'not_allowed' );
			}
			update_user_meta( $user_id, '_wpm_tier', $tier );
			$user->set_role( $tiers[ $tier ] );
			wpm_back( 'tier_changed' );

		case 'posting_on':
		case 'posting_off':
			if ( ! $is_member ) {
				wpm_back( 'not_allowed' );
			}
			update_user_meta( $user_id, '_wpm_can_post', 'posting_on' === $do ? 1 : 0 );
			wpm_back( 'posting_on' === $do ? 'posting_on' : 'posting_off' );
	}

	wpm_back( 'not_allowed' );
}
add_action( 'admin_post_wpm_member_action', 'wpm_handle_member_action' );

/** Approve (publish as a regular post) or reject a content item from the Member Content tab. */
function wpm_handle_moderate_entry(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'wp-memberships' ), 403 );
	}

	$entry_id = absint( wpm_input( 'entry_id', 'get' ) );
	check_admin_referer( 'wpm_moderate_' . $entry_id );

	$post = get_post( $entry_id );
	if ( ! $post || 'wpm_entry' !== $post->post_type || 'pending' !== $post->post_status ) {
		wpm_back( 'not_allowed' );
	}

	$do = sanitize_key( wpm_input( 'do', 'get' ) );
	if ( 'approve' === $do ) {
		wpm_publish_entry_as_post( $entry_id );
		wpm_back( 'entry_approved' );
	}
	if ( 'reject' === $do ) {
		wp_trash_post( $entry_id );
		wpm_back( 'entry_rejected' );
	}

	wpm_back( 'not_allowed' );
}
add_action( 'admin_post_wpm_moderate_entry', 'wpm_handle_moderate_entry' );

/* =========================================================================
 * 13. MEMBER CONTENT: SAVE & DELETE
 * ========================================================================= */

/**
 * Create or update a content submission from the Markdown editor.
 * - Only members with posting enabled (and administrators) may save.
 * - The browser sends the rendered HTML and the raw Markdown. If the rendered HTML is missing
 *   (for example, the editor libraries did not load), the server converts the Markdown itself.
 * - All HTML is sanitized with wp_kses_post, including content from administrators.
 * - Members' submissions wait for approval, then become regular posts.
 * - Administrators' content is published as a regular post straight away.
 */
function wpm_handle_save_entry(): void {
	if ( ! is_user_logged_in() || ! wpm_user_can_post() ) {
		wpm_back( 'not_allowed' );
	}
	check_admin_referer( 'wpm_save_entry' );

	$uid      = get_current_user_id();
	$is_admin = current_user_can( 'manage_options' );
	$entry_id = absint( wpm_input( 'entry_id' ) );
	$title    = sanitize_text_field( wpm_input( 'entry_title' ) );
	$markdown = wpm_input( 'entry_markdown' ); // Raw Markdown, kept for editing.
	$rendered = wpm_input( 'entry_html' );     // Rendered by the editor in the browser.
	$vis      = sanitize_key( wpm_input( 'entry_visibility' ) );

	if ( ! in_array( $vis, array( 'members', 'private' ), true ) ) {
		$vis = 'private';
	}
	if ( '' === $title ) {
		wpm_back( 'missing_title' );
	}
	if ( '' === trim( $markdown ) ) {
		wpm_back( 'missing' );
	}

	$html = '' !== trim( $rendered ) ? $rendered : wpm_markdown_to_html( $markdown );
	$body = wp_kses_post( $html );

	$args = array(
		'post_title'   => $title,
		'post_content' => $body,
		'post_status'  => 'pending',
	);

	if ( $entry_id ) {
		// Editing is only allowed for submissions still waiting for approval.
		$existing = get_post( $entry_id );
		if ( ! $existing || 'wpm_entry' !== $existing->post_type || ( (int) $existing->post_author !== $uid && ! $is_admin ) ) {
			wpm_back( 'not_allowed' );
		}
		$saved = wp_update_post( array_merge( $args, array( 'ID' => $entry_id ) ), true );
	} else {
		$saved = wp_insert_post(
			array_merge(
				$args,
				array(
					'post_type'   => 'wpm_entry',
					'post_author' => $uid,
				)
			),
			true
		);
	}

	if ( is_wp_error( $saved ) || ! $saved ) {
		wpm_back( 'error' );
	}

	$saved = (int) $saved;
	update_post_meta( $saved, '_wpm_visibility', $vis );
	update_post_meta( $saved, '_wpm_format', 'markdown' );
	update_post_meta( $saved, '_wpm_markdown_source', $markdown );

	// Administrators publish straight away; members wait for approval.
	if ( $is_admin ) {
		wpm_publish_entry_as_post( $saved );
		wpm_back( 'entry_saved' );
	}

	wpm_back( 'entry_pending' );
}
add_action( 'admin_post_wpm_save_entry', 'wpm_handle_save_entry' );

/** Delete a submission or a published member post. Members can delete their own; administrators can delete any. */
function wpm_handle_delete_entry(): void {
	$entry_id = absint( wpm_input( 'entry_id', 'get' ) );
	if ( ! is_user_logged_in() || ! $entry_id ) {
		wpm_back( 'not_allowed' );
	}
	check_admin_referer( 'wpm_delete_entry_' . $entry_id );

	$post = get_post( $entry_id );
	if ( ! $post ) {
		wpm_back( 'not_allowed' );
	}

	$is_submission  = ( 'wpm_entry' === $post->post_type );
	$is_member_post = ( 'post' === $post->post_type && '1' === (string) get_post_meta( $entry_id, '_wpm_member_post', true ) );
	if ( ! $is_submission && ! $is_member_post ) {
		wpm_back( 'not_allowed' );
	}
	if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
		wpm_back( 'not_allowed' );
	}

	wp_delete_post( $entry_id, true );
	wpm_back( 'entry_deleted' );
}
add_action( 'admin_post_wpm_delete_entry', 'wpm_handle_delete_entry' );

/* =========================================================================
 * 14. MY MEMBERSHIP (EVERY LOGGED-IN USER)
 * ========================================================================= */

/** Render the user's own options page inside the dashboard. */
function wpm_render_my_membership(): void {
	if ( ! is_user_logged_in() ) {
		wp_die( esc_html__( 'Please log in.', 'wp-memberships' ), 403 );
	}

	$user    = wp_get_current_user();
	$tier    = wpm_get_user_tier( $user );
	$org     = (string) get_user_meta( $user->ID, '_wpm_org', true );
	$country = (string) get_user_meta( $user->ID, '_wpm_country', true );
	$bio     = (string) get_user_meta( $user->ID, '_wpm_bio', true );
	$is_corp = ( 'corporate' === $tier );

	echo '<div class="wrap"><h1>My Membership</h1>';
	echo wpm_notice_html( 'admin' ); // phpcs:ignore WordPress.Security.EscapeOutput

	if ( '' !== $tier ) {
		echo '<p>Your membership level: <strong>' . esc_html( wpm_tier_label( $tier ) ) . '</strong></p>';
	}
	if ( 'pending' === $tier ) {
		echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) wpm_setting( 'pending_message' ) ) . '</p></div>';
	}
	if ( wpm_is_active_member() ) {
		printf(
			'<p><a class="button button-primary" href="%s">Go to Member Portal to add your content</a></p>',
			esc_url( wpm_portal_url() . '#wpm-my-content' )
		);
	}

	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
	echo '<input type="hidden" name="action" value="wpm_profile">';
	wp_nonce_field( 'wpm_profile' );

	echo '<h2>Your photo</h2><table class="form-table" role="presentation"><tr><th>Profile photo</th><td>';
	$preview = wpm_photo_html( $user->ID, 'thumb', 'Your current profile photo', 'wpm-photo-preview' );
	if ( '' !== $preview ) {
		echo $preview; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<label><input type="checkbox" name="wpm_photo_remove" value="1"> Remove current photo</label><br><br>';
	}
	echo '<input type="file" name="wpm_photo" accept="image/jpeg,image/png,image/webp">';
	echo '<p class="description">JPG, PNG, or WebP, up to 5 MB. It is resized automatically for the directory and your profile.</p>';
	echo '</td></tr></table>';

	echo '<h2>Your details</h2><table class="form-table" role="presentation">';
	printf( '<tr><th><label for="wpm_name">Full name</label></th><td><input type="text" id="wpm_name" name="wpm_name" value="%s" class="regular-text"></td></tr>', esc_attr( $user->display_name ) );
	printf(
		'<tr><th><label for="wpm_org">%s</label></th><td><input type="text" id="wpm_org" name="wpm_org" value="%s" class="regular-text"></td></tr>',
		$is_corp ? 'Organization name' : 'Organization (optional)',
		esc_attr( $org )
	);
	printf( '<tr><th><label for="wpm_country">Country</label></th><td><input type="text" id="wpm_country" name="wpm_country" value="%s" class="regular-text"></td></tr>', esc_attr( $country ) );
	printf( '<tr><th><label for="wpm_bio">Bio</label></th><td><textarea id="wpm_bio" name="wpm_bio" rows="4" class="large-text">%s</textarea><p class="description">Shown in the member directory to approved members.</p></td></tr>', esc_textarea( $bio ) );
	echo '</table>';

	echo '<h2>Change password</h2><p class="description">Leave blank to keep your current password.</p>';
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th><label for="wpm_current_password">Current password</label></th><td><input type="password" id="wpm_current_password" name="wpm_current_password" autocomplete="current-password" class="regular-text"></td></tr>';
	printf( '<tr><th><label for="wpm_new_password">New password</label></th><td><input type="password" id="wpm_new_password" name="wpm_new_password" minlength="%d" autocomplete="new-password" class="regular-text"></td></tr>', (int) wpm_setting( 'min_password' ) );
	echo '</table>';

	submit_button( 'Save My Details' );
	echo '</form></div>';
}

/** Save the details form (used by both My Membership and the portal page). */
function wpm_handle_profile(): void {
	if ( ! is_user_logged_in() ) {
		wpm_back( 'error' );
	}
	check_admin_referer( 'wpm_profile' );

	$user = wp_get_current_user();
	if ( in_array( wpm_get_user_tier( $user ), array( 'pending', 'rejected' ), true ) ) {
		wpm_back( 'not_allowed' );
	}

	$name    = sanitize_text_field( wpm_input( 'wpm_name' ) );
	$org     = sanitize_text_field( wpm_input( 'wpm_org' ) );
	$country = sanitize_text_field( wpm_input( 'wpm_country' ) );
	$bio     = sanitize_textarea_field( wpm_input( 'wpm_bio' ) );

	$new_pass = wpm_input( 'wpm_new_password' );     // Raw on purpose.
	$current  = wpm_input( 'wpm_current_password' ); // Raw on purpose.

	// Validate the password change before saving anything.
	if ( '' !== $new_pass ) {
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			wpm_back( 'pw_wrong' );
		}
		if ( strlen( $new_pass ) < (int) wpm_setting( 'min_password' ) ) {
			wpm_back( 'weak_password' );
		}
	}

	// Photo: process the upload first, so a bad image stops the whole save.
	$photo_error = wpm_save_photo( $user->ID );
	if ( '' !== $photo_error ) {
		wpm_back( $photo_error );
	}
	if ( ! empty( $_POST['wpm_photo_remove'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		wpm_delete_photo( $user->ID );
	}

	if ( '' !== $name ) {
		wp_update_user( array( 'ID' => $user->ID, 'display_name' => $name ) );
	}
	update_user_meta( $user->ID, '_wpm_org', $org );
	update_user_meta( $user->ID, '_wpm_country', $country );
	update_user_meta( $user->ID, '_wpm_bio', $bio );

	if ( '' !== $new_pass ) {
		wp_set_password( $new_pass, $user->ID ); // Ends existing sessions.
		wp_set_auth_cookie( $user->ID );         // Keep this device signed in.
	}

	wpm_back( 'profile_saved' );
}
add_action( 'admin_post_wpm_profile', 'wpm_handle_profile' );