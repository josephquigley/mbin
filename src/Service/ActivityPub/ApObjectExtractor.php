<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Service\ActivityPubManager;
use App\Service\TagExtractor;

class ApObjectExtractor
{
    public const MARKDOWN_TYPE = 'text/markdown';

    public function __construct(
        private readonly MarkdownConverter $markdownConverter,
        private readonly ActivityPubManager $activityPubManager,
        private readonly TagExtractor $tagExtractor,
    ) {
    }

    /**
     * @param array<string, mixed> $object
     */
    public function getMarkdownBody(array $object): ?string
    {
        $body = $this->extractBody($object);
        $hashtags = $this->getHashtags($object['tag'] ?? []);

        if (!$hashtags) {
            return $body;
        }

        return $this->tagExtractor->joinTagsToBody($body, $hashtags);
    }

    /**
     * Collect the hashtags of an object's "tag" array, normalized the same way a
     * hashtag typed into a body is. Entries of any other type, "Mention" above all,
     * are left alone.
     *
     * @return string[]
     */
    private function getHashtags(mixed $tags): array
    {
        if (!\is_array($tags)) {
            return [];
        }

        if (isset($tags['type'])) {
            // a single object rather than a list of them
            $tags = [$tags];
        }

        $result = [];
        foreach ($tags as $tag) {
            if (!\is_array($tag) || 'Hashtag' !== ($tag['type'] ?? null) || !\is_string($tag['name'] ?? null)) {
                continue;
            }

            // running the name back through the extractor is what keeps this
            // normalization identical to the one applied to inline hashtags,
            // and it drops names the local pattern would not have accepted
            $extracted = $this->tagExtractor->extract('#'.ltrim(trim($tag['name']), '#'));
            if ($extracted) {
                $result[] = $extracted[0];
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<string, mixed> $object
     */
    private function extractBody(array $object): ?string
    {
        $content = $object['content'] ?? null;
        $source = $object['source'] ?? null;

        // object has no content nor source to extract body from
        if (null === $content && null === $source) {
            return null;
        }

        if ($source && (isset($source['mediaType']) && self::MARKDOWN_TYPE === $source['mediaType'])) {
            // markdown source found, return them
            return $source['content'] ?? null;
        } elseif ($content && (isset($object['mediaType']) && self::MARKDOWN_TYPE === $object['mediaType'])) {
            // markdown source isn't found but object's content is specified
            // to be markdown, also return them
            return $content;
        } elseif ($content && \is_string($content)) {
            // assuming default content mediaType of text/html,
            // returning html -> markdown conversion of content
            return $this->markdownConverter->convert($content, $object['tag'] ?? []);
        }

        return '';
    }

    public function getExternalMediaBody(array $object): ?string
    {
        $body = null;

        if (isset($object['attachment'])) {
            $attachments = $object['attachment'];

            if ($images = $this->activityPubManager->handleExternalImages($attachments)) {
                $body .= "\n\n".implode(
                    "  \n",
                    array_map(
                        fn ($image) => \sprintf(
                            '![%s](%s)',
                            preg_replace('/\r\n|\r|\n/', ' ', $image->name),
                            $image->url
                        ),
                        $images
                    )
                );
            }

            if ($videos = $this->activityPubManager->handleExternalVideos($attachments)) {
                $body .= "\n\n".implode(
                    "  \n",
                    array_map(
                        fn ($video) => \sprintf(
                            '![%s](%s)',
                            preg_replace('/\r\n|\r|\n/', ' ', $video->name),
                            $video->url
                        ),
                        $videos
                    )
                );
            }
        }

        return $body;
    }
}
