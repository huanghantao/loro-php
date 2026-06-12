<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\ExportMode;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMap;
use Loro\TreeParentId;
use Loro\VersionVector;

final class JsonEncodingTest extends LoroTestCase
{
    public function testJsonUpdatesRoundTripAcrossContainerTypes(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);

        $text = $doc->getText('text');
        $text->insert(0, '123');

        $map = $doc->getMap('map');
        $subMap = $map->setContainer('subMap', new LoroMap());
        $subMap->set('foo', 'bar');

        $list = $doc->getList('list');
        $list->push('foo');
        $list->push('bird');

        $movableList = $doc->getMovableList('movableList');
        $movableList->push('move list');
        $movableList->push('bird');
        $movableList->mov(1, 0);

        $tree = $doc->getTree('tree');
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        $tree->getMeta($child)->set('tree', 'abc');

        $text->mark(0, 3, 'bold', true);

        $jsonUpdates = $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv());
        $doc2 = new LoroDoc();
        $doc2->importJsonUpdates($jsonUpdates);

        self::assertEquals($doc->toJSON(), $doc2->toJSON());
    }

    public function testJsonUpdatesCanDecodeLegacyShape(): void
    {
        $legacyJson = <<<'JSON'
{
  "schema_version": 1,
  "start_version": {},
  "peers": ["14944917281143706156"],
  "changes": [
    {
      "id": "0@0",
      "timestamp": 0,
      "deps": [],
      "lamport": 0,
      "msg": null,
      "ops": [
        {
          "container": "cid:root-text:Text",
          "content": {"type": "insert", "pos": 0, "text": "123"},
          "counter": 0
        },
        {
          "container": "cid:root-map:Map",
          "content": {"type": "insert", "key": "subMap", "value": "🦜:cid:3@0:Map"},
          "counter": 3
        },
        {
          "container": "cid:3@0:Map",
          "content": {"type": "insert", "key": "foo", "value": "bar"},
          "counter": 4
        },
        {
          "container": "cid:root-list:List",
          "content": {"type": "insert", "pos": 0, "value": ["foo", "bird"]},
          "counter": 5
        }
      ]
    }
  ]
}
JSON;

        $doc = new LoroDoc();
        $doc->importJsonUpdates($legacyJson);

        self::assertEquals([
            'text' => '123',
            'map' => ['subMap' => ['foo' => 'bar']],
            'list' => ['foo', 'bird'],
        ], $doc->toJSON());
    }

    public function testExportJsonUpdatesUsesNullForNullValues(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');
        $map->set('key', null);

        $json = json_decode(
            $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv()),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'type' => 'insert',
            'key' => 'key',
            'value' => null,
        ], $json['changes'][0]['ops'][0]['content']);
    }

    public function testTextDeleteIsEncodedAsCompressedDeleteOp(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);

        $text = $doc->getText('text');
        $text->insert(0, '123');
        $doc->commit();
        $text->delete(2, 1);
        $text->delete(1, 1);
        $text->delete(0, 1);
        $doc->commit();

        $json = json_decode(
            $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv()),
            true,
            flags: JSON_THROW_ON_ERROR
        );

        self::assertSame([
            'type' => 'insert',
            'pos' => 0,
            'text' => '123',
        ], $json['changes'][0]['ops'][0]['content']);
        self::assertSame([
            'type' => 'delete',
            'pos' => 2,
            'len' => -3,
            'start_id' => '0@0',
        ], $json['changes'][0]['ops'][1]['content']);
    }

    public function testNestedContainersConvertToPhpArrays(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap('map');
        $subMap = $map->setContainer('subMap', new LoroMap());
        $subMap->set('foo', 'bar');

        $list = $doc->getList('list');
        $subList = $list->insertContainer(0, new LoroList());
        $subList->push('item1');
        $subList->push('item2');

        self::assertEquals([
            'map' => ['subMap' => ['foo' => 'bar']],
            'list' => [['item1', 'item2']],
        ], $doc->toJSON());
        self::assertSame(['foo' => 'bar'], $map->get('subMap')?->toJSON());
    }
}
