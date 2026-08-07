<?php
/**
 * Login form content.
 *
 * @var bool $error Whether login failed.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;
?>
<div class="lumina-hub-card lumina-hub-card--narrow">
	<h2>Sign in</h2>
	<?php if ( ! empty( $error ) ) : ?>
		<div class="lumina-hub-error">Invalid password. Please try again.</div>
	<?php endif; ?>
	<?php if ( ! empty( $oauth_pending ) ) : ?>
		<div class="lumina-hub-notice">Instagram authorization completed. Sign in to view your access token and User ID.</div>
	<?php endif; ?>
	<?php if ( ! empty( $_GET['csrf_error'] ) ) : ?>
		<div class="lumina-hub-error">Your session expired. Please try again.</div>
	<?php endif; ?>
	<form method="post" action="/manage">
		<?php include __DIR__ . '/partials/csrf.php'; ?>
		<?php if ( ! empty( $oauth_done ) ) : ?>
			<input type="hidden" name="oauth_done" value="<?php echo htmlspecialchars( $oauth_done, ENT_QUOTES, 'UTF-8' ); ?>" />
		<?php endif; ?>
		<div class="lumina-hub-field">
			<label for="hub_password">Admin password</label>
			<input type="password" id="hub_password" name="hub_password" required autocomplete="current-password" />
		</div>
		<button type="submit" class="lumina-hub-btn lumina-hub-btn--block">Sign in to Lumina</button>
	</form>
</div>
