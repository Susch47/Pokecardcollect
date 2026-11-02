<?php
/**
 * Admin-Einstellungen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCR_Settings {

	const OPTION = 'pcr_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_pcr_scan_now', array( __CLASS__, 'scan_now' ) );
	}

	public static function defaults() {
		return array(
			'sets'               => array_keys( pcr_set_definitions() ),
			'interval_minutes'   => 15,
			'eur_chf'            => 0.94,
			'stale_hours'        => 6,
			'keep_days'          => 21,
			'targeted_per_cycle' => 4,
			'ka_enabled'         => 1,
			'ka_pages'           => 2,
			'ka_delay_min'       => 4,
			'ka_delay_max'       => 9,
			'tutti_enabled'      => 1,
			'tutti_delay'        => 2,
			'time_budget'        => 25,
			'telegram_token'     => '',
			'extra_queries'      => '',
		);
	}

	public static function get( $key = null ) {
		$o = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		return null === $key ? $o : ( $o[ $key ] ?? null );
	}

	/** @return array<string,PCR_Set> nur aktivierte Sets */
	public static function sets() {
		$out = array();
		foreach ( (array) self::get( 'sets' ) as $key ) {
			$s = PCR_Engine::enriched_set( $key );
			if ( $s ) {
				$out[ $key ] = $s;
			}
		}
		return $out;
	}

	/** Breite Suchbegriffe eines Sets inkl. Zusatz-Suchen aus den Einstellungen */
	public static function queries( PCR_Set $set ) {
		$q = $set->def['queries'];
		foreach ( preg_split( '/\R/', (string) self::get( 'extra_queries' ) ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( 2 === count( $p ) && $p[0] === $set->key && '' !== $p[1] ) {
				$q[] = $p[1];
			}
		}
		return array_values( array_unique( $q ) );
	}

	public static function menu() {
		add_options_page( 'PokéCard Radar', 'PokéCard Radar', 'manage_options', 'pokecard-radar', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting(
			'pcr',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in   = (array) $in;
		$d    = self::defaults();
		$defs = pcr_set_definitions();
		$num  = static function ( $k, $min, $max ) use ( $in, $d ) {
			$v = isset( $in[ $k ] ) ? (float) str_replace( ',', '.', $in[ $k ] ) : $d[ $k ];
			return max( $min, min( $max, $v ) );
		};
		return array(
			'sets'               => array_values( array_intersect( array_keys( $defs ), (array) ( $in['sets'] ?? array() ) ) ),
			'interval_minutes'   => (int) $num( 'interval_minutes', 10, 1440 ),
			'eur_chf'            => round( $num( 'eur_chf', 0.01, 10 ), 4 ),
			'stale_hours'        => (int) $num( 'stale_hours', 1, 168 ),
			'keep_days'          => (int) $num( 'keep_days', 1, 90 ),
			'targeted_per_cycle' => (int) $num( 'targeted_per_cycle', 0, 20 ),
			'ka_enabled'         => empty( $in['ka_enabled'] ) ? 0 : 1,
			'ka_pages'           => (int) $num( 'ka_pages', 1, 5 ),
			'ka_delay_min'       => $num( 'ka_delay_min', 2, 60 ),
			'ka_delay_max'       => $num( 'ka_delay_max', 2, 120 ),
			'tutti_enabled'      => empty( $in['tutti_enabled'] ) ? 0 : 1,
			'tutti_delay'        => $num( 'tutti_delay', 1, 60 ),
			'time_budget'        => (int) $num( 'time_budget', 10, 240 ),
			'telegram_token'     => sanitize_text_field( $in['telegram_token'] ?? '' ),
			'extra_queries'      => sanitize_textarea_field( $in['extra_queries'] ?? '' ),
		);
	}

	public static function scan_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.' );
		}
		check_admin_referer( 'pcr_scan_now' );
		PCR_Engine::request_scan();
		wp_safe_redirect( admin_url( 'options-general.php?page=pokecard-radar&scan=1' ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o  = self::get();
		$n  = self::OPTION;
		$rt = PCR_Engine::runtime();
		$f  = static function ( $key, $label, $desc = '', $type = 'number', $step = '1' ) use ( $o, $n ) {
			printf(
				'<tr><th scope="row"><label for="pcr_%1$s">%2$s</label></th><td><input type="%3$s" step="%4$s" id="pcr_%1$s" name="%5$s[%1$s]" value="%6$s" class="%7$s">%8$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				esc_attr( $step ),
				esc_attr( $n ),
				esc_attr( $o[ $key ] ),
				'text' === $type ? 'regular-text' : 'small-text',
				$desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : ''
			);
		};
		$cb = static function ( $key, $label ) use ( $o, $n ) {
			printf(
				'<label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label>',
				esc_attr( $n ),
				esc_attr( $key ),
				checked( ! empty( $o[ $key ] ), true, false ),
				esc_html( $label )
			);
		};
		?>
		<div class="wrap">
			<h1>PokéCard Radar</h1>
			<?php if ( isset( $_GET['scan'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p>Scan angestossen. Er läuft beim nächsten Cron-Aufruf.</p></div>
			<?php endif; ?>
			<p>Seite mit dem Shortcode <code>[pokecard_radar]</code> anlegen. Optional mit Standard-Set: <code>[pokecard_radar set="base1"]</code>.</p>

			<div class="notice notice-warning inline"><p>
				<strong>Hinweis:</strong> Kleinanzeigen und tutti verbieten das automatische Auslesen in ihren Nutzungsbedingungen.
				Intervall nicht zu klein wählen. Bei einer Sperre (Status 403/429) pausiert die Quelle bis zum nächsten Zyklus.
				Hosting-IPs werden schneller gesperrt als private Anschlüsse.
			</p></div>

			<h2>Status</h2>
			<p>
				Letzter Scan: <strong><?php echo esc_html( $rt['last_scan'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $rt['last_scan'] ), 'd.m.Y H:i' ) : '–' ); ?></strong> ·
				Jobs in Warteschlange: <strong><?php echo (int) count( $rt['queue'] ); ?></strong> ·
				Zyklus: <strong><?php echo (int) $rt['cycle']; ?></strong>
			</p>
			<?php foreach ( $rt['status'] as $src => $st ) : ?>
				<p><?php echo esc_html( $src ); ?>: <?php echo null === $st['ok'] ? '–' : ( $st['ok'] ? '✅ ok' : '❌ ' . esc_html( $st['error'] ) ); ?></p>
			<?php endforeach; ?>
			<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<p>WP-Cron ist deaktiviert. Stelle sicher, dass ein Server-Cron <code>wp-cron.php</code> jede Minute aufruft.</p>
			<?php else : ?>
				<p class="description">Empfohlen: <code>define('DISABLE_WP_CRON', true);</code> in der wp-config.php setzen und per Server-Cron jede Minute <code><?php echo esc_html( site_url( 'wp-cron.php' ) ); ?></code> aufrufen. Sonst scannt der Radar nur, wenn jemand die Seite besucht.</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pcr_scan_now">
				<?php wp_nonce_field( 'pcr_scan_now' ); ?>
				<?php submit_button( 'Jetzt scannen', 'secondary', 'submit', false ); ?>
			</form>
			<details><summary>Protokoll</summary><pre><?php echo esc_html( implode( "\n", $rt['log'] ) ); ?></pre></details>

			<form method="post" action="options.php">
				<?php settings_fields( 'pcr' ); ?>
				<h2>Sets</h2>
				<p>
				<?php foreach ( pcr_set_definitions() as $key => $def ) : ?>
					<label style="margin-right:16px"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[sets][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $o['sets'], true ) ); ?>> <?php echo esc_html( $def['label'] ); ?></label>
				<?php endforeach; ?>
				</p>
				<table class="form-table" role="presentation">
					<?php
					$f( 'interval_minutes', 'Scan-Intervall (Minuten)', 'Abstand zwischen zwei kompletten Durchläufen über alle Sets. Mindestens 10.' );
					$f( 'targeted_per_cycle', 'Gezielte Suchen pro Set und Zyklus', 'Einzelkarten-Suchen (z. B. „glurak 4/102“) für Karten, die Nutzern fehlen, rotierend und seltene zuerst.' );
					$f( 'eur_chf', 'Kurs EUR → CHF', '', 'number', '0.01' );
					$f( 'stale_hours', '„Nicht mehr gesehen“ nach (Stunden)' );
					$f( 'keep_days', 'Treffer anzeigen für (Tage)' );
					$f( 'time_budget', 'Zeitbudget pro Cron-Lauf (Sekunden)', 'Muss unter dem PHP-<code>max_execution_time</code> deines Hostings liegen.' );
					?>
					<tr><th scope="row">Kleinanzeigen</th><td><?php $cb( 'ka_enabled', 'aktiv' ); ?></td></tr>
					<?php
					$f( 'ka_pages', 'Kleinanzeigen: Seiten pro Suche' );
					$f( 'ka_delay_min', 'Kleinanzeigen: Pause min. (s)', '', 'number', '0.5' );
					$f( 'ka_delay_max', 'Kleinanzeigen: Pause max. (s)', '', 'number', '0.5' );
					?>
					<tr><th scope="row">tutti</th><td><?php $cb( 'tutti_enabled', 'aktiv' ); ?></td></tr>
					<?php
					$f( 'tutti_delay', 'tutti: Pause (s)', '', 'number', '0.5' );
					$f( 'telegram_token', 'Telegram-Bot-Token', 'Optional. Bot bei @BotFather anlegen. Jeder Nutzer trägt im Radar seine eigene Chat-ID ein und bekommt Push-Nachrichten zu neuen Angeboten für seine fehlenden Karten.', 'text' );
					?>
					<tr>
						<th scope="row"><label for="pcr_extra">Zusätzliche Suchbegriffe</label></th>
						<td><textarea id="pcr_extra" rows="4" class="large-text code" name="<?php echo esc_attr( $n ); ?>[extra_queries]"><?php echo esc_textarea( $o['extra_queries'] ); ?></textarea>
						<p class="description">Eine Zeile pro Suche: <code>set|suchbegriff</code>, z. B. <code>base1|glurak holo 1999</code>. Set-Schlüssel: <?php echo esc_html( implode( ', ', array_keys( pcr_set_definitions() ) ) ); ?>.</p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
