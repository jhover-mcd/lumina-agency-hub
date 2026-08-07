<?php
/**
 * License management content.
 *
 * @var array $licenses License map.
 * @var bool  $demo_mode Whether demo mode is enabled.
 * @var bool  $oauth_ready Whether Instagram OAuth is configured.
 * @var array|null $oauth_result OAuth connection result to display once.
 * @var string $oauth_error OAuth error message.
 * @var string $oauth_redirect_uri Registered redirect URI.
 * @var array|null $token_account Live lookup from config token.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;

$active_count  = 0;
$revoked_count = 0;

foreach ( $licenses as $license ) {
	if ( ! empty( $license['active'] ) ) {
		++$active_count;
	} else {
		++$revoked_count;
	}
}
?>
<?php if ( ! empty( $_GET['revoked'] ) ) : ?><div class="lumina-hub-notice">Feed revoked. The client site will stop receiving posts on the next cache refresh.</div><?php endif; ?>
<?php if ( ! empty( $_GET['activated'] ) ) : ?><div class="lumina-hub-notice">License reactivated.</div><?php endif; ?>
<?php if ( ! empty( $_GET['added'] ) ) : ?><div class="lumina-hub-notice">License added.</div><?php endif; ?>
<?php if ( ! empty( $_GET['updated'] ) ) : ?><div class="lumina-hub-notice">Instagram User ID updated.</div><?php endif; ?>
<?php if ( ! empty( $_GET['csrf_error'] ) ) : ?><div class="lumina-hub-error">Your session expired. Please try again.</div><?php endif; ?>
<?php if ( ! empty( $_GET['oauth_config_error'] ) ) : ?><div class="lumina-hub-error">Add <code>instagram_app_id</code>, <code>instagram_app_secret</code>, and <code>hub_public_url</code> to <code>config.php</code> before connecting Instagram.</div><?php endif; ?>
<?php if ( ! empty( $_GET['oauth_error'] ) ) : ?><div class="lumina-hub-error"><?php echo htmlspecialchars( (string) $_GET['oauth_error'], ENT_QUOTES, 'UTF-8' ); ?></div><?php endif; ?>
<?php if ( ! empty( $oauth_error ) ) : ?><div class="lumina-hub-error"><?php echo htmlspecialchars( $oauth_error, ENT_QUOTES, 'UTF-8' ); ?></div><?php endif; ?>

<?php if ( ! empty( $oauth_result ) ) : ?>
	<?php include __DIR__ . '/partials/oauth-result.php'; ?>
<?php endif; ?>

<div class="lumina-hub-card">
	<h2>Connect Instagram account</h2>
	<?php if ( ! empty( $oauth_ready ) ) : ?>
		<p class="description">Use this when onboarding a client Instagram Business or Creator account. The client can log in on Instagram’s screen; you’ll get a long-lived token and User ID to paste into <code>config.php</code> and the license below.</p>
		<p class="description"><strong>Redirect URI</strong> (must match Meta App Dashboard exactly):<br /><code class="lumina-hub-code"><?php echo htmlspecialchars( $oauth_redirect_uri, ENT_QUOTES, 'UTF-8' ); ?></code></p>
		<p class="description">In Meta App Dashboard → Instagram → API setup with Instagram login → Business login settings, add that URI under OAuth redirect URIs.</p>
		<a class="lumina-hub-btn" href="/oauth/start">Connect Instagram account</a>
	<?php else : ?>
		<p class="description">Add your Instagram app credentials to <code>config.php</code> to enable the connect flow:</p>
		<ul class="lumina-hub-list">
			<li><code>instagram_app_id</code></li>
			<li><code>instagram_app_secret</code></li>
			<li><code>hub_public_url</code> (e.g. https://lumina.mcddigital.biz)</li>
		</ul>
	<?php endif; ?>
</div>

<?php if ( ! empty( $token_account ) && empty( $token_account['error'] ) ) : ?>
<div class="lumina-hub-card">
	<h2>Current token account</h2>
	<p class="description">This is read live from the access token in <code>config.php</code>. Paste the License User ID into each license row exactly as shown here.</p>
	<div class="lumina-hub-oauth-grid">
		<div class="lumina-hub-field">
			<label>License User ID</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $token_account['user_id'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
		<div class="lumina-hub-field">
			<label>Username</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $token_account['username'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
		<div class="lumina-hub-field">
			<label>Account type</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $token_account['account_type'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
	</div>
	<p class="description">Instagram Login IDs often start with <code>2808…</code> instead of <code>178414…</code>. That is normal. The hub now loads media via <code>/me/media</code>, not by calling the numeric ID directly.</p>
</div>
<?php elseif ( ! empty( $token_account['error'] ) ) : ?>
<div class="lumina-hub-error">Could not read the current token account: <?php echo htmlspecialchars( (string) $token_account['error'], ENT_QUOTES, 'UTF-8' ); ?></div>
<?php endif; ?>

<div class="lumina-hub-stats">
	<div class="lumina-hub-stat">
		<strong><?php echo (int) count( $licenses ); ?></strong>
		<span>Total licenses</span>
	</div>
	<div class="lumina-hub-stat">
		<strong><?php echo (int) $active_count; ?></strong>
		<span>Active feeds</span>
	</div>
	<div class="lumina-hub-stat">
		<strong><?php echo (int) $revoked_count; ?></strong>
		<span>Revoked</span>
	</div>
	<div class="lumina-hub-stat">
		<strong><?php echo ! empty( $demo_mode ) ? 'Demo' : 'Live'; ?></strong>
		<span>Instagram mode</span>
	</div>
</div>

<div class="lumina-hub-card">
	<h2>Client licenses</h2>
	<table class="lumina-hub-table">
		<thead>
			<tr>
				<th>License key</th>
				<th>Label</th>
				<th>Instagram User ID</th>
				<th>Status</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $licenses ) ) : ?>
				<tr>
					<td colspan="5">No licenses yet. Add your first client site below.</td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $licenses as $key => $license ) : ?>
				<tr>
					<td><code class="lumina-hub-code"><?php echo htmlspecialchars( $key, ENT_QUOTES, 'UTF-8' ); ?></code></td>
					<td><?php echo htmlspecialchars( $license['label'] ?? '', ENT_QUOTES, 'UTF-8' ); ?></td>
					<td>
						<form method="post" action="/manage">
							<?php include __DIR__ . '/partials/csrf.php'; ?>
							<input type="hidden" name="action" value="update_user_id" />
							<input type="hidden" name="license_key" value="<?php echo htmlspecialchars( $key, ENT_QUOTES, 'UTF-8' ); ?>" />
							<div class="lumina-hub-field">
								<input type="text" name="user_id" value="<?php echo htmlspecialchars( $license['user_id'] ?? '', ENT_QUOTES, 'UTF-8' ); ?>" />
							</div>
							<button type="submit" class="lumina-hub-btn lumina-hub-btn--dark">Update ID</button>
						</form>
					</td>
					<td>
						<?php if ( ! empty( $license['active'] ) ) : ?>
							<span class="lumina-hub-badge lumina-hub-badge--active">Active</span>
						<?php else : ?>
							<span class="lumina-hub-badge lumina-hub-badge--revoked">Revoked</span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $license['active'] ) ) : ?>
							<form method="post" action="/manage" onsubmit="return confirm('Revoke this feed?');">
								<?php include __DIR__ . '/partials/csrf.php'; ?>
								<input type="hidden" name="action" value="revoke" />
								<input type="hidden" name="license_key" value="<?php echo htmlspecialchars( $key, ENT_QUOTES, 'UTF-8' ); ?>" />
								<button type="submit" class="lumina-hub-btn lumina-hub-btn--danger">Revoke feed</button>
							</form>
						<?php else : ?>
							<form method="post" action="/manage">
								<?php include __DIR__ . '/partials/csrf.php'; ?>
								<input type="hidden" name="action" value="activate" />
								<input type="hidden" name="license_key" value="<?php echo htmlspecialchars( $key, ENT_QUOTES, 'UTF-8' ); ?>" />
								<button type="submit" class="lumina-hub-btn lumina-hub-btn--success">Reactivate</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<div class="lumina-hub-card">
	<h2>Add client site</h2>
	<form method="post" action="/manage">
		<?php include __DIR__ . '/partials/csrf.php'; ?>
		<input type="hidden" name="action" value="add" />
		<div class="lumina-hub-field">
			<label for="license_key">License key</label>
			<input type="text" id="license_key" name="license_key" required placeholder="unique-site-key-abc123" />
		</div>
		<div class="lumina-hub-field">
			<label for="label">Label</label>
			<input type="text" id="label" name="label" placeholder="Client site name" />
		</div>
		<div class="lumina-hub-field">
			<label for="user_id">License User ID</label>
			<input type="text" id="user_id" name="user_id" required placeholder="28080537801582012" />
		</div>
		<button type="submit" class="lumina-hub-btn">Add license</button>
	</form>
</div>
