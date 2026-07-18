<?php

declare(strict_types=1);

namespace Rasuvaeff\MediaConverter\Tests\Internal;

use Rasuvaeff\MediaConverter\Internal\FilterGraph;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(FilterGraph::class)]
final class FilterGraphTest
{
    public function chainsNodesWithCommasPreservingOrder(): void
    {
        Assert::same(FilterGraph::chain(['scale=640:-2', 'crop=100:100:0:0', 'fps=30']), 'scale=640:-2,crop=100:100:0:0,fps=30');
    }

    public function chainOfASingleNodeIsThatNode(): void
    {
        Assert::same(FilterGraph::chain(['fps=30']), 'fps=30');
    }

    #[DataProvider('escapeProvider')]
    public function escapesFilterArgumentMetacharacters(string $raw, string $expected): void
    {
        Assert::same(FilterGraph::escapeArgument($raw), $expected);
    }

    public static function escapeProvider(): iterable
    {
        yield 'plain path' => ['/subs/track.srt', '/subs/track.srt'];
        // Double-escaped: the colon must survive a filter's own internal
        // colon-delimited option parsing, not just the filtergraph parser
        // (verified against a real ffmpeg build — see the method docblock).
        yield 'colon' => ['C:/subs.srt', 'C\\\\:/subs.srt'];
        yield 'single quote (best-effort only; callers must reject it)' => ["it's.srt", "it\\'s.srt"];
        yield 'backslash first' => ['a\\b', 'a\\\\b'];
        yield 'graph separators' => ['a,b[c];d', 'a\\,b\\[c\\]\\;d'];
    }

    #[DataProvider('drawtextProvider')]
    public function quotesDrawtextValues(string $raw, string $expected): void
    {
        Assert::same(FilterGraph::quoteDrawtextValue($raw), $expected);
    }

    public static function drawtextProvider(): iterable
    {
        yield 'plain text' => ['hello world', "'hello world'"];
        yield 'colon comma and brackets need no escaping' => ['a:b,c[d]', "'a:b,c[d]'"];
        yield 'percent needs no escaping (expansion=none)' => ['50%', "'50%'"];
        yield 'single quote is closed, escaped and reopened' => ["it's", "'it'\\''s'"];
        yield 'two single quotes' => ["'a'", "''\\''a'\\'''"];
    }
}
