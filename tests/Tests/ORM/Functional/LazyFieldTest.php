<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\ORM\Query;
use Doctrine\Tests\Models\LazyField\LazyFieldPost;
use Doctrine\Tests\OrmFunctionalTestCase;

use function array_keys;
use function str_contains;

class LazyFieldTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchemaForModels(LazyFieldPost::class);
    }

    private function requireNativeLazy(): void
    {
        if (! $this->_em->getConfiguration()->isNativeLazyObjectsEnabled()) {
            $this->markTestSkipped('Test requires PHP 8.4 native lazy objects to be enabled.');
        }
    }

    private function createPost(string $title = 'Hello', string $body = 'World'): LazyFieldPost
    {
        $post        = new LazyFieldPost();
        $post->title = $title;
        $post->body  = $body;

        $this->_em->persist($post);
        $this->_em->flush();
        $this->_em->clear();

        return $post;
    }

    public function testFindDoesNotSelectLazyField(): void
    {
        $this->requireNativeLazy();

        $post = $this->createPost();
        $this->getQueryLog()->reset()->enable();

        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        self::assertNotNull($found);
        self::assertQueryCount(1);
        self::assertStringNotContainsString('body', $this->getQueryLog()->queries[0]['sql']);
    }

    public function testFindCreatesLazyGhost(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost();
        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($found));
    }

    public function testEagerFieldAccessDoesNotTriggerInit(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('My Title', 'My Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);
        $this->getQueryLog()->reset()->enable();

        $title = $found->title;

        self::assertSame('My Title', $title);
        self::assertQueryCount(0, 'Accessing an eager field must not fire a query');
        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($found));
    }

    public function testAccessingLazyFieldTriggersInitAndLoadsValue(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('My Title', 'My Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);
        $this->getQueryLog()->reset()->enable();

        $body = $found->body;

        self::assertSame('My Body', $body);
        self::assertQueryCount(1, 'Accessing a lazy field must fire exactly one SELECT');
        self::assertFalse($this->_em->getUnitOfWork()->isUninitializedObject($found));
    }

    public function testLazyInitDoesNotOverwriteEagerFieldMutations(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('Original', 'The Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        // Mutate the eagerly-loaded field before lazy init fires.
        $found->title = 'Modified';

        // Access the lazy field — triggers full reload.
        $body = $found->body;

        self::assertSame('Modified', $found->title, 'In-memory mutation must survive lazy init');
        self::assertSame('The Body', $body);
    }

    public function testFindByReturnsLazyGhosts(): void
    {
        $this->requireNativeLazy();

        $this->createPost('A', 'Body A');
        $this->getQueryLog()->reset()->enable();

        $results = $this->_em->getRepository(LazyFieldPost::class)->findBy(['title' => 'A']);

        self::assertCount(1, $results);
        self::assertQueryCount(1);
        self::assertStringNotContainsString('body', $this->getQueryLog()->queries[0]['sql']);
        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($results[0]));
    }

    public function testFindAllReturnsLazyGhosts(): void
    {
        $this->requireNativeLazy();

        $this->createPost('A', 'Body A');
        $this->getQueryLog()->reset()->enable();

        $results = $this->_em->getRepository(LazyFieldPost::class)->findAll();

        self::assertNotEmpty($results);
        self::assertQueryCount(1);
        self::assertStringNotContainsString('body', $this->getQueryLog()->queries[0]['sql']);
        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($results[0]));
    }

    public function testDqlFullSelectAutoSkipsLazyField(): void
    {
        $this->requireNativeLazy();

        $post = $this->createPost('DQL Post', 'DQL Body');
        $this->getQueryLog()->reset()->enable();

        $dql     = 'SELECT p FROM ' . LazyFieldPost::class . ' p WHERE p.id = :id';
        $results = $this->_em->createQuery($dql)
            ->setParameter('id', $post->id)
            ->getResult();

        self::assertCount(1, $results);
        self::assertQueryCount(1);
        self::assertStringNotContainsString('body', $this->getQueryLog()->queries[0]['sql']);
        self::assertTrue($this->_em->getUnitOfWork()->isUninitializedObject($results[0]));
    }

    public function testDqlPartialCanExplicitlyIncludeLazyField(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('DQL Post', 'Explicit Body');
        $this->getQueryLog()->reset()->enable();

        $dql     = 'SELECT PARTIAL p.{id, title, body} FROM ' . LazyFieldPost::class . ' p WHERE p.id = :id';
        $results = $this->_em->createQuery($dql)
            ->setParameter('id', $post->id)
            ->getResult();

        self::assertCount(1, $results);
        self::assertQueryCount(1);
        $sql = $this->getQueryLog()->queries[0]['sql'];
        self::assertStringContainsString('body', $sql, 'PARTIAL with explicit body must include it in SELECT');
    }

    public function testPersistNewEntityWithLazyFieldWorks(): void
    {
        $post        = new LazyFieldPost();
        $post->title = 'Persisted';
        $post->body  = 'Persisted Body';

        $this->_em->persist($post);
        $this->_em->flush();
        $this->_em->clear();

        $found = $this->_em->find(LazyFieldPost::class, $post->id);
        self::assertNotNull($found);

        // Force full load to check the persisted body
        $body = $found->body;
        self::assertSame('Persisted Body', $body);
    }

    public function testUpdateLazyFieldWorks(): void
    {
        $post  = $this->createPost('Update Test', 'Original Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        // Trigger init so we can modify body
        $found->body = 'Updated Body';
        $this->_em->flush();
        $this->_em->clear();

        $reloaded = $this->_em->find(LazyFieldPost::class, $post->id);
        self::assertSame('Updated Body', $reloaded->body);
    }

    public function testRefreshLoadsLazyField(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('Refresh', 'Refresh Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        $this->getQueryLog()->reset()->enable();
        $this->_em->refresh($found);

        self::assertFalse($this->_em->getUnitOfWork()->isUninitializedObject($found));
        // After refresh body is accessible without a further query
        $body = $found->body;
        self::assertSame('Refresh Body', $body);
        // refresh fires 1 SELECT; body access must not fire another
        self::assertQueryCount(1);
    }

    public function testOriginalEntityDataExcludesLazyFieldsAfterFind(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('OED Test', 'OED Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);

        $oed = $this->_em->getUnitOfWork()->getOriginalEntityData($found);

        self::assertArrayHasKey('title', $oed);
        self::assertArrayNotHasKey('body', $oed, 'Lazy field must not appear in initial originalEntityData');
    }

    public function testOriginalEntityDataIncludesLazyFieldAfterInit(): void
    {
        $this->requireNativeLazy();

        $post  = $this->createPost('OED Test', 'OED Body');
        $found = $this->_em->find(LazyFieldPost::class, $post->id);
        // Access lazy field to trigger init
        $_ = $found->body;

        $oed = $this->_em->getUnitOfWork()->getOriginalEntityData($found);

        self::assertArrayHasKey('body', $oed);
        self::assertSame('OED Body', $oed['body']);
    }
}
