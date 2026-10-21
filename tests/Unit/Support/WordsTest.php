<?php

declare(strict_types=1);

namespace RomCommerce\Tests\Unit\Support;

use RomCommerce\Support\Words;
use RomCommerce\Tests\TestCase;

final class WordsTest extends TestCase {

	public function test_text_within_the_limit_is_kept_whole(): void {
		self::assertSame( 'Produs foarte bun', Words::limit( 'Produs foarte bun', 3 ) );
		self::assertSame( 'Produs foarte bun', Words::limit( 'Produs foarte bun', 10 ) );
	}

	public function test_zero_means_no_limit(): void {
		self::assertSame( 'unu doi trei patru', Words::limit( 'unu doi trei patru', 0 ) );
	}

	public function test_cuts_after_whole_words_and_adds_an_ellipsis(): void {
		self::assertSame( "Produs foarte\u{2026}", Words::limit( 'Produs foarte bun, recomand', 2 ) );
	}

	public function test_never_cuts_inside_a_word(): void {
		$text = 'Ștefănescu a primit îmbrăcămintea într-o cutie impecabilă';

		for ( $max = 1; $max <= 6; $max++ ) {
			$kept = rtrim( Words::limit( $text, $max ), "\u{2026}" );
			foreach ( explode( ' ', $kept ) as $word ) {
				self::assertStringContainsString( $word, $text );
				self::assertContains( $word, explode( ' ', $text ) );
			}
		}
	}

	public function test_drops_punctuation_left_dangling_before_the_ellipsis(): void {
		self::assertSame( "Excelent\u{2026}", Words::limit( 'Excelent, recomand.', 1 ) );
		self::assertSame( "Bun\u{2026}", Words::limit( 'Bun. Foarte bun.', 1 ) );
		self::assertSame( "Calitate \u{2014} preț\u{2026}", Words::limit( 'Calitate — preț — livrare', 3 ) );
	}

	public function test_collapses_whitespace(): void {
		self::assertSame( 'unu doi trei', Words::limit( "  unu\n\ndoi \t trei ", 0 ) );
		self::assertSame( "unu doi\u{2026}", Words::limit( "unu\u{00A0}doi trei", 2 ) );
	}

	public function test_empty_text_stays_empty(): void {
		self::assertSame( '', Words::limit( '   ', 5 ) );
	}
}
