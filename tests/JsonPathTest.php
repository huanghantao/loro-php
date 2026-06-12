<?php

declare(strict_types=1);

namespace Loro\Tests;

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
        $store->set('books', $books);
        $store->set('featured_author', 'George Orwell');
        $store->set('min_price', 10);
        $store->set('featured_authors', ['George Orwell', 'Jane Austen']);

        $project = $this->doc->getMap('project');
        $project->set('name', 'Launch Plan');
        $project->set('tasks', [
            ['id' => 1, 'title' => 'Storyboard slides', 'assignee' => 'amy', 'status' => 'in-progress'],
            ['id' => 2, 'title' => 'Budget review', 'assignee' => 'li', 'status' => 'todo'],
            ['id' => 3, 'title' => 'Finalize keynote deck', 'assignee' => 'amy', 'status' => 'done'],
        ]);

        $drafts = $this->doc->getList('drafts');
        $drafts->push(['title' => 'slide walkthrough']);
        $drafts->push(['title' => 'executive summary']);
        $drafts->push(['title' => 'slide qa checklist']);

        $todos = $this->doc->getList('todos');
        $todos->push(['title' => 'Wire up auth', 'status' => 'done']);
        $todos->push(['title' => 'Polish animation', 'status' => 'doing']);
        $todos->push(['title' => 'Ship launch blog', 'status' => 'done']);

        $this->doc->commit();
    }

    public function testBasicSelectors(): void
    {
        self::assertSame(['1984'], $this->doc->jsonpathToJSON("$['store'].books[0].title"));
        self::assertSame(['1984'], $this->doc->jsonpathToJSON("$['store']['books'][0]['title']"));

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
        ], $this->doc->jsonpathToJSON("$['store'].books[*].title"));

        self::assertCount(19, $this->doc->jsonpathToJSON('$..title'));
    }

    public function testStringLogicalAndInFilters(): void
    {
        self::assertSame(['1984'], $this->doc->jsonpathToJSON(
            "$['store'].books[?(@.title == '1984')].title"
        ));
        self::assertSame(['Animal Farm'], $this->doc->jsonpathToJSON(
            "$['store'].books[?(@.title contains 'Farm')].title"
        ));

        $orResult = $this->doc->jsonpathToJSON(
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
        ], $this->doc->jsonpathToJSON("$['store'].books[?(!(@.available == false))].title"));

        $inResult = $this->doc->jsonpathToJSON(
            '$.store.books[?(@.author in $.store.featured_authors)].title'
        );
        sort($inResult);
        self::assertSame(['1984', 'Animal Farm', 'Pride and Prejudice'], $inResult);
    }

    public function testUnionSliceAndRecursiveFilters(): void
    {
        self::assertSame(['1984', 'Brave New World'], $this->doc->jsonpathToJSON(
            "$['store'].books[0,2].title"
        ));
        self::assertSame(['1984', 'George Orwell'], $this->doc->jsonpathToJSON(
            "$['store'].books[0]['title','author']"
        ));
        self::assertSame(['Pride and Prejudice', 'The Hobbit'], $this->doc->jsonpathToJSON(
            "$['store'].books[-2,-1].title"
        ));
        self::assertSame(['1984', 'Animal Farm', 'Brave New World'], $this->doc->jsonpathToJSON(
            "$['store'].books[0:3].title"
        ));
        self::assertSame(['1984', 'Brave New World', 'The Great Gatsby'], $this->doc->jsonpathToJSON(
            "$['store'].books[0:5:2].title"
        ));

        $priceResult = $this->doc->jsonpathToJSON('$..[?(@.price > 10)].title');
        sort($priceResult);
        self::assertSame(['Brave New World', 'The Hobbit', 'To Kill a Mockingbird'], $priceResult);
    }

    public function testRootReferencesAndDiscordExamples(): void
    {
        $featured = $this->doc->jsonpathToJSON(
            '$.store.books[?(@.author == $.store.featured_author)].title'
        );
        sort($featured);
        self::assertSame(['1984', 'Animal Farm'], $featured);

        $notFeatured = $this->doc->jsonpathToJSON(
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

        $amyTasks = $this->doc->jsonpathToJSON('$.project.tasks[?(@.assignee in ["amy"])]');
        $amyTitles = array_column($amyTasks, 'title');
        sort($amyTitles);
        self::assertSame(['Finalize keynote deck', 'Storyboard slides'], $amyTitles);

        $slideDrafts = $this->doc->jsonpathToJSON('$.drafts[?(@.title contains "slide")]');
        self::assertSame(['slide walkthrough', 'slide qa checklist'], array_column($slideDrafts, 'title'));

        $doneTodos = $this->doc->jsonpathToJSON('$.todos[?(@.status == "done")]');
        self::assertSame('Wire up auth', $doneTodos[0]['title']);
    }

    public function testJsonpathSubscriptionTriggersAndCanUnsubscribe(): void
    {
        $hit = 0;
        $subscription = $this->doc->subscribeJsonpath(
            '$.store.books[0].title',
            static function () use (&$hit): void {
                $hit++;
            }
        );

        $store = $this->doc->getMap('store');
        $books = $this->doc->jsonpathToJSON('$.store.books')[0];
        $books[0]['title'] = 'Nineteen Eighty-Four';
        $books[0]['title'] = '1984 (second)';
        $store->set('books', $books);
        $this->doc->commit();

        self::assertSame(1, $hit);

        $subscription->unsubscribe();
        $books[0]['title'] = '1984 (third)';
        $store->set('books', $books);
        $this->doc->commit();

        self::assertSame(1, $hit);
    }

    public function testQuotedKeysWithSpecialCharacters(): void
    {
        $specialDoc = new LoroDoc();
        $root = $specialDoc->getMap('root');
        $root->set('book', [
            'map' => [
                'book-with-dash' => [
                    'price-$10' => 'cheap',
                ],
            ],
        ]);
        $specialDoc->commit();

        self::assertSame(
            ['cheap'],
            $specialDoc->jsonpathToJSON("$.root.book['map']['book-with-dash']['price-\$10']")
        );
    }
}
