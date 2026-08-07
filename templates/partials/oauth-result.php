<?php
/**
 * OAuth connection result.
 *
 * @var array $oauth_result Connected account details.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;

$config_snippet = "<?php\n\nreturn array(\n\t'instagram_access_token' => '" . ( $oauth_result['access_token'] ?? '' ) . "',\n\t// ... keep your other config values ...\n);\n";
?>
<div class="lumina-hub-card lumina-hub-card--success">
	<h2>Instagram connected</h2>
	<p class="description">Copy these values into <code>config.php</code> and the client license in the table below. This page shows the token once — store it securely.</p>

	<div class="lumina-hub-oauth-grid">
		<div class="lumina-hub-field">
			<label>License User ID</label>
			<input type="text" readonly value="<?php echo htmlspecialchars( (string) ( $oauth_result['user_id'] ?? '' ), ENT_QUOTES, 'UTF-8' ); ?>" onclick="this.select();" />
			<p class="description">Paste this exact value into the license row. IDs starting with <code>2808…</code> are normal for Instagram Login.</p>
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

	<div class="lumina-hub-field">
		<label><code>config.php</code> snippet</label>
		<textarea readonly rows="6" onclick="this.select();"><?php echo htmlspecialchars( $config_snippet, ENT_QUOTES, 'UTF-8' ); ?></textarea>
	</div>
</div>
