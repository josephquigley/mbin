<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Inbox;

use App\Entity\Hashtag;
use App\Message\ActivityPub\Inbox\ActivityMessage;
use App\Tests\Functional\ActivityPub\ActivityPubFunctionalTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group(name: 'ActivityPub')]
#[Group(name: 'NonThreadSafe')]
class HashtagTest extends ActivityPubFunctionalTestCase
{
    private array $createEntry;
    private array $createEntryComment;
    private array $createPost;
    private array $createPostComment;

    /**
     * One hashtag and one mention, which is the shape WriteFreely, Mastodon and
     * GoToSocial all send. Only the hashtag may become a tag.
     */
    private const AP_TAGS = [
        [
            'type' => 'Hashtag',
            'href' => 'https://writefreely.tld/tag:federation',
            'name' => '#Federation',
        ],
        [
            'type' => 'Mention',
            'href' => 'https://remote.mbin/u/remoteUser',
            'name' => '@remoteUser',
        ],
    ];

    public function setUpRemoteEntities(): void
    {
        $this->createEntry = $this->createRemoteEntryInLocalMagazine($this->localMagazine, $this->remoteUser);
        $this->createEntryComment = $this->createRemoteEntryCommentInLocalMagazine($this->localMagazine, $this->remoteUser);
        $this->createPost = $this->createRemotePostInLocalMagazine($this->localMagazine, $this->remoteUser);
        $this->createPostComment = $this->createRemotePostCommentInLocalMagazine($this->localMagazine, $this->remoteUser);
    }

    public function setUpLocalEntities(): void
    {
    }

    public function testCreateEntryWithHashtag(): void
    {
        $activity = $this->withTags($this->createEntry, self::AP_TAGS);

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        $entry = $this->entryRepository->findOneBy(['apId' => $activity['object']['id']]);
        self::assertNotNull($entry);
        self::assertContains('federation', $this->tagLinkRepository->getTagsOfContent($entry));
        self::assertNotContains('remoteuser', $this->tagLinkRepository->getTagsOfContent($entry));
        self::assertNotContains($this->localMagazine->name, $this->tagLinkRepository->getTagsOfContent($entry));
    }

    public function testCreateEntryCommentWithHashtag(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->createEntry)));
        $activity = $this->withTags($this->createEntryComment, self::AP_TAGS);

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        $comment = $this->entryCommentRepository->findOneBy(['apId' => $activity['object']['id']]);
        self::assertNotNull($comment);
        self::assertContains('federation', $this->tagLinkRepository->getTagsOfContent($comment));
        self::assertNotContains('remoteuser', $this->tagLinkRepository->getTagsOfContent($comment));
        self::assertNotContains($this->localMagazine->name, $this->tagLinkRepository->getTagsOfContent($comment));
    }

    public function testCreatePostWithHashtag(): void
    {
        $activity = $this->withTags($this->createPost, self::AP_TAGS);

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        $post = $this->postRepository->findOneBy(['apId' => $activity['object']['id']]);
        self::assertNotNull($post);
        self::assertContains('federation', $this->tagLinkRepository->getTagsOfContent($post));
        self::assertNotContains('remoteuser', $this->tagLinkRepository->getTagsOfContent($post));
        // no magazine assertion here: PostNoteFactory is the one factory that also joins
        // the magazine hashtag into the body, so a federated post has carried it as a tag
        // since long before this change and dropping it is a separate question
    }

    public function testCreatePostCommentWithHashtag(): void
    {
        $this->bus->dispatch(new ActivityMessage(json_encode($this->createPost)));
        $activity = $this->withTags($this->createPostComment, self::AP_TAGS);

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        $comment = $this->postCommentRepository->findOneBy(['apId' => $activity['object']['id']]);
        self::assertNotNull($comment);
        self::assertContains('federation', $this->tagLinkRepository->getTagsOfContent($comment));
        self::assertNotContains('remoteuser', $this->tagLinkRepository->getTagsOfContent($comment));
        self::assertNotContains($this->localMagazine->name, $this->tagLinkRepository->getTagsOfContent($comment));
    }

    public function testHashtagOfTheTagArrayIsNotDuplicatedByTheOneInTheBody(): void
    {
        $activity = $this->withTags($this->createPost, self::AP_TAGS);
        unset($activity['object']['source']);
        $activity['object']['content'] = '<p>a post about #federation</p>';

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        $post = $this->postRepository->findOneBy(['apId' => $activity['object']['id']]);
        self::assertNotNull($post);
        self::assertContains('federation', $this->tagLinkRepository->getTagsOfContent($post));
        self::assertEquals(1, substr_count($post->body, '#federation'));
    }

    public function testCannotCreatePostWithBannedHashtagOfTheTagArray(): void
    {
        $hashtag = new Hashtag();
        $hashtag->tag = 'federation';
        $hashtag->banned = true;
        $this->entityManager->persist($hashtag);
        $this->entityManager->flush();

        $activity = $this->withTags($this->createPost, self::AP_TAGS);

        $this->bus->dispatch(new ActivityMessage(json_encode($activity)));

        self::assertNull($this->postRepository->findOneBy(['apId' => $activity['object']['id']]));
    }

    private function withTags(array $activity, array $tags): array
    {
        $activity['object']['tag'] = $tags;

        return $activity;
    }
}
