<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Service\ActivityPub\ApObjectExtractor;
use App\Service\ActivityPub\MarkdownConverter;
use App\Service\ActivityPubManager;
use App\Service\TagExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ApObjectExtractorTest extends TestCase
{
    private ApObjectExtractor $extractor;
    private TagExtractor $tagExtractor;

    public function setUp(): void
    {
        $this->tagExtractor = new TagExtractor();

        $markdownConverter = $this->createStub(MarkdownConverter::class);
        // the HTML to markdown conversion is covered by MarkdownConverterTest,
        // here the content is passed through so the assertions are about the tags
        $markdownConverter->method('convert')->willReturnCallback(fn (string $value) => $value);

        $this->extractor = new ApObjectExtractor(
            $markdownConverter,
            $this->createStub(ActivityPubManager::class),
            $this->tagExtractor,
        );
    }

    #[DataProvider('provider')]
    public function testGetMarkdownBodyTags(array $object, ?array $expectedTags, string $name): void
    {
        $body = $this->extractor->getMarkdownBody($object);

        self::assertEquals($expectedTags, $this->tagExtractor->extract($body), message: "Tag test '$name'");
    }

    public static function provider(): array
    {
        $content = ['content' => 'Lorem ipsum'];

        return [
            [
                'object' => $content,
                'expectedTags' => null,
                'name' => 'No tag array at all',
            ],
            [
                'object' => $content + ['tag' => []],
                'expectedTags' => null,
                'name' => 'Empty tag array',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'href' => 'https://writefreely.tld/tag:foo', 'name' => '#foo'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'A single hashtag',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Mention', 'href' => 'https://mastodon.tld/users/alice', 'name' => '@alice'],
                ]],
                'expectedTags' => null,
                'name' => 'A mention is not a hashtag',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Mention', 'href' => 'https://mastodon.tld/users/alice', 'name' => '@alice'],
                    ['type' => 'Hashtag', 'href' => 'https://mastodon.tld/tags/foo', 'name' => '#foo'],
                    ['type' => 'Emoji', 'name' => ':blobcat:'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'Only the hashtag of a mixed tag array',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => 'foo'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'A name without a leading hash',
            ],
            [
                'object' => ['content' => 'Lorem #foo ipsum'] + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#Foo'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'A hashtag already inline in the body is not duplicated',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#foo'],
                    ['type' => 'Hashtag', 'name' => '#FOO'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'The same hashtag twice in the tag array',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#Zażółć'],
                ]],
                'expectedTags' => ['zazolc'],
                'name' => 'A hashtag is normalized the way an inline one is',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#a'],
                ]],
                'expectedTags' => null,
                'name' => 'A single character hashtag is out of scope of the local pattern',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag'],
                    ['type' => 'Hashtag', 'name' => 123],
                    ['type' => 'Hashtag', 'name' => '#'],
                    'a bare string',
                    null,
                ]],
                'expectedTags' => null,
                'name' => 'Malformed tag entries are survived',
            ],
            [
                'object' => $content + ['tag' => ['type' => 'Hashtag', 'name' => '#foo']],
                'expectedTags' => ['foo'],
                'name' => 'A single tag object rather than a list of them',
            ],
            [
                'object' => ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#foo'],
                ]],
                'expectedTags' => ['foo'],
                'name' => 'An object with no content and no source',
            ],
            [
                'object' => [
                    'content' => '<p>Lorem ipsum</p>',
                    'source' => ['mediaType' => 'text/markdown', 'content' => 'Lorem ipsum'],
                    'tag' => [['type' => 'Hashtag', 'name' => '#foo']],
                ],
                'expectedTags' => ['foo'],
                'name' => 'A markdown source body',
            ],
        ];
    }

    #[DataProvider('magazineProvider')]
    public function testGetMarkdownBodyDropsTheMagazineHashtag(array $object, string $magazineName, ?array $expectedTags, string $name): void
    {
        $body = $this->extractor->getMarkdownBody($object, $magazineName);

        self::assertEquals($expectedTags, $this->tagExtractor->extract($body), message: "Magazine test '$name'");
    }

    public static function magazineProvider(): array
    {
        $content = ['content' => 'Lorem ipsum'];

        return [
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#someMagazine'],
                ]],
                'magazineName' => 'someMagazine',
                'expectedTags' => null,
                'name' => 'The magazine hashtag every Mbin note carries is not a tag',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#SOMEMAGAZINE'],
                ]],
                'magazineName' => 'someMagazine',
                'expectedTags' => null,
                'name' => 'The magazine hashtag is matched after normalization',
            ],
            [
                'object' => $content + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#someMagazine'],
                    ['type' => 'Hashtag', 'name' => '#foo'],
                ]],
                'magazineName' => 'someMagazine',
                'expectedTags' => ['foo'],
                'name' => 'Other hashtags of the same object are kept',
            ],
            [
                'object' => ['content' => 'Lorem #someMagazine ipsum'] + ['tag' => [
                    ['type' => 'Hashtag', 'name' => '#someMagazine'],
                ]],
                'magazineName' => 'someMagazine',
                'expectedTags' => ['somemagazine'],
                'name' => 'A magazine name the author typed into the body is left alone',
            ],
        ];
    }

    public function testGetMarkdownBodyKeepsTheBodyText(): void
    {
        $body = $this->extractor->getMarkdownBody([
            'content' => 'Lorem ipsum',
            'tag' => [['type' => 'Hashtag', 'name' => '#foo']],
        ]);

        self::assertStringContainsString('Lorem ipsum', $body);
        self::assertStringContainsString('#foo', $body);
    }

    public function testGetMarkdownBodyWithoutTagsIsUnchanged(): void
    {
        self::assertNull($this->extractor->getMarkdownBody([]));
        self::assertEquals('Lorem ipsum', $this->extractor->getMarkdownBody(['content' => 'Lorem ipsum']));
    }
}
