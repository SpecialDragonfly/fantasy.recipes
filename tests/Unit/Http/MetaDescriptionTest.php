<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\MetaDescription;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionTest extends TestCase
{
    public function testShortTextIsKeptAndWhitespaceCollapsed(): void
    {
        $this->assertSame('A short tale.', MetaDescription::fromText("  A  short\n\ntale. "));
    }

    public function testHtmlTagsAreStripped(): void
    {
        $this->assertSame('Bold move.', MetaDescription::fromText('<p>Bold <b>move</b>.</p>'));
    }

    public function testLongTextIsCutAtAWordBoundaryWithEllipsis(): void
    {
        $text = str_repeat('dragon ', 40);
        $result = MetaDescription::fromText($text);

        $this->assertLessThanOrEqual(155, mb_strlen($result));
        $this->assertStringEndsWith('dragon…', $result);
    }

    public function testEmptyInputGivesEmptyString(): void
    {
        $this->assertSame('', MetaDescription::fromText('   '));
    }
}
