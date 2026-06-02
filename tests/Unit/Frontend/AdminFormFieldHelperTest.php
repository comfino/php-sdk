<?php

/**
 * Comfino PHP SDK
 *
 * Backend routines for e-commerce platforms integration with the Comfino payment gateway API.
 *
 * @package Comfino\Tests\Unit\Frontend
 * @author Artur Kozubski <a.kozubski@artkosoft.pl>
 * @copyright Copyright (c) 2026 Comfino by Comperia.pl S.A.
 * @license https://opensource.org/licenses/BSD-3-Clause BSD 3-Clause License
 * @link https://github.com/comfino/php-sdk
 */

declare(strict_types=1);

namespace Comfino\Tests\Unit\Frontend;

use Comfino\Frontend\AdminFormFieldHelper;
use PHPUnit\Framework\TestCase;

final class AdminFormFieldHelperTest extends TestCase
{
    /* ------------------------------------------------------------------ */
    /*  renderTextInput                                                     */
    /* ------------------------------------------------------------------ */

    public function testRenderTextInputProducesInputTag(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('my_field', 'hello');

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="my_field"', $html);
        $this->assertStringContainsString('value="hello"', $html);
    }

    public function testRenderTextInputWrapsInLabelWhenLabelGiven(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('f', 'v', 'My Label');

        $this->assertStringContainsString('<label>', $html);
        $this->assertStringContainsString('My Label', $html);
    }

    public function testRenderTextInputNoLabelWhenLabelEmpty(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('f', 'v');

        $this->assertStringNotContainsString('<label>', $html);
    }

    public function testRenderTextInputEscapesValueXss(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('f', '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderTextInputEscapesLabelXss(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('f', 'v', '<b>Label</b>');

        $this->assertStringContainsString('&lt;b&gt;Label&lt;/b&gt;', $html);
    }

    public function testRenderTextInputMergesExtraAttrs(): void
    {
        $html = AdminFormFieldHelper::renderTextInput('f', 'v', '', ['id' => 'my-id', 'class' => 'form-control']);

        $this->assertStringContainsString('id="my-id"', $html);
        $this->assertStringContainsString('class="form-control"', $html);
    }

    /* ------------------------------------------------------------------ */
    /*  renderTextarea                                                      */
    /* ------------------------------------------------------------------ */

    public function testRenderTextareaProducesTextareaTag(): void
    {
        $html = AdminFormFieldHelper::renderTextarea('ta', 'content');

        $this->assertStringContainsString('<textarea', $html);
        $this->assertStringContainsString('name="ta"', $html);
        $this->assertStringContainsString('content', $html);
    }

    public function testRenderTextareaWrapsInLabelWhenLabelGiven(): void
    {
        $html = AdminFormFieldHelper::renderTextarea('ta', 'v', 'Description');

        $this->assertStringContainsString('<label>', $html);
        $this->assertStringContainsString('Description', $html);
    }

    public function testRenderTextareaEscapesValueXss(): void
    {
        $html = AdminFormFieldHelper::renderTextarea('ta', '<script>evil()</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /* ------------------------------------------------------------------ */
    /*  renderCheckbox                                                      */
    /* ------------------------------------------------------------------ */

    public function testRenderCheckboxCheckedAttributeWhenTrue(): void
    {
        $html = AdminFormFieldHelper::renderCheckbox('cb', true);

        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testRenderCheckboxNoCheckedAttributeWhenFalse(): void
    {
        $html = AdminFormFieldHelper::renderCheckbox('cb', false);

        $this->assertStringNotContainsString('checked', $html);
    }

    public function testRenderCheckboxWrapsInLabelWhenLabelGiven(): void
    {
        $html = AdminFormFieldHelper::renderCheckbox('cb', false, 'Enable feature');

        $this->assertStringContainsString('<label>', $html);
        $this->assertStringContainsString('Enable feature', $html);
    }

    public function testRenderCheckboxEscapesLabel(): void
    {
        $html = AdminFormFieldHelper::renderCheckbox('cb', false, '<b>Bold</b>');

        $this->assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }

    /* ------------------------------------------------------------------ */
    /*  renderSelect                                                        */
    /* ------------------------------------------------------------------ */

    public function testRenderSelectProducesSelectTag(): void
    {
        $html = AdminFormFieldHelper::renderSelect('size', ['s' => 'Small', 'l' => 'Large'], 's');

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('name="size"', $html);
        $this->assertStringContainsString('<option', $html);
    }

    public function testRenderSelectMarksSelectedOption(): void
    {
        $html = AdminFormFieldHelper::renderSelect('color', ['r' => 'Red', 'b' => 'Blue'], 'b');

        $this->assertStringContainsString('value="b" selected="selected"', $html);
        $this->assertStringNotContainsString('value="r" selected', $html);
    }

    public function testRenderSelectSupportsMultipleSelected(): void
    {
        $html = AdminFormFieldHelper::renderSelect('items', ['a' => 'A', 'b' => 'B', 'c' => 'C'], ['a', 'c']);

        $this->assertStringContainsString('value="a" selected="selected"', $html);
        $this->assertStringContainsString('value="c" selected="selected"', $html);
        $this->assertStringNotContainsString('value="b" selected', $html);
    }

    public function testRenderSelectWrapsInLabelWhenLabelGiven(): void
    {
        $html = AdminFormFieldHelper::renderSelect('s', ['a' => 'A'], 'a', 'Choose:');

        $this->assertStringContainsString('<label>', $html);
        $this->assertStringContainsString('Choose:', $html);
    }

    public function testRenderSelectEscapesOptionValues(): void
    {
        $html = AdminFormFieldHelper::renderSelect('s', ['<xss>' => 'Bad'], '<xss>');

        $this->assertStringNotContainsString('value="<xss>"', $html);
        $this->assertStringContainsString('&lt;xss&gt;', $html);
    }

    public function testRenderSelectEscapesOptionLabels(): void
    {
        $html = AdminFormFieldHelper::renderSelect('s', ['k' => '<script>evil()</script>'], 'k');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
