<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TextDiff;
use PHPUnit\Framework\TestCase;

final class TextDiffTest extends TestCase
{
    public function testIdenticalTextHasNoChanges(): void
    {
        $ops = TextDiff::words('same text here', 'same text here');
        $this->assertFalse(TextDiff::stats($ops)['changed']);
    }

    public function testWordChangeDetected(): void
    {
        $ops = TextDiff::words('the quick brown fox', 'the quick red fox');
        $text = implode('|', array_map(fn($o) => $o['op'] . ':' . trim($o['text']), $ops));
        $this->assertStringContainsString('del:brown', $text);
        $this->assertStringContainsString('add:red', $text);
        $this->assertSame(['added_words' => 1, 'removed_words' => 1, 'changed' => true], TextDiff::stats($ops));
    }

    public function testLineDiff(): void
    {
        $ops = TextDiff::lines("a\nb\nc", "a\nc\nd");
        $this->assertTrue(TextDiff::stats($ops)['changed']);
        $rebuilt = implode('', array_map(fn($o) => $o['op'] === 'del' ? '' : $o['text'], $ops));
        $this->assertSame("a\nc\nd", $rebuilt);
    }
}
