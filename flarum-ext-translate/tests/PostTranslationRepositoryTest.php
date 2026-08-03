<?php

namespace Twikura\Translate\Tests;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Twikura\Translate\Repository\PostTranslationRepository;

class PostTranslationRepositoryTest extends TestCase
{
    private static ?Capsule $capsule = null;

    private ConnectionInterface $db;
    private PostTranslationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = self::connection();
        $this->createSchema();
        $this->truncateTables();

        $this->repo = new PostTranslationRepository($this->db);
    }

    private static function connection(): ConnectionInterface
    {
        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection([
                'driver'    => 'mysql',
                'host'      => 'localhost',
                'database'  => 'translate_test',
                'username'  => 'translate_test',
                'password'  => 'translate_test',
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ]);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        return self::$capsule->getConnection();
    }

    private function createSchema(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS post_translations (
                post_id INT UNSIGNED NOT NULL,
                target_lang VARCHAR(10) NOT NULL,
                source_content MEDIUMTEXT,
                translated_content MEDIUMTEXT NULL,
                translated_content_html MEDIUMTEXT NULL,
                source_lang VARCHAR(10) NULL,
                status ENUM(\'pending\',\'running\',\'done\',\'error\') DEFAULT \'pending\',
                error TEXT NULL,
                is_backfill TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id, target_lang),
                KEY idx_status_backfill_created (status, is_backfill, created_at)
            ) ENGINE=InnoDB'
        );

        // Minimal posts table for findStalePostIds().
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS posts (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                type VARCHAR(20) NOT NULL DEFAULT \'comment\',
                content MEDIUMTEXT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB'
        );
    }

    private function truncateTables(): void
    {
        $this->db->statement('TRUNCATE TABLE post_translations');
        $this->db->statement('TRUNCATE TABLE posts');
    }

    private function row(int $postId, string $targetLang): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM post_translations WHERE post_id = ? AND target_lang = ?',
            [$postId, $targetLang]
        );

        return $row !== null ? (array) $row : null;
    }

    public function testEnqueueInsertsPendingRow(): void
    {
        $this->repo->enqueue(1, 'zh', 'source text');

        $row = $this->row(1, 'zh');

        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['is_backfill']);
        $this->assertSame('source text', $row['source_content']);
    }

    public function testEnqueueResetsExistingDoneRow(): void
    {
        $this->repo->enqueue(1, 'zh', 'source text');

        // Simulate a previously-completed (then failed/obsolete) translation.
        $this->db->statement(
            'UPDATE post_translations
             SET status = \'done\',
                 translated_content = \'OLD-TRANSLATION\',
                 translated_content_html = \'<p>OLD-TRANSLATION</p>\',
                 error = \'boom\'
             WHERE post_id = 1 AND target_lang = \'zh\''
        );

        $this->repo->enqueue(1, 'zh', 'new source');

        $row = $this->row(1, 'zh');

        $this->assertNotNull($row);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('new source', $row['source_content']);
        $this->assertNull($row['error']);
        $this->assertNull($row['translated_content']);
        $this->assertNull($row['translated_content_html']);
    }

    public function testFindForPostExactMatch(): void
    {
        $this->repo->enqueue(42, 'ja', 'source');

        $row = $this->repo->findForPost(42, 'ja');

        $this->assertNotNull($row);
        $this->assertSame(42, (int) $row['post_id']);
        $this->assertSame('ja', $row['target_lang']);
        $this->assertSame('pending', $row['status']);
    }

    public function testFindForPostMiss(): void
    {
        $this->repo->enqueue(42, 'ja', 'source');

        $this->assertNull($this->repo->findForPost(42, 'zh'));
        $this->assertNull($this->repo->findForPost(43, 'ja'));
    }

    public function testFindForPostFlexibleExactMatchPreferred(): void
    {
        $this->repo->enqueue(5, 'zh', 'source');
        $this->repo->enqueue(5, 'zh-Hans', 'source');

        $row = $this->repo->findForPostFlexible(5, 'zh');

        $this->assertNotNull($row);
        $this->assertSame('zh', $row['target_lang']);
    }

    public function testFindForPostFlexibleRegionRequestFallsBackToBareCode(): void
    {
        $this->repo->enqueue(6, 'zh', 'source');

        $row = $this->repo->findForPostFlexible(6, 'zh-Hans');

        $this->assertNotNull($row);
        $this->assertSame('zh', $row['target_lang']);
    }

    public function testFindForPostFlexibleBareRequestFindsRegionVariant(): void
    {
        $this->repo->enqueue(7, 'zh-Hans', 'source');

        $row = $this->repo->findForPostFlexible(7, 'zh');

        $this->assertNotNull($row);
        $this->assertSame('zh-Hans', $row['target_lang']);
    }

    public function testFindForPostFlexibleNoMatchReturnsNull(): void
    {
        $this->repo->enqueue(8, 'ja', 'source');

        $this->assertNull($this->repo->findForPostFlexible(8, 'zh'));
        $this->assertNull($this->repo->findForPostFlexible(99, 'zh'));
    }

    public function testGetStatusCounts(): void
    {
        foreach ([[1, 'pending'], [2, 'running'], [3, 'done'], [4, 'error'], [5, 'done']] as [$id, $status]) {
            $this->repo->enqueue($id, 'zh', 'source');
            $this->db->statement(
                "UPDATE post_translations SET status = ? WHERE post_id = ? AND target_lang = 'zh'",
                [$status, $id]
            );
        }
        // A different language should not leak into the counts.
        $this->repo->enqueue(6, 'ja', 'source');

        $counts = $this->repo->getStatusCounts('zh');

        $this->assertSame([
            'pending' => 1,
            'running' => 1,
            'done'    => 2,
            'error'   => 1,
        ], $counts);
    }

    public function testGetStatusCountsEmpty(): void
    {
        $this->assertSame([
            'pending' => 0,
            'running' => 0,
            'done'    => 0,
            'error'   => 0,
        ], $this->repo->getStatusCounts('zh'));
    }

    public function testFindStalePostIds(): void
    {
        // Post 1: comment, no translation → stale (missing).
        $this->insertPost(1, 'comment', 'alpha');
        // Post 2: comment, translation done + in sync → not stale.
        $this->insertPost(2, 'comment', 'beta');
        $this->repo->enqueue(2, 'zh', 'beta');
        $this->markDone(2, 'zh');
        // Post 3: comment, translation done but source changed → stale.
        $this->insertPost(3, 'comment', 'gamma');
        $this->repo->enqueue(3, 'zh', 'OLD');
        $this->markDone(3, 'zh');
        // Post 4: comment, translation in error → stale.
        $this->insertPost(4, 'comment', 'delta');
        $this->repo->enqueue(4, 'zh', 'delta');
        $this->db->statement(
            "UPDATE post_translations SET status = 'error' WHERE post_id = 4 AND target_lang = 'zh'"
        );
        // Post 5: system (non-comment) post, no translation → excluded.
        $this->insertPost(5, 'moderator', 'system note');

        $stale = $this->repo->findStalePostIds('zh');

        $this->assertSame([1, 3, 4], $stale);
    }

    private function insertPost(int $id, string $type, string $content): void
    {
        $this->db->statement(
            'INSERT INTO posts (id, type, content) VALUES (?, ?, ?)',
            [$id, $type, $content]
        );
    }

    private function markDone(int $postId, string $targetLang): void
    {
        $this->db->statement(
            "UPDATE post_translations
             SET status = 'done', translated_content = 'DONE', translated_content_html = '<p>DONE</p>'
             WHERE post_id = ? AND target_lang = ?",
            [$postId, $targetLang]
        );
    }
}
