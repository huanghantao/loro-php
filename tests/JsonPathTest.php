<?php

declare(strict_types=1);

namespace Loro\Tests;

use Loro\Container;
use Loro\Events;
use Loro\Loro;
use Loro\LoroDoc;

final class JsonPathTest extends LoroTestCase
{
    private LoroDoc $doc;

    protected function setUp(): void
    {
        $this->doc = new LoroDoc();

        $books = [
            ['title' => '1984', 'author' => 'George Orwell', 'price' => 10, 'available' => true],
            ['title' => 'Animal Farm', 'author' => 'George Orwell', 'price' => 8, 'available' => true],
            ['title' => 'Brave New World', 'author' => 'Aldous Huxley', 'price' => 12, 'available' => false],
            ['title' => 'Fahrenheit 451', 'author' => 'Ray Bradbury', 'price' => 9, 'available' => true],
            ['title' => 'The Great Gatsby', 'author' => 'F. Scott Fitzgerald', 'price' => null, 'available' => true],
            ['title' => 'To Kill a Mockingbird', 'author' => 'Harper Lee', 'price' => 11, 'available' => true],
            ['title' => 'The Catcher in the Rye', 'author' => 'J.D. Salinger', 'price' => 10, 'available' => false],
            ['title' => 'Lord of the Flies', 'author' => 'William Golding', 'price' => 9, 'available' => true],
            ['title' => 'Pride and Prejudice', 'author' => 'Jane Austen', 'price' => 7, 'available' => true],
            ['title' => 'The Hobbit', 'author' => 'J.R.R. Tolkien', 'price' => 14, 'available' => true],
        ];

        $store = $this->doc->getMap('store');
        Container::insertMapValue($store, 'books', $books);
        Container::insertMapValue($store, 'featured_author', 'George Orwell');
        Container::insertMapValue($store, 'min_price', 10);
        Container::insertMapValue($store, 'featured_authors', ['George Orwell', 'Jane Austen']);

        $project = $this->doc->getMap('project');
        Container::insertMapValue($project, 'name', 'Launch Plan');
        Container::insertMapValue($project, 'tasks', [
            ['id' => 1, 'title' => 'Storyboard slides', 'assignee' => 'amy', 'status' => 'in-progress'],
            ['id' => 2, 'title' => 'Budget review', 'assignee' => 'li', 'status' => 'todo'],
            ['id' => 3, 'title' => 'Finalize keynote deck', 'assignee' => 'amy', 'status' => 'done'],
        ]);

        $drafts = $this->doc->getList('drafts');
        Container::pushListValue($drafts, ['title' => 'slide walkthrough']);
        Container::pushListValue($drafts, ['title' => 'executive summary']);
        Container::pushListValue($drafts, ['title' => 'slide qa checklist']);

        $todos = $this->doc->getList('todos');
        Container::pushListValue($todos, ['title' => 'Wire up auth', 'status' => 'done']);
        Container::pushListValue($todos, ['title' => 'Polish animation', 'status' => 'doing']);
        Container::pushListValue($todos, ['title' => 'Ship launch blog', 'status' => 'done']);

        $this->doc->commit();
    }

    public function testBasicSelectors(): void
    {
        self::assertSame(['1984'], Loro::jsonpath($this->doc, "$['store'].books[0].title"));
        self::assertSame(['1984'], Loro::jsonpath($this->doc, "$['store']['books'][0]['title']"));

        self::assertSame([
            '1984',
            'Animal Farm',
            'Brave New World',
            'Fahrenheit 451',
            'The Great Gatsby',
            'To Kill a Mockingbird',
            'The Catcher in the Rye',
            'Lord of the Flies',
            'Pride and Prejudice',
            'The Hobbit',
        ], Loro::jsonpath($this->doc, "$['store'].books[*].title"));

        self::assertCount(19, Loro::jsonpath($this->doc, '$..title'));
    }

    public function testStringLogicalAndInFilters(): void
    {
        self::assertSame(['1984'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[?(@.title == '1984')].title"
        ));
        self::assertSame(['Animal Farm'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[?(@.title contains 'Farm')].title"
        ));

        $orResult = Loro::jsonpath(
            $this->doc,
            '$[\'store\'].books[?(@.author == "George Orwell" || @.price >= 10)].title'
        );
        sort($orResult);
        self::assertSame([
            '1984',
            'Animal Farm',
            'Brave New World',
            'The Catcher in the Rye',
            'The Hobbit',
            'To Kill a Mockingbird',
        ], $orResult);

        self::assertSame([
            '1984',
            'Animal Farm',
            'Fahrenheit 451',
            'The Great Gatsby',
            'To Kill a Mockingbird',
            'Lord of the Flies',
            'Pride and Prejudice',
            'The Hobbit',
        ], Loro::jsonpath($this->doc, "$['store'].books[?(!(@.available == false))].title"));

        $inResult = Loro::jsonpath(
            $this->doc,
            '$.store.books[?(@.author in $.store.featured_authors)].title'
        );
        sort($inResult);
        self::assertSame(['1984', 'Animal Farm', 'Pride and Prejudice'], $inResult);
    }

    public function testUnionSliceAndRecursiveFilters(): void
    {
        self::assertSame(['1984', 'Brave New World'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[0,2].title"
        ));
        self::assertSame(['1984', 'George Orwell'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[0]['title','author']"
        ));
        self::assertSame(['Pride and Prejudice', 'The Hobbit'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[-2,-1].title"
        ));
        self::assertSame(['1984', 'Animal Farm', 'Brave New World'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[0:3].title"
        ));
        self::assertSame(['1984', 'Brave New World', 'The Great Gatsby'], Loro::jsonpath(
            $this->doc,
            "$['store'].books[0:5:2].title"
        ));

        $priceResult = Loro::jsonpath($this->doc, '$..[?(@.price > 10)].title');
        sort($priceResult);
        self::assertSame(['Brave New World', 'The Hobbit', 'To Kill a Mockingbird'], $priceResult);
    }

    public function testRootReferencesAndDiscordExamples(): void
    {
        $featured = Loro::jsonpath(
            $this->doc,
            '$.store.books[?(@.author == $.store.featured_author)].title'
        );
        sort($featured);
        self::assertSame(['1984', 'Animal Farm'], $featured);

        $notFeatured = Loro::jsonpath(
            $this->doc,
            '$.store.books[?(@.author != $.store.featured_author)].title'
        );
        sort($notFeatured);
        self::assertSame([
            'Brave New World',
            'Fahrenheit 451',
            'Lord of the Flies',
            'Pride and Prejudice',
            'The Catcher in the Rye',
            'The Great Gatsby',
            'The Hobbit',
            'To Kill a Mockingbird',
        ], $notFeatured);

        $amyTasks = Loro::jsonpath($this->doc, '$.project.tasks[?(@.assignee in ["amy"])]');
        $amyTitles = array_column($amyTasks, 'title');
        sort($amyTitles);
        self::assertSame(['Finalize keynote deck', 'Storyboard slides'], $amyTitles);

        $slideDrafts = Loro::jsonpath($this->doc, '$.drafts[?(@.title contains "slide")]');
        self::assertSame(['slide walkthrough', 'slide qa checklist'], array_column($slideDrafts, 'title'));

        $doneTodos = Loro::jsonpath($this->doc, '$.todos[?(@.status == "done")]');
        self::assertSame('Wire up auth', $doneTodos[0]['title']);
    }

    public function testJsonpathSubscriptionTriggersAndCanUnsubscribe(): void
    {
        $hit = 0;
        $subscription = Events::subscribeJsonpath(
            $this->doc,
            '$.store.books[0].title',
            static function () use (&$hit): void {
                $hit++;
            }
        );

        $store = $this->doc->getMap('store');
        $books = Loro::jsonpath($this->doc, '$.store.books')[0];
        $books[0]['title'] = 'Nineteen Eighty-Four';
        $books[0]['title'] = '1984 (second)';
        Container::insertMapValue($store, 'books', $books);
        $this->doc->commit();

        self::assertSame(1, $hit);

        $subscription->unsubscribe();
        $books[0]['title'] = '1984 (third)';
        Container::insertMapValue($store, 'books', $books);
        $this->doc->commit();

        self::assertSame(1, $hit);
    }

    public function testQuotedKeysWithSpecialCharacters(): void
    {
        $specialDoc = new LoroDoc();
        $root = $specialDoc->getMap('root');
        Container::insertMapValue($root, 'book', [
            'map' => [
                'book-with-dash' => [
                    'price-$10' => 'cheap',
                ],
            ],
        ]);
        $specialDoc->commit();

        self::assertSame(
            ['cheap'],
            Loro::jsonpath($specialDoc, "$.root.book['map']['book-with-dash']['price-\$10']")
        );
    }
}
