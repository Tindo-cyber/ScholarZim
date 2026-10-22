<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Symfony\Component\Process\Process;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    /** The one ChromeDriver for the whole run, shared by every test class. */
    private static ?Process $sharedDriver = null;

    /**
     * Prepare for Dusk test execution.
     *
     * Dusk's own startChromeDriver() starts a new ChromeDriver before every test class and stops it after. On Windows the
     * stopped one can still be holding port 9515 as the next starts, and a class then finds nothing listening ("Failed to
     * connect to localhost:9515") - all of its tests fail at once, whatever they test, and the next class passes. So the
     * driver is started once, waited for until it accepts connections, and stopped when the run ends.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (static::runningInSail()) {
            return;
        }

        if (self::$sharedDriver?->isRunning()) {
            return;
        }

        // The driver's own log is kept beside the application's, so a failure to connect can be read afterwards.
        self::$sharedDriver = static::$chromeProcess = static::buildChromeProcess(['--port=9515', '--log-path=' . dirname(__DIR__) . '/storage/logs/chromedriver.log']);
        self::$sharedDriver->start();

        $ready = false;

        for ($try = 0; $try < 100 && ! $ready; $try++) {
            $socket = @fsockopen('127.0.0.1', 9515, $errno, $error, 0.2);

            if ($socket !== false) {
                fclose($socket);
                $ready = true;
            } elseif (! self::$sharedDriver->isRunning()) {
                break;
            } else {
                usleep(100_000);
            }
        }

        // Say so here, once, rather than letting every test fail with a connection error.
        if (! $ready) {
            throw new \RuntimeException('ChromeDriver did not accept connections on port 9515. '
                . (self::$sharedDriver->isRunning() ? 'It is running but not listening.' : 'It exited: ' . trim(self::$sharedDriver->getErrorOutput() . self::$sharedDriver->getOutput())));
        }

        register_shutdown_function(static fn () => self::$sharedDriver?->stop());
    }

    /**
     * Every browser starts as a tab that has already been used for ScholarZim. A page that is signed in at the very first
     * request - which is what loginAs() gives a test - would otherwise look like one opened after every page was closed,
     * and the session guard (public/assets/js/session-guard.js) would send it to the sign-in page. The sign-in page marks
     * the tab, exactly as it does for a person.
     */
    protected function newBrowser($driver)
    {
        $browser = parent::newBrowser($driver);
        $browser->visit('/login');

        return $browser;
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        $capabilities = DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options);

        if ($this->capturesNetwork()) {
            $capabilities->setCapability('goog:loggingPrefs', ['browser' => 'ALL', 'performance' => 'ALL']);
        }

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            $capabilities
        );
    }

    // ------------------------------------------------------------------ diagnostics --

    /** Whether this test class keeps the network log, so a page that arrives wrong can be examined afterwards. */
    protected function capturesNetwork(): bool
    {
        return false;
    }

    /** The request id of the last page the browser loaded, read from the network log. */
    private ?string $lastDocumentRequest = null;

    /**
     * Call after each page load in a class that captures the network: remembers which request was the page itself, and keeps
     * the browser's copy of its body available for saveDiagnostics().
     */
    protected function noteDocument(Browser $browser): void
    {
        if (! $this->capturesNetwork()) {
            return;
        }

        $this->devTools($browser)->execute('Network.enable', ['maxTotalBufferSize' => 100_000_000, 'maxResourceBufferSize' => 20_000_000]);

        try {
            foreach ($browser->driver->manage()->getLog('performance') as $entry) {
                $event = json_decode($entry['message'], true)['message'] ?? [];

                if (($event['method'] ?? '') === 'Network.responseReceived' && ($event['params']['type'] ?? '') === 'Document') {
                    $this->lastDocumentRequest = $event['params']['requestId'];
                }
            }
        } catch (\Throwable) {
            // No log available: the diagnostics will say so.
        }
    }

    private function devTools(Browser $browser): ChromeDevToolsDriver
    {
        return new ChromeDevToolsDriver($browser->driver);
    }

    /**
     * Writes what is needed to tell where a wrong page went wrong, to storage/logs/dusk-diagnostics/<time>-<label>/:
     * the page's address, the notes given, the browser console, the body the network delivered, and the page as the
     * browser built it. Compare the last two: if the body is already wrong, the page was damaged on its way (the server
     * or the connection); if only the page is wrong, a script or the parser changed it.
     *
     * Login tokens and email addresses are removed; nothing else is altered. Returns the folder written.
     */
    protected function saveDiagnostics(Browser $browser, string $label, array $notes = []): string
    {
        $dir = dirname(__DIR__) . '/storage/logs/dusk-diagnostics/' . gmdate('Ymd-His') . '-' . substr(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? 'page', 0, 60);
        @mkdir($dir, 0777, true);

        $redact = static fn (string $html): string => (string) preg_replace(
            ['/(csrf-token" content=")[^"]*/', '/(name="_token" value=")[^"]*/', '/[\w.+-]+@[\w-]+(\.[\w-]+)+/'],
            ['$1[removed]', '$1[removed]', '[email]'],
            $html
        );

        $lines = ['url: ' . $browser->driver->getCurrentURL(), ...$notes, ''];

        try {
            $lines[] = 'console: ' . json_encode($browser->driver->manage()->getLog('browser'));
        } catch (\Throwable $e) {
            $lines[] = 'console: unavailable (' . $e->getMessage() . ')';
        }

        if ($this->lastDocumentRequest !== null) {
            try {
                $body = $this->devTools($browser)->execute('Network.getResponseBody', ['requestId' => $this->lastDocumentRequest]);
                $text = ! empty($body['base64Encoded']) ? (string) base64_decode($body['body']) : (string) $body['body'];
                file_put_contents($dir . '/network-body.html', $redact($text));
                $lines[] = 'network body: ' . strlen($text) . ' bytes';
            } catch (\Throwable $e) {
                $lines[] = 'network body: unavailable (' . $e->getMessage() . ')';
            }
        } else {
            $lines[] = 'network body: not captured (this test class does not keep the network log)';
        }

        $dom = (string) $browser->script('return document.documentElement.outerHTML;')[0];
        file_put_contents($dir . '/browser-dom.html', $redact($dom));
        $lines[] = 'browser dom: ' . strlen($dom) . ' characters';

        file_put_contents($dir . '/notes.txt', implode(PHP_EOL, $lines) . PHP_EOL);

        return $dir;
    }
}
