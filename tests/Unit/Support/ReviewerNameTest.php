<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use Brain\Monkey\Functions;
use RomCommerce\Support\ReviewerName;
use RomCommerce\Tests\TestCase;

final class ReviewerNameTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
	}

	public function test_keeps_the_first_name_and_an_initial_for_every_other_part(): void {
		self::assertSame( 'Ion P.', ReviewerName::shorten( 'Ion Popescu' ) );
		self::assertSame( 'Maria E. I.', ReviewerName::shorten( 'Maria Elena Ionescu' ) );
	}

	public function test_handles_romanian_diacritics_and_lowercase_input(): void {
		self::assertSame( 'Ana M. Ș.', ReviewerName::shorten( 'Ana Maria Ștefănescu' ) );
		self::assertSame( 'ștefan Ț.', ReviewerName::shorten( 'ștefan țurcanu' ) );
		self::assertSame( 'Ion Î.', ReviewerName::shorten( 'Ion Îonescu' ) );
	}

	public function test_keeps_a_letter_written_with_a_combining_mark_whole(): void {
		self::assertSame( "Ion S\u{0326}.", ReviewerName::shorten( "Ion S\u{0326}tefan" ) );
	}

	public function test_a_single_name_is_shown_as_is(): void {
		self::assertSame( 'Ion', ReviewerName::shorten( 'Ion' ) );
		self::assertSame( 'Ion', ReviewerName::shorten( '  Ion  ' ) );
	}

	public function test_collapses_extra_and_non_breaking_whitespace(): void {
		self::assertSame( 'Ana M. Ș.', ReviewerName::shorten( "  Ana \t Maria   Ștefănescu \n" ) );
		self::assertSame( 'Ion P.', ReviewerName::shorten( "Ion\u{00A0}Popescu" ) );
	}

	public function test_a_hyphenated_first_name_stays_whole_and_a_hyphenated_surname_gets_one_initial(): void {
		self::assertSame( 'Ana-Maria P.', ReviewerName::shorten( 'Ana-Maria Pop' ) );
		self::assertSame( 'Ion P.', ReviewerName::shorten( 'Ion Popescu-Tăriceanu' ) );
	}

	public function test_surname_first_names_keep_the_first_word(): void {
		self::assertSame( 'Popescu I.', ReviewerName::shorten( 'Popescu Ion' ) );
		self::assertSame( 'Popescu I.', ReviewerName::shorten( 'Popescu, Ion' ) );
	}

	public function test_punctuation_only_words_are_dropped(): void {
		self::assertSame( 'Ion P.', ReviewerName::shorten( '- Ion - Popescu' ) );
	}

	public function test_nothing_is_returned_for_an_empty_name_or_an_email(): void {
		self::assertSame( '', ReviewerName::shorten( '   ' ) );
		self::assertSame( '', ReviewerName::shorten( 'ion.popescu@example.com' ) );
		self::assertSame( '', ReviewerName::shorten( 'Ion Popescu ion@example.com' ) );
	}

	public function test_shortening_twice_changes_nothing(): void {
		foreach ( array( 'Ion Popescu', 'Maria Elena Ionescu', 'Ana-Maria Pop', 'Ion' ) as $name ) {
			$once = ReviewerName::shorten( $name );
			self::assertSame( $once, ReviewerName::shorten( $once ) );
		}
	}

	public function test_for_display_never_shows_an_email_and_falls_back_to_a_neutral_label(): void {
		self::assertSame( 'Ion P.', ReviewerName::for_display( 'Ion Popescu' ) );
		self::assertSame( 'Anonymous', ReviewerName::for_display( 'ion@example.com' ) );
		self::assertSame( 'Anonymous', ReviewerName::for_display( '' ) );
		self::assertSame( 'Ion Popescu', ReviewerName::for_display( "Ion   Popescu\n", false ) );
		self::assertSame( 'Anonymous', ReviewerName::for_display( 'ion@example.com', false ) );
		self::assertSame( 'Anonymous', ReviewerName::for_display( ' - ', false ) );
	}
}
