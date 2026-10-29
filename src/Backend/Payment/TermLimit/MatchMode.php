<?php

/**
 * ComfinoPay PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the ComfinoPay payment gateway API.
 *
 * @package Comfino\Backend\Payment\TermLimit
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 by ComfinoPay sp. z o.o.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Backend\Payment\TermLimit;

/**
 * How a {@see TermLimitRuleSet} interprets its rules.
 */
enum MatchMode: string
{
    /**
     * Opt-in: a rule grants the product type to the products it matches (within its term constraint). A ruled type
     * is allowed only when every cart product matches at least one of its rules; several matching rules on one
     * product are united (the most permissive wins for that product).
     */
    case ALLOW_LIST = 'allow_list';

    /**
     * Block list: a rule restricts the products it matches. A rule without a term constraint blocks the type; a rule
     * with one limits the matched products to it, and several matching rules on one product are intersected.
     * Unmatched products are not restricted.
     */
    case BLOCK_LIST = 'block_list';
}
