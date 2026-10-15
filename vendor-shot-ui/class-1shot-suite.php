<?php
/**
 * 1Shot Suite Bridge — Cross-plugin discovery, status & interoperability.
 *
 * Lightweight, zero-dependency helper class shared across all Byebis / 1Shot plugins:
 * - 1shotwp (Core MCP Server & Automation)
 * - 1shot-Visibility (AEO, GEO & Search Readiness)
 * - keeper-ai (Accessibility & EAA Compliance)
 * - nexus-news-ai (AI Journalism & News Wire)
 *
 * Provides safe detection, telemetry summaries, mutual navigation and MCP hooks.
 *
 * @package OneShot\Suite
 * @version 1.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'OneShot_Suite' ) ) {
	return;
}

final class OneShot_Suite {

	const VERSION = '1.0.0';

	/**
	 * Registry of suite plugins with metadata.
	 *
	 * @return array
	 */
	public static function get_plugins_meta() {
		return array(
			'1shotwp' => array(
				'slug'        => '1shotwp',
				'name'        => '1ShotWP',
				'tagline'     => __( 'Self-Hosted AI & MCP Server', '1shot-suite' ),
				'icon'        => '⚡',
				'main_file'   => '1shotwp/1shotwp.php',
				'version_c'   => 'ONESHOTWP_VERSION',
				'menu_page'   => 'oneshotwp',
				'color'       => '#2271b1',
			),
			'1shot-visibility' => array(
				'slug'        => '1shot-visibility',
				'name'        => '1Shot Visibility',
				'tagline'     => __( 'AI Visibility & GEO Toolkit', '1shot-suite' ),
				'icon'        => '👁️',
				'main_file'   => '1shot-visibility/1shot-visibility.php',
				'version_c'   => 'SHOTVIZ_VERSION',
				'menu_page'   => '1shot-visibility',
				'color'       => '#135e96',
			),
			'keeper-ai' => array(
				'slug'        => 'keeper-ai',
				'name'        => 'Keeper AI',
				'tagline'     => __( 'Accessibility & EAA Compliance', '1shot-suite' ),
				'icon'        => '🛡️',
				'main_file'   => 'keeper-ai/keeper-ai.php',
				'version_c'   => 'KEEPER_AI_VERSION',
				'menu_page'   => 'keeper-ai',
				'color'       => '#00a32a',
			),
			'nexus-news-ai' => array(
				'slug'        => 'nexus-news-ai',
				'name'        => 'Nexus News AI',
				'tagline'     => __( 'Autonomous AI Newsroom & Wire', '1shot-suite' ),
				'icon'        => '📰',
				'main_file'   => 'nexus-news-ai-wp/nexus-news-ai.php',
				'version_c'   => 'NEXUS_VERSION',
				'menu_page'   => 'nexus-news-ai',
				'color'       => '#d63638',
			),
		);
	}

	/**
	 * Check if a plugin from the suite is active.
	 *
	 * @param string $slug Plugin identifier.
	 * @return bool
	 */
	public static function is_active( $slug ) {
		$meta = self::get_plugins_meta();
		if ( ! isset( $meta[ $slug ] ) ) {
			return false;
		}

		$const = $meta[ $slug ]['version_c'];
		if ( defined( $const ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			$plugin_admin_file = ABSPATH . 'wp-admin/includes/plugin.php';
			if ( file_exists( $plugin_admin_file ) ) {
				require_once $plugin_admin_file;
			}
		}

		if ( function_exists( 'is_plugin_active' ) ) {
			return is_plugin_active( $meta[ $slug ]['main_file'] );
		}

		return false;
	}

	/**
	 * Get version of an active suite plugin.
	 *
	 * @param string $slug Plugin identifier.
	 * @return string|null
	 */
	public static function get_version( $slug ) {
		$meta = self::get_plugins_meta();
		if ( ! isset( $meta[ $slug ] ) ) {
			return null;
		}

		$const = $meta[ $slug ]['version_c'];
		if ( defined( $const ) ) {
			return constant( $const );
		}

		return null;
	}

	/**
	 * Get status summary for a suite plugin.
	 *
	 * @param string $slug Plugin identifier.
	 * @return array
	 */
	public static function get_status( $slug ) {
		$active  = self::is_active( $slug );
		$version = $active ? self::get_version( $slug ) : null;
		$meta    = self::get_plugins_meta()[ $slug ] ?? array();

		$summary = array(
			'slug'      => $slug,
			'name'      => $meta['name'] ?? $slug,
			'tagline'   => $meta['tagline'] ?? '',
			'icon'      => $meta['icon'] ?? '🔌',
			'active'    => $active,
			'version'   => $version,
			'admin_url' => $active ? admin_url( 'admin.php?page=' . $meta['menu_page'] ) : '',
			'metrics'   => array(),
		);

		// Contextual live metrics per plugin
		if ( $active ) {
			switch ( $slug ) {
				case '1shotwp':
					$summary['metrics'] = array(
						'label' => __( 'MCP Server', '1shot-suite' ),
						'value' => defined( 'ONESHOTWP_VERSION' ) ? 'Online (v' . ONESHOTWP_VERSION . ')' : 'Ready',
					);
					break;

				case '1shot-visibility':
					$report = get_option( 'shotviz_last_report', array() );
					$score  = isset( $report['score'] ) ? $report['score'] : null;
					$summary['metrics'] = array(
						'label' => __( 'AI Readiness', '1shot-suite' ),
						'value' => null !== $score ? $score . '/100' : __( 'Scan ready', '1shot-suite' ),
					);
					break;

				case 'keeper-ai':
					$last_score = get_option( 'keeper_ai_badge_score', null );
					$summary['metrics'] = array(
						'label' => __( 'EAA Health', '1shot-suite' ),
						'value' => null !== $last_score ? $last_score . '/100' : __( 'Protected', '1shot-suite' ),
					);
					break;

				case 'nexus-news-ai':
					$summary['metrics'] = array(
						'label' => __( 'Agents', '1shot-suite' ),
						'value' => '7 Journalists',
					);
					break;
			}
		}

		return $summary;
	}

	/**
	 * Render a unified Suite navigation / status strip.
	 *
	 * @param string $current_slug Slug of the host plugin.
	 */
	public static function render_suite_strip( $current_slug = '' ) {
		$plugins = self::get_plugins_meta();
		?>
		<div class="sui-suite-strip" style="background:#fff; border:1px solid var(--sui-border, #dcdcde); border-radius:var(--sui-radius, 10px); padding:12px 16px; margin:0 0 20px 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
			<div style="display:flex; align-items:center; gap:8px;">
				<span style="font-weight:700; font-size:13px; color:var(--sui-text, #1d2327); letter-spacing:-0.01em;">
					Byebis 1Shot Suite:
				</span>
			</div>
			<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
				<?php
				foreach ( $plugins as $slug => $meta ) :
					$status = self::get_status( $slug );
					$is_self = ( $slug === $current_slug );
					?>
					<div class="sui-suite-item" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; padding:4px 10px; border-radius:20px; background:<?php echo $is_self ? 'var(--sui-primary-soft, rgba(34,113,177,0.12))' : '#f6f7f7'; ?>; border:1px solid <?php echo $is_self ? 'var(--sui-primary, #2271b1)' : '#dcdcde'; ?>;">
						<span aria-hidden="true"><?php echo esc_html( $meta['icon'] ); ?></span>
						<?php if ( $status['active'] && ! $is_self ) : ?>
							<a href="<?php echo esc_url( $status['admin_url'] ); ?>" style="text-decoration:none; color:var(--sui-text, #1d2327); font-weight:600;">
								<?php echo esc_html( $meta['name'] ); ?>
							</a>
						<?php else : ?>
							<span style="font-weight:<?php echo $is_self ? '700' : '500'; ?>; color:<?php echo $is_self ? 'var(--sui-primary, #2271b1)' : '#50575e'; ?>;">
								<?php echo esc_html( $meta['name'] ); ?>
								<?php if ( $is_self ) : ?>
									<span style="font-size:10px; text-transform:uppercase; opacity:0.8;">(current)</span>
								<?php endif; ?>
							</span>
						<?php endif; ?>

						<?php if ( $status['active'] ) : ?>
							<span class="sui-badge sui-badge--ok" style="font-size:10px; padding:2px 6px;">
								<?php echo esc_html( ! empty( $status['metrics']['value'] ) ? $status['metrics']['value'] : 'v' . $status['version'] ); ?>
							</span>
						<?php else : ?>
							<span class="sui-badge sui-badge--neutral" style="font-size:10px; padding:2px 6px;">
								<?php esc_html_e( 'Inactive', '1shot-suite' ); ?>
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
