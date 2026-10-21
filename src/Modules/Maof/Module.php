<?php

declare(strict_types=1);

namespace RomCommerce\Modules\Maof;

use RomCommerce\Admin\ProSection;
use RomCommerce\Admin\SettingsForm;
use RomCommerce\Modules\HasSettingsUi;
use RomCommerce\Modules\ModuleInterface;
use RomCommerce\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * MAOF (Multiple Active Orders Finder) — Lite finder half. Surfaces possible
 * related orders and active duplicates to authorised staff as decision support.
 * It never cancels, blocks, refuses or blacklists anything (GDPR Art. 22). The
 * risk layer (unclaimed tracking, refund reason codes, override workflow) is
 * Pro. docs/maof-gdpr-reference.md is the binding production gate.
 */
final class Module implements ModuleInterface, HasSettingsUi {

	private const ID = 'maof';

	private Admin $admin;

	public function __construct() {
		$this->admin = new Admin( new Finder() );
	}

	public function id(): string {
		return self::ID;
	}

	public function title(): string {
		return __( 'MAOF', 'romcommerce' );
	}

	public function is_enabled(): bool {
		return Settings::is_enabled( self::ID );
	}

	public function boot(): void {
		// Not admin-only: most orders are created at front-end checkout, and
		// the backfill runs from cron.
		( new PhoneIndex() )->register();

		if ( is_admin() ) {
			$this->admin->register();
		}
	}

	public function render_settings(): void {
		if ( SettingsForm::verify( self::ID ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above via SettingsForm::verify().
			Settings::set_enabled( self::ID, isset( $_POST['rc_enabled'] ) );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'romcommerce' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'Shows authorised staff possible related orders (by e-mail or phone, over a 12-month window) and warns about active duplicate orders. It is decision support only. It never cancels, blocks or refuses an order automatically, and no risk score is stored.', 'romcommerce' ) . '</p>';

		echo '<form method="post">';
		SettingsForm::nonce_field( self::ID );
		echo '<table class="form-table"><tbody><tr>';
		echo '<th scope="row">' . esc_html__( 'MAOF finder', 'romcommerce' ) . '</th>';
		echo '<td><label><input type="checkbox" name="rc_enabled" value="1"' . checked( $this->is_enabled(), true, false ) . '> ';
		echo esc_html__( 'Show the MAOF column and order review box', 'romcommerce' ) . '</label></td>';
		echo '</tr>';
		if ( $this->is_enabled() ) {
			$this->render_index_progress();
		}
		echo '</tbody></table>';
		submit_button( __( 'Save changes', 'romcommerce' ) );
		echo '</form>';

		echo '<hr>';
		echo '<h3>' . esc_html__( 'Privacy notice paragraph', 'romcommerce' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Add a paragraph like this to your checkout privacy information (GDPR Art. 13). Adjust the contact details and the period if you change them.', 'romcommerce' ) . '</p>';
		echo '<textarea readonly rows="8" class="large-text code">' . esc_textarea( $this->privacy_paragraph() ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The Legitimate Interests Assessment, the Article 30 record and the DPIA screening are merchant obligations.', 'romcommerce' ) . '</p>';

		ProSection::line( __( 'Compliance checklist', 'romcommerce' ), __( 'Lists the Legitimate Interests Assessment, the Article 30 record and the DPIA screening as attestations you can tick off.', 'romcommerce' ) );
	}

	/**
	 * Until the backfill finishes, older orders whose phone was typed with
	 * spaces or dots can still be missed, so staff need to see it isn't done.
	 */
	private function render_index_progress(): void {
		$progress = PhoneIndex::progress();

		echo '<tr><th scope="row">' . esc_html__( 'Phone number index', 'romcommerce' ) . '</th><td>';

		if ( $progress['complete'] ) {
			echo '<p>' . esc_html__( 'Complete. Orders from the last 12 months are matched by phone number however it was typed.', 'romcommerce' ) . '</p>';
			echo '</td></tr>';

			return;
		}

		if ( null === $progress['total'] ) {
			// No value attribute: the browser shows an indeterminate bar.
			echo '<progress class="rc-progress" aria-label="' . esc_attr__( 'Phone number index progress', 'romcommerce' ) . '"></progress>';
			echo '<p class="description">' . esc_html__( 'Starting. Orders from the last 12 months are indexed in the background.', 'romcommerce' ) . '</p>';
		} else {
			$percent = $progress['total'] > 0 ? (int) floor( 100 * $progress['done'] / $progress['total'] ) : 0;
			echo '<progress class="rc-progress" max="100" value="' . esc_attr( (string) $percent ) . '" aria-label="' . esc_attr__( 'Phone number index progress', 'romcommerce' ) . '"></progress>';
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: 1: orders indexed so far, 2: orders in the last 12 months, 3: percentage. */
					__( '%1$s of %2$s orders indexed (%3$s%%).', 'romcommerce' ),
					number_format_i18n( $progress['done'] ),
					number_format_i18n( $progress['total'] ),
					number_format_i18n( $percent )
				)
			) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'Until this finishes, older orders whose phone number was typed with spaces or dots may not show up as related. Indexing runs in batches whenever WordPress runs its scheduled tasks; reload this page to see progress.', 'romcommerce' ) . '</p>';
		echo '</td></tr>';
	}

	private function privacy_paragraph(): string {
		return __(
			'Pentru prevenirea erorilor de procesare și livrare, a comenzilor duplicate și, după caz, a utilizării abuzive a serviciului, comparăm adresa de e-mail și/sau numărul de telefon furnizate la plasarea comenzii cu datele comenzilor din ultimele 12 luni. Prelucrarea se întemeiază pe interesul nostru legitim de a asigura procesarea corectă și sigură a comenzilor și de a preveni pierderile operaționale, conform art. 6 alin. (1) lit. f) din RGPD. Rezultatul este un indicator intern destinat personalului autorizat și nu determină automat anularea sau refuzarea unei comenzi. În cazul identificării unor posibile comenzi asociate, situația este verificată manual. Puteți solicita accesul sau rectificarea datelor și vă puteți opune prelucrării, în condițiile art. 21 din RGPD, contactându-ne la [adresă de contact].',
			'romcommerce'
		);
	}
}
