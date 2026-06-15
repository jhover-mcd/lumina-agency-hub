<?php
/**
 * CSRF hidden field for hub forms.
 *
 * @var string $csrf_token CSRF token.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;
?>
<input type="hidden" name="_hub_csrf" value="<?php echo htmlspecialchars( $csrf_token, ENT_QUOTES, 'UTF-8' ); ?>" />
