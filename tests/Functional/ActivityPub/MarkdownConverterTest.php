<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub;

use App\Entity\User;
use App\Service\TagExtractor;
use PHPUnit\Framework\Attributes\DataProvider;

use function PHPUnit\Framework\assertEquals;

class MarkdownConverterTest extends ActivityPubFunctionalTestCase
{
    public function setUpRemoteEntities(): void
    {
    }

    public function setUpLocalEntities(): void
    {
        $domain = 'some.domain.tld';
        $this->switchToRemoteDomain($domain);

        $this->registerActor($this->getUserByUsername('someUser', email: "someUser@$domain"), $domain, true);
        $this->registerActor($this->getMagazineByName('someMagazine'), $domain, true);

        $this->switchToLocalDomain();
    }

    public function setUp(): void
    {
        parent::setUp();

        // generate the local user 'someUser'
        $user = $this->getUserByUsername('someUser', email: 'someUser@kbin.test');
        $this->getMagazineByName('someMagazine', $user);
        $mastodonUser = new User('SomeUser@mastodon.tld', 'SomeUser@mastodon.tld', '', 'Person', 'https://mastodon.tld/users/SomeAccount');
        $mastodonUser->apPublicUrl = 'https://mastodon.tld/@SomeAccount';
        $this->entityManager->persist($mastodonUser);
    }

    #[DataProvider('htmlMentionsProvider')]
    public function testMentions(string $html, array $apTags, array $expectedMentions, string $name): void
    {
        $converted = $this->apMarkdownConverter->convert($html, $apTags);
        $mentions = $this->mentionManager->extract($converted);
        assertEquals($expectedMentions, $mentions, message: "Mention test '$name'");
    }

    public static function htmlMentionsProvider(): array
    {
        return [
            [
                'html' => '<p><span class="h-card" translate="no"><a href="https://some.domain.tld/u/someUser" class="u-url mention">@<span>someUser</span></a></span> <span class="h-card" translate="no"><a href="https://kbin.test/u/someUser" class="u-url mention">@<span>someUser@kbin.test</span></a></span></p>',
                'apTags' => [
                    [
                        'type' => 'Mention',
                        'href' => 'https://some.domain.tld/u/someUser',
                        'name' => '@someUser',
                    ],
                    [
                        'type' => 'Mention',
                        'href' => 'https://kbin.test/u/someUser',
                        'name' => '@someUser@kbin.test',
                    ],
                ],
                'expectedMentions' => ['@someUser@some.domain.tld', '@someUser@kbin.test'],
                'name' => 'Local and remote user',
            ],
            [
                'html' => '<p><span class="h-card" translate="no"><a href="https://some.domain.tld/m/someMagazine" class="u-url mention">@<span>someMagazine</span></a></span></p>',
                'apTags' => [
                    [
                        'type' => 'Mention',
                        'href' => 'https://some.domain.tld/m/someMagazine',
                        'name' => '@someMagazine',
                    ],
                ],
                'expectedMentions' => ['@someMagazine@some.domain.tld'],
                'name' => 'Magazine mention',
            ],
            [
                'html' => '<p><span class="h-card" translate="no"><a href="https://kbin.test/m/someMagazine" class="u-url mention">@<span>someMagazine</span></a></span></p>',
                'apTags' => [
                    [
                        'type' => 'Mention',
                        'href' => 'https://kbin.test/m/someMagazine',
                        'name' => '@someMagazine',
                    ],
                ],
                'expectedMentions' => ['@someMagazine@kbin.test'],
                'name' => 'Local magazine mention',
            ],
            [
                'html' => '<a href=\"https://mastodon.tld/@SomeAccount\" class=\"u-url mention\">@<span>SomeAccount</span></a></span>',
                'apTags' => [
                    [
                        'type' => 'Mention',
                        'href' => 'https://mastodon.tld/users/SomeAccount',
                        'name' => '@SomeAccount@mastodon.tld',
                    ],
                ],
                'expectedMentions' => ['@SomeAccount@mastodon.tld'],
                'name' => 'Mastodon account mention',
            ],
        ];
    }

    #[DataProvider('htmlHashtagsProvider')]
    public function testHashtags(string $html, array $apTags, ?array $expectedTags, string $name): void
    {
        $converted = $this->apMarkdownConverter->convert($html, $apTags);
        $tags = (new TagExtractor())->extract($converted);
        assertEquals($expectedTags, $tags, message: "Hashtag test '$name'");
    }

    public static function htmlHashtagsProvider(): array
    {
        return [
            [
                'html' => '<p>hello</p>',
                'apTags' => [],
                'expectedTags' => null,
                'name' => 'No hashtag at all',
            ],
            [
                'html' => '<p>hello #foo</p>',
                'apTags' => [],
                'expectedTags' => ['foo'],
                'name' => 'Plain text hashtag, the control case',
            ],
            [
                'html' => '<a href="https://writefreely.tld/tag:foo">#foo</a>',
                'apTags' => [
                    ['type' => 'Hashtag', 'href' => 'https://writefreely.tld/tag:foo', 'name' => '#foo'],
                ],
                'expectedTags' => ['foo'],
                'name' => 'WriteFreely hashtag link',
            ],
            [
                'html' => '<p>a post about <a href="https://writefreely.tld/tag:foo">#foo</a> and more</p>',
                'apTags' => [
                    ['type' => 'Hashtag', 'href' => 'https://writefreely.tld/tag:foo', 'name' => '#foo'],
                ],
                'expectedTags' => ['foo'],
                'name' => 'WriteFreely hashtag link surrounded by prose',
            ],
            [
                'html' => '<p><a href="https://mastodon.tld/tags/foo" class="mention hashtag" rel="tag">#<span>foo</span></a></p>',
                'apTags' => [
                    ['type' => 'Hashtag', 'href' => 'https://mastodon.tld/tags/foo', 'name' => '#foo'],
                ],
                'expectedTags' => ['foo'],
                'name' => 'Mastodon hashtag link',
            ],
            [
                'html' => '<p>nothing inline here</p>',
                'apTags' => [
                    ['type' => 'Hashtag', 'href' => 'https://writefreely.tld/tag:foo', 'name' => '#foo'],
                ],
                'expectedTags' => null,
                'name' => 'Hashtag only in the tag array is not in the converted content',
            ],
        ];
    }
}
