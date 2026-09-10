<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package ComfinoPay
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

return
"import({WIDGET_SCRIPT_URL}).then(function (sdkModule) {
    const sdk = sdkModule.ComfinoSDK.getInstance();
    const shopEnvironment = {SHOP_ENVIRONMENT};

    sdk.init({
        environment: {ENVIRONMENT},
        platform: shopEnvironment.platform || 'generic',
        widgetKey: {WIDGET_KEY},
        loggingToken: {LOGGING_TOKEN},
        trackId: {TRACK_ID}
    });

    sdk.bootstrapWidget({
        widgetTargetSelector: {WIDGET_TARGET_SELECTOR},
        priceSelector: {WIDGET_PRICE_SELECTOR},
        priceObserverSelector: {WIDGET_PRICE_OBSERVER_SELECTOR},
        priceObserverLevel: {WIDGET_PRICE_OBSERVER_LEVEL},
        embedMethod: {EMBED_METHOD},
        widgetType: {WIDGET_TYPE},
        price: {PRODUCT_PRICE},
        offerTypes: {OFFER_TYPES},
        language: {LANGUAGE},
        currency: {CURRENCY},
        showProviderLogos: {SHOW_PROVIDER_LOGOS},
        hasPriceInput: {HAS_PRICE_INPUT},
        shopEnvironment: shopEnvironment,
        availableProductTypes: {AVAILABLE_PRODUCT_TYPES},
        productId: {PRODUCT_ID},
        productCartDetails: {PRODUCT_CART_DETAILS},
        customBannerCss: {CUSTOM_BANNER_CSS_URL},
        customCalculatorCss: {CUSTOM_CALCULATOR_CSS_URL},
        onWidgetBannerLoaded: function (loadedOffers) { },
        onWidgetCalculatorLoaded: function (loadedOffers) { },
        onWidgetCalculatorUpdated: function (activeOffer) { },
        onWidgetBannerCustomCssLoaded: function (cssUrl) { },
        onWidgetCalculatorCustomCssLoaded: function (cssUrl) { },
        debugMode: window.location.hash && window.location.hash.substring(1) === 'comfino_debug'
    });
});";
