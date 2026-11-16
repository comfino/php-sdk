<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Shop\Product
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Shop\Product;

/**
 * Supplies the shop's category tree to SDK services that need it, such as the category-exclusion filter built by
 * {@see \Comfino\Backend\Settings\SettingsManager::getAllowedProductTypesFiltered()}.
 *
 * Building the tree is platform-specific (a {@see CategoryTree\BuildStrategyInterface} over the platform's category
 * storage), so the plugin implements this. It is called only when a category filter is configured, and may return the
 * same instance on every call.
 */
interface CategoryTreeProviderInterface
{
    public function getCategoryTree(): CategoryTree;
}
