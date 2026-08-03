<?php

namespace Twikura\Translate\Tests;

use PHPUnit\Framework\TestCase;
use Twikura\Translate\LangMapConfig;

class LangMapConfigTest extends TestCase
{
    public function testParseHandlesNormalLines(): void
    {
        $map = LangMapConfig::parse("zh:Simplified Chinese\nja:Japanese\nen:English");

        $this->assertSame([
            'zh' => 'Simplified Chinese',
            'ja' => 'Japanese',
            'en' => 'English',
        ], $map);
    }

    public function testParseSkipsEmptyLines(): void
    {
        $map = LangMapConfig::parse("zh:Chinese\n\n\nja:Japanese\n");

        $this->assertSame([
            'zh' => 'Chinese',
            'ja' => 'Japanese',
        ], $map);
    }

    public function testParseSkipsLinesWithoutColon(): void
    {
        $map = LangMapConfig::parse("zh:Chinese\nno colon here\nja:Japanese");

        $this->assertSame([
            'zh' => 'Chinese',
            'ja' => 'Japanese',
        ], $map);
    }

    public function testParseTrimsCodeAndNameWhitespace(): void
    {
        $map = LangMapConfig::parse("  zh :   Simplified Chinese  \n");

        $this->assertSame(['zh' => 'Simplified Chinese'], $map);
    }

    public function testParseSkipsEmptyCodeOrName(): void
    {
        $map = LangMapConfig::parse(":Chinese\nzh:\nja:Japanese");

        $this->assertSame(['ja' => 'Japanese'], $map);
    }

    public function testParseHandlesCrlfLineEndings(): void
    {
        $map = LangMapConfig::parse("zh:Chinese\r\nja:Japanese\r\n");

        $this->assertSame([
            'zh' => 'Chinese',
            'ja' => 'Japanese',
        ], $map);
    }

    public function testGetNameReturnsMappedName(): void
    {
        $this->assertSame('Simplified Chinese', LangMapConfig::getName('zh:Simplified Chinese', 'zh'));
    }

    public function testGetNameFallsBackToCodeWhenUnmatched(): void
    {
        $this->assertSame('fr', LangMapConfig::getName('zh:Chinese', 'fr'));
    }
}
