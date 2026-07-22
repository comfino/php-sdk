<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Frontend;

/**
 * Utility class for rendering HTML admin form fields without framework dependencies.
 *
 * All output is HTML-escaped using ENT_QUOTES | ENT_SUBSTITUTE / UTF-8.
 */
final class AdminFormFieldHelper
{
    /**
     * @param array<string, string> $attrs
     */
    public static function renderTextInput(string $name, string $value, string $label = '', array $attrs = []): string
    {
        $attrsHtml = self::buildAttrs(array_merge(['type' => 'text', 'name' => $name, 'value' => $value], $attrs));
        $inputHtml = "<input $attrsHtml />";

        return $label !== '' ? "<label>" . self::escape($label) . " $inputHtml</label>" : $inputHtml;
    }

    /**
     * @param array<string, string> $attrs
     */
    public static function renderTextarea(string $name, string $value, string $label = '', array $attrs = []): string
    {
        $attrsHtml = self::buildAttrs(array_merge(['name' => $name], $attrs));
        $textareaHtml = "<textarea $attrsHtml>" . self::escape($value) . "</textarea>";

        return $label !== '' ? "<label>" . self::escape($label) . " $textareaHtml</label>" : $textareaHtml;
    }

    /**
     * @param array<string, string> $attrs
     */
    public static function renderCheckbox(string $name, bool $checked, string $label = '', array $attrs = []): string
    {
        $baseAttrs = ['type' => 'checkbox', 'name' => $name];

        if ($checked) {
            $baseAttrs['checked'] = 'checked';
        }

        $attrsHtml = self::buildAttrs(array_merge($baseAttrs, $attrs));
        $inputHtml = "<input $attrsHtml />";

        return $label !== '' ? "<label>$inputHtml " . self::escape($label) . "</label>" : $inputHtml;
    }

    /**
     * @param array<string, string> $options [value => label]
     * @param string|string[] $selected
     * @param array<string, string> $attrs
     */
    public static function renderSelect(
        string $name,
        array $options,
        string|array $selected,
        string $label = '',
        array $attrs = []
    ): string {
        $selectedValues = is_array($selected) ? $selected : [$selected];
        $optionsHtml = '';

        foreach ($options as $value => $optLabel) {
            $selectedAttr = in_array((string) $value, $selectedValues, true) ? ' selected="selected"' : '';
            $optionsHtml .= '<option value="' . self::escape((string) $value) . '"' . $selectedAttr . '>' .
                self::escape($optLabel) . '</option>';
        }

        $attrsHtml = self::buildAttrs(array_merge(['name' => $name], $attrs));
        $selectHtml = "<select $attrsHtml>$optionsHtml</select>";

        return $label !== '' ? "<label>" . self::escape($label) . " $selectHtml</label>" : $selectHtml;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, string> $attrs */
    private static function buildAttrs(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $value) {
            $parts[] = self::escape($key) . '="' . self::escape($value) . '"';
        }

        return implode(' ', $parts);
    }
}
