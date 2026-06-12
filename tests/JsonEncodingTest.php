<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\Export;
use Loro\Loro;
use Loro\LoroDoc;
use Loro\LoroList;
use Loro\LoroMap;
use Loro\TreeParentId;
use Loro\Value;
use Loro\VersionVector;

final class JsonEncodingTest extends LoroTestCase
{
    public function testJsonUpdatesRoundTripAcrossContainerTypes(): void
    {
        $doc = new LoroDoc();
        $doc->setPeerId(0);

        $text = $doc->getText(Container::idLike('text'));
        $text->insert(0, '123');

        $map = $doc->getMap(Container::idLike('map'));
        $subMap = Container::insertMapContainer($map, 'subMap', new LoroMap());
        Container::insertMapValue($subMap, 'foo', 'bar');

        $list = $doc->getList(Container::idLike('list'));
        Container::pushListValue($list, 'foo');
        Container::pushListValue($list, 'bird');

        $movableList = $doc->getMovableList(Container::idLike('movableList'));
        Container::pushListValue($movableList, 'move list');
        Container::pushListValue($movableList, 'bird');
        $movableList->mov(1, 0);

        $tree = $doc->getTree(Container::idLike('tree'));
        $root = $tree->create(TreeParentId::root());
        $child = $tree->create(TreeParentId::node($root));
        Container::insertMapValue($tree->getMeta($child), 'tree', 'abc');

        Container::markText($text, 0, 3, 'bold', true);

        $jsonUpdates = $doc->exportJsonUpdates(new VersionVector(), $doc->oplogVv());
        $doc2 = new LoroDoc();
        $doc2->importJsonUpdates($jsonUpdates);

        self::assertEquals(Loro::toJson($doc), Loro::toJson($doc2));
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
        ], Loro::toJson($doc));
    }

    public function testExportJsonUpdatesUsesNullForNullValues(): void
    {
        $doc = new LoroDoc();
        $map = $doc->getMap(Container::idLike('map'));
        Container::insertMapValue($map, 'key', null);

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

        $text = $doc->getText(Container::idLike('text'));
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
        $map = $doc->getMap(Container::idLike('map'));
        $subMap = Container::insertMapContainer($map, 'subMap', new LoroMap());
        Container::insertMapValue($subMap, 'foo', 'bar');

        $list = $doc->getList(Container::idLike('list'));
        $subList = Container::insertListContainer($list, 0, new LoroList());
        Container::pushListValue($subList, 'item1');
        Container::pushListValue($subList, 'item2');

        self::assertEquals([
            'map' => ['subMap' => ['foo' => 'bar']],
            'list' => [['item1', 'item2']],
        ], Loro::toJson($doc));
        self::assertSame(['foo' => 'bar'], Value::toPhp($map->get('subMap')));
    }
}
