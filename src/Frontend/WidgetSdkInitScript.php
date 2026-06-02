<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

return
"const script = document.createElement('script');
script.onload = function () {
    const sdk = window.Comfino.ComfinoSDK.getInstance();
    const shopEnvironment = {SHOP_ENVIRONMENT};

    sdk.init({
        environment: {ENVIRONMENT},
        platform: shopEnvironment.platform || 'generic',
        widgetKey: {WIDGET_KEY}
    });

    sdk.createWidget({
        container: {WIDGET_TARGET_SELECTOR},
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
};
script.src = {WIDGET_SCRIPT_URL};
script.async = true;

document.getElementsByTagName('head')[0].appendChild(script);";
