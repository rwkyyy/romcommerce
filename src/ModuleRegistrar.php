<?php

declare(strict_types=1);

namespace RomCommerce;

use RomCommerce\Modules\AdminBranding\Module as AdminBrandingModule;
use RomCommerce\Modules\ClearCart\Module as ClearCartModule;
use RomCommerce\Modules\CouponCleanup\Module as CouponCleanupModule;
use RomCommerce\Modules\CountiesPostcodes\Module as CountiesPostcodesModule;
use RomCommerce\Modules\FooterCredit\Module as FooterCreditModule;
use RomCommerce\Modules\FreeShippingBar\Module as FreeShippingBarModule;
use RomCommerce\Modules\FrequentlyBoughtTogether\Module as FrequentlyBoughtTogetherModule;
use RomCommerce\Modules\HideVoucher\Module as HideVoucherModule;
use RomCommerce\Modules\LegalGuaranteeNotice\Module as LegalGuaranteeNoticeModule;
use RomCommerce\Modules\Maof\Module as MaofModule;
use RomCommerce\Modules\MerchantWidget\Module as MerchantWidgetModule;
use RomCommerce\Modules\MultiAddress\Module as MultiAddressModule;
use RomCommerce\Modules\OrderPaymentFilter\Module as OrderPaymentFilterModule;
use RomCommerce\Modules\AvailabilitySorting\Module as AvailabilitySortingModule;
use RomCommerce\Modules\PerksShop\Module as PerksShopModule;
use RomCommerce\Modules\PfPjBilling\Module as PfPjBillingModule;
use RomCommerce\Modules\PhoneValidation\Module as PhoneValidationModule;
use RomCommerce\Modules\PriceHistory\Module as PriceHistoryModule;
use RomCommerce\Modules\RecommendedPrice\Module as RecommendedPriceModule;
use RomCommerce\Modules\RelatedProducts\Module as RelatedProductsModule;
use RomCommerce\Modules\ReviewerNames\Module as ReviewerNamesModule;
use RomCommerce\Modules\ReviewPage\Module as ReviewPageModule;
use RomCommerce\Modules\ReviewsShowcase\Module as ReviewsShowcaseModule;
use RomCommerce\Modules\SaleCategorySync\Module as SaleCategorySyncModule;
use RomCommerce\Modules\SalPictogram\Module as SalPictogramModule;
use RomCommerce\Modules\StoreReport\Module as StoreReportModule;
use RomCommerce\Modules\VatId\Module as VatIdModule;
use RomCommerce\Modules\VatRates\Module as VatRatesModule;
use RomCommerce\Modules\WhatsappFab\Module as WhatsappFabModule;

defined( 'ABSPATH' ) || exit;

/**
 * Registers RomCommerce Lite's own built-in modules on the same
 * romcommerce/register_modules action Pro uses, so Plugin::boot() itself
 * stays free of any hardcoded module list.
 */
final class ModuleRegistrar {

	public function register(): void {
		add_action( 'romcommerce/register_modules', array( $this, 'register_modules' ) );
	}

	public function register_modules( ModuleLoader $modules ): void {
		$modules->register( new SalPictogramModule() );
		$modules->register( new LegalGuaranteeNoticeModule() );
		$modules->register( new PriceHistoryModule() );
		$modules->register( new PfPjBillingModule() );
		$modules->register( new VatIdModule() );
		$modules->register( new MaofModule() );
		$modules->register( new ReviewerNamesModule() );
		$modules->register( new HideVoucherModule() );
		$modules->register( new CountiesPostcodesModule() );
		$modules->register( new PhoneValidationModule() );
		$modules->register( new VatRatesModule() );
		$modules->register( new MultiAddressModule() );
		$modules->register( new StoreReportModule() );
		$modules->register( new OrderPaymentFilterModule() );
		$modules->register( new FreeShippingBarModule() );
		$modules->register( new SaleCategorySyncModule() );
		$modules->register( new RecommendedPriceModule() );
		$modules->register( new RelatedProductsModule() );
		$modules->register( new FrequentlyBoughtTogetherModule() );
		$modules->register( new AvailabilitySortingModule() );
		$modules->register( new ReviewPageModule() );
		$modules->register( new ReviewsShowcaseModule() );
		$modules->register( new WhatsappFabModule() );
		$modules->register( new ClearCartModule() );
		$modules->register( new MerchantWidgetModule() );
		$modules->register( new PerksShopModule() );
		$modules->register( new CouponCleanupModule() );
		$modules->register( new AdminBrandingModule() );
		$modules->register( new FooterCreditModule() );
	}
}
