<?php

declare(strict_types=1);

namespace Neo;

final class Feed
{
    public function __construct(private Store $store)
    {
    }
    public function render(string $kind): string
    {
        if (!in_array($kind, ['audio','video'], true)) {
            throw new \InvalidArgumentException('Invalid feed.');
        }
        // One read transaction gives settings and enclosure membership a consistent snapshot.
        return $this->store->transaction(function () use ($kind): string {
            $s = $this->store->settings();
            $base = $s['base_url'];
            $doc = new \DOMDocument('1.0', 'UTF-8');
            $doc->formatOutput = true;
            $rss = $doc->appendChild($doc->createElement('rss'));
            $rss->setAttribute('version', '2.0');
            $itunes = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:itunes', $itunes);
            $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:atom', 'http://www.w3.org/2005/Atom');
            $add = static function (\DOMNode $parent, string $name, string $value) use ($doc, $itunes): \DOMElement {
                $node = str_starts_with($name, 'itunes:') ? $doc->createElementNS($itunes, $name) : $doc->createElement($name);
                $node->appendChild($doc->createTextNode($value));
                $parent->appendChild($node);
                return $node;
            };
            $channel = $rss->appendChild($doc->createElement('channel'));
            $add($channel, 'title', $s['title'].' — '.ucfirst($kind));
            $add($channel, 'link', $base.'/');
            $add($channel, 'description', $s['description']);
            $add($channel, 'language', $s['language']);
            $add($channel, 'itunes:author', $s['owner_name']);
            $add($channel, 'itunes:explicit', $s['explicit'] ? 'true' : 'false');
            $add($channel, 'itunes:type', 'episodic');
            $owner = $channel->appendChild($doc->createElementNS($itunes, 'itunes:owner'));
            $add($owner, 'itunes:name', $s['owner_name']);
            $add($owner, 'itunes:email', $s['owner_email']);
            $cat = $channel->appendChild($doc->createElementNS($itunes, 'itunes:category'));
            $cat->setAttribute('text', $s['category']);
            $self = $channel->appendChild($doc->createElementNS('http://www.w3.org/2005/Atom', 'atom:link'));
            foreach (['href' => $base.'/feeds/'.$kind.'.xml','rel' => 'self','type' => 'application/rss+xml'] as $key => $value) {
                $self->setAttribute($key, $value);
            }
            if ($s['artwork']) {
                $url = $base.'/artwork/'.$s['artwork'];
                $cover = $channel->appendChild($doc->createElementNS($itunes, 'itunes:image'));
                $cover->setAttribute('href', $url);
                $image = $channel->appendChild($doc->createElement('image'));
                $add($image, 'url', $url);
                $add($image, 'title', $s['title']);
                $add($image, 'link', $base.'/');
            }
            $episodes = $this->store->all("SELECT e.*, a.id asset_id,a.mime,a.bytes,a.duration FROM episodes e JOIN assets a ON a.id=e.{$kind}_id WHERE e.state='published' ORDER BY e.published_at DESC,e.id");
            foreach ($episodes as $e) {
                $item = $channel->appendChild($doc->createElement('item'));
                $add($item, 'title', $e['title']);
                $add($item, 'description', $e['description']);
                $add($item, 'link', $base.'/episodes/'.$e['id']);
                $guid = $add($item, 'guid', 'urn:uuid:'.$e['id']);
                $guid->setAttribute('isPermaLink', 'false');
                $add($item, 'pubDate', gmdate(DATE_RSS, (int)$e['published_at']));
                $add($item, 'itunes:duration', (string)(int)round((float)$e['duration']));
                $add($item, 'itunes:explicit', $e['explicit'] ? 'true' : 'false');
                $add($item, 'itunes:episodeType', $e['episode_type']);
                if ($e['season']) {
                    $add($item, 'itunes:season', (string)$e['season']);
                }
                if ($e['number']) {
                    $add($item, 'itunes:episode', (string)$e['number']);
                }
                $enclosure = $item->appendChild($doc->createElement('enclosure'));
                foreach (['url' => $base.'/media/'.$e['asset_id'],'length' => (string)$e['bytes'],'type' => $e['mime']] as $k => $v) {
                    $enclosure->setAttribute($k, $v);
                }
            }
            return $doc->saveXML();
        });
    }
}
