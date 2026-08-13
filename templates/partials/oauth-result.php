<?php
/**
 * OAuth connection result.
 *
 * @var array $oauth_result Connected account details.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;

$saved_to_license = ! empty( $oauth_result['saved_to_license'] );
$license_label      = (string) ( $oauth_result['license_label'] ?? $oauth_result['license_key'] ?? '' );
?>
<div class="lumina-hub-card lumina-hub-card--success">
	<h2>Instagram connected</h2>
	<?php if ( $saved_to_license ) : ?>
		<p class="description">The token and User ID were saved to <strong><?php echo htmlspecialchars( $license_label, ENT_QUOTES, 'UTF-8' ); ?></strong>. Paste the license key into the WordPress plugin settings and the feed should work immediately.</p>
	<?php else : ?>
		<p class="description">Copy the User ID into a license row, or start OAuth again from a specific client row so the token is saved automatically.</p>
	<?php endif; ?>

	<div class="lumina-hub-oauth-grid">
		<div class="lumina-hub-field">
			<label>License User ID</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $oauth_result['user_id'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
			<p class="description">IDs starting with <code>2808…</code> are normal for Instagram Login.</p>
		</div>
		<?php if ( ! empty( $oauth_result['app_scoped_id'] ) && $oauth_result['app_scoped_id'] !== ( $oauth_result['user_id'] ?? '' ) ) : ?>
		<div class="lumina-hub-field">
			<label>App-scoped ID (reference only)</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) $oauth_result['app_scoped_id'], ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
		<?php endif; ?>
		<div class="lumina-hub-field">
			<label>Username</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $oauth_result['username'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
		<div class="lumina-hub-field">
			<label>Account type</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $oauth_result['account_type'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
		</div>
	</div>

	<?php if ( ! $saved_to_license ) : ?>
	<div class="lumina-hub-field">
		<label>Long-lived access token (60 days)</label>
		<textarea readonly rows="4" onclick="this.select();"><?php echo htmlspecialchars( (string) ( $oauth_result['access_token'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?></textarea>
		<?php if ( ! empty( $oauth_result['expires_in'] ) ) : ?>
			<p class="description">Expires in <?php echo (int) floor( (int) $oauth_result['expires_in'] / 86400 ); ?> days. Set a reminder to reconnect before it lapses.</p>
		<?php endif; ?>
		<?php if ( ! empty( $oauth_result['token_note'] ) ) : ?>
			<p class="description"><?php echo htmlspecialchars( (string) $oauth_result['token_note'], ENT_QUOTES, 'UTF-8' ); ?></p>
		<?php endif; ?>
	</div>
	<?php else : ?>
		<?php if ( ! empty( $oauth_result['expires_in'] ) ) : ?>
			<p class="description">Token expires in about <?php echo (int) floor( (int) $oauth_result['expires_in'] / 86400 ); ?> days. Reconnect from the client row before it lapses.</p>
		<?php endif; ?>
		<?php if ( ! empty( $oauth_result['token_note'] ) ) : ?>
			<p class="description"><?php echo htmlspecialchars( (string) $oauth_result['token_note'], ENT_QUOTES, 'UTF-8' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</div>
