<?php

namespace Tests\Unit;

use App\Support\FrontendAssets;
use PHPUnit\Framework\TestCase;

/**
 * Which delivery path FrontendAssets picks, given every state a checkout can be
 * in. Run against a temporary public/ so the real one is never touched.
 *
 * The case that matters most is a hot file left behind by a dev server that is
 * no longer running: it used to be taken at face value, which pointed every
 * stylesheet at a dead port and cost the site its layout until someone thought
 * to delete the file.
 */
class FrontendAssetsTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sz-assets-' . bin2hex(random_bytes(6));
        mkdir($this->publicDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->publicDir);

        parent::tearDown();
    }

    public function test_a_checkout_with_no_build_uses_the_fallback(): void
    {
        $this->assertFalse(FrontendAssets::viteReadyIn($this->publicDir));
    }

    public function test_a_complete_build_is_used(): void
    {
        $this->writeBuild();

        $this->assertTrue(FrontendAssets::viteReadyIn($this->publicDir));
    }

    /**
     * A manifest naming a chunk that is not on disk is worse than no manifest:
     * @vite() emits the tags and every one of them 404s.
     */
    public function test_a_manifest_naming_a_missing_chunk_uses_the_fallback(): void
    {
        $this->writeBuild(writeChunks: false);

        $this->assertFalse(FrontendAssets::viteReadyIn($this->publicDir));
    }

    public function test_an_unreadable_manifest_uses_the_fallback(): void
    {
        mkdir($this->publicDir . '/build', 0777, true);
        file_put_contents($this->publicDir . '/build/manifest.json', 'not json');

        $this->assertFalse(FrontendAssets::viteReadyIn($this->publicDir));
    }

    /**
     * The regression this class exists for: the dev server is gone, so its hot
     * file is ignored and cleaned up, and the build is used instead.
     */
    public function test_a_hot_file_whose_dev_server_is_gone_is_ignored_and_removed(): void
    {
        $this->writeBuild();
        file_put_contents($this->publicDir . '/hot', 'http://127.0.0.1:' . $this->closedPort());

        $this->assertTrue(FrontendAssets::viteReadyIn($this->publicDir));
        $this->assertFileDoesNotExist($this->publicDir . '/hot');
    }

    public function test_a_stale_hot_file_with_no_build_falls_back_rather_than_pointing_at_a_dead_port(): void
    {
        file_put_contents($this->publicDir . '/hot', 'http://127.0.0.1:' . $this->closedPort());

        $this->assertFalse(FrontendAssets::viteReadyIn($this->publicDir));
        $this->assertFileDoesNotExist($this->publicDir . '/hot');
    }

    public function test_an_empty_hot_file_is_treated_as_stale(): void
    {
        file_put_contents($this->publicDir . '/hot', '');

        $this->assertFalse(FrontendAssets::viteReadyIn($this->publicDir));
    }

    /**
     * The other side of the coin: a dev server that really is listening must
     * still win, and keep its hot file.
     */
    public function test_a_running_dev_server_is_used_and_its_hot_file_is_kept(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, 'could not open a local listener: ' . $errstr);

        $address = stream_socket_get_name($server, false);
        file_put_contents($this->publicDir . '/hot', 'http://' . $address);

        try {
            $this->assertTrue(FrontendAssets::viteReadyIn($this->publicDir));
            $this->assertFileExists($this->publicDir . '/hot');
        } finally {
            fclose($server);
        }
    }

    /** A build directory as `npm run build` leaves it. */
    private function writeBuild(bool $writeChunks = true): void
    {
        mkdir($this->publicDir . '/build/assets', 0777, true);

        file_put_contents($this->publicDir . '/build/manifest.json', json_encode([
            'resources/css/app.css' => ['file' => 'assets/app-test.css', 'isEntry' => true],
            'resources/js/app.js' => ['file' => 'assets/app-test.js', 'isEntry' => true],
        ]));

        if ($writeChunks) {
            file_put_contents($this->publicDir . '/build/assets/app-test.css', 'main{display:block}');
            file_put_contents($this->publicDir . '/build/assets/app-test.js', '// app');
        }
    }

    /** A port nothing is listening on: bound to learn its number, then released. */
    private function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT);
        fclose($server);

        return $port;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
