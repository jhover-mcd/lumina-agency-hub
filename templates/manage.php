<?php
/**
 * License management content.
 *
 * @var array $licenses License map.
 * @var bool  $demo_mode Whether demo mode is enabled.
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
			<label for="user_id">Instagram User ID</label>
			<input type="text" id="user_id" name="user_id" required placeholder="17841400000000000" />
		</div>
		<button type="submit" class="lumina-hub-btn">Add license</button>
	</form>
</div>
