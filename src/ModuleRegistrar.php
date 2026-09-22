<?php

declare(strict_types=1);

namespace RomCommerce;

use RomCommerce\Modules\ClearCart\Module as ClearCartModule;
use RomCommerce\Modules\CountiesPostcodes\Module as CountiesPostcodesModule;
use RomCommerce\Modules\FreeShippingBar\Module as FreeShippingBarModule;
use RomCommerce\Modules\HideVoucher\Module as HideVoucherModule;
use RomCommerce\Modules\LegalGuaranteeNotice\Module as LegalGuaranteeNoticeModule;
use RomCommerce\Modules\Maof\Module as MaofModule;
use RomCommerce\Modules\MultiAddress\Module as MultiAddressModule;
use RomCommerce\Modules\PfPjBilling\Module as PfPjBillingModule;
use RomCommerce\Modules\PriceHistory\Module as PriceHistoryModule;
use RomCommerce\Modules\RelatedProducts\Module as RelatedProductsModule;
use RomCommerce\Modules\ReviewPage\Module as ReviewPageModule;
use RomCommerce\Modules\SaleCategorySync\Module as SaleCategorySyncModule;
use RomCommerce\Modules\SalPictogram\Module as SalPictogramModule;
use RomCommerce\Modules\VatId\Module as VatIdModule;
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
		$modules->register( new HideVoucherModule() );
		$modules->register( new CountiesPostcodesModule() );
		$modules->register( new MultiAddressModule() );
		$modules->register( new FreeShippingBarModule() );
		$modules->register( new SaleCategorySyncModule() );
		$modules->register( new RelatedProductsModule() );
		$modules->register( new ReviewPageModule() );
		$modules->register( new WhatsappFabModule() );
		$modules->register( new ClearCartModule() );
	}
}
