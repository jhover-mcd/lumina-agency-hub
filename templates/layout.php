<?php
/**
 * Shared Lumina Agency Hub layout.
 *
 * @var string $page_title Page title.
 * @var string $page_heading Hero heading.
 * @var string $page_intro Optional intro copy.
 * @var string $content    Rendered inner template HTML.
 * @var bool   $narrow     Whether to constrain hero width for auth pages.
 */

defined( 'LUMINA_HUB_RENDER' ) || exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo htmlspecialchars( $page_title, ENT_QUOTES, 'UTF-8' ); ?> — Lumina</title>
	<link rel="stylesheet" href="/assets/hub.css" />
</head>
<body>
	<div class="lumina-hub-shell">
		<header class="lumina-hub-topbar">
			<a class="lumina-hub-brand" href="/manage">
				<span class="lumina-hub-mark" aria-hidden="true">
					<svg viewBox="0 0 24 24" role="img" aria-hidden="true">
						<path d="M12 7.2a4.8 4.8 0 1 0 0 9.6 4.8 4.8 0 0 0 0-9.6Zm0-2.4c3.97 0 7.2 3.23 7.2 7.2S15.97 19.2 12 19.2 4.8 15.97 4.8 12 8.03 4.8 12 4.8Zm8.4 0h-1.8a1.2 1.2 0 0 0-1.2 1.2V7.2a1.2 1.2 0 0 0 1.2 1.2H20.4A1.2 1.2 0 0 0 21.6 7.2V6A1.2 1.2 0 0 0 20.4 4.8ZM4.8 6A1.2 1.2 0 0 0 3.6 7.2v1.2A1.2 1.2 0 0 0 4.8 9.6h1.8a1.2 1.2 0 0 0 1.2-1.2V7.2A1.2 1.2 0 0 0 6.6 6H4.8Z"/>
					</svg>
				</span>
				<span class="lumina-hub-brand__text">
					<strong>Lumina</strong>
					<span>Agency Hub</span>
				</span>
			</a>
			<span class="lumina-hub-pill">Instagram Feed Control</span>
		</header>

		<main class="lumina-hub-main">
			<?php if ( ! empty( $page_heading ) ) : ?>
				<div class="lumina-hub-hero<?php echo ! empty( $narrow ) ? ' lumina-hub-hero--narrow' : ''; ?>">
					<h1><?php echo htmlspecialchars( $page_heading, ENT_QUOTES, 'UTF-8' ); ?></h1>
					<?php if ( ! empty( $page_intro ) ) : ?>
						<p><?php echo htmlspecialchars( $page_intro, ENT_QUOTES, 'UTF-8' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</main>

		<footer class="lumina-hub-footer">
			Lumina Agency Hub · Centralized Instagram licensing and feed delivery
		</footer>
	</div>
</body>
</html>
