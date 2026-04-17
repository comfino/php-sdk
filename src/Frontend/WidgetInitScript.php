<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino
 * @author Artur Kozubski <akozubski@comperia.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

return
"const script = document.createElement('script');
script.onload = function () {
    ComfinoWidgetFrontend.init({
        widgetKey: {WIDGET_KEY},
        priceSelector: {WIDGET_PRICE_SELECTOR},
        widgetTargetSelector: {WIDGET_TARGET_SELECTOR},
        priceObserverSelector: {WIDGET_PRICE_OBSERVER_SELECTOR},
        priceObserverLevel: {WIDGET_PRICE_OBSERVER_LEVEL},
        type: {WIDGET_TYPE},
        offerTypes: {OFFER_TYPES},
        embedMethod: {EMBED_METHOD},
        numOfInstallments: 0,
        price: null,
        productId: {PRODUCT_ID},
        productPrice: {PRODUCT_PRICE},
        platform: {PLATFORM},
        platformName: {PLATFORM_NAME},
        platformVersion: {PLATFORM_VERSION},
        platformDomain: {PLATFORM_DOMAIN},
        pluginVersion: {PLUGIN_VERSION},
        availableProductTypes: {AVAILABLE_PRODUCT_TYPES},
        productCartDetails: {PRODUCT_CART_DETAILS},
        language: {LANGUAGE},
        currency: {CURRENCY},
        showProviderLogos: {SHOW_PROVIDER_LOGOS},
        customBannerCss: {CUSTOM_BANNER_CSS_URL},
        customCalculatorCss: {CUSTOM_CALCULATOR_CSS_URL},
        callbackBefore: function () {},
        callbackAfter: function () {},
        onOfferRendered: function (jsonResponse, widgetTarget, widgetNode) { },
        onWidgetBannerLoaded: function (loadedOffers) { },
        onWidgetCalculatorLoaded: function (loadedOffers) { },
        onWidgetCalculatorUpdated: function (activeOffer) { },
        onWidgetBannerCustomCssLoaded: function (cssUrl) { },
        onWidgetCalculatorCustomCssLoaded: function (cssUrl) { },
        onGetPriceElement: function (priceSelector, priceObserverSelector) { return null; },
        debugMode: window.location.hash && window.location.hash.substring(1) === 'comfino_debug'
    });
};
script.src = {WIDGET_SCRIPT_URL};
script.async = true;
document.getElementsByTagName('head')[0].appendChild(script);";
