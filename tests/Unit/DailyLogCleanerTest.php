<?php

namespace Chencongbao\Foundation\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Chencongbao\Foundation\Services\Logging\DailyLogCleaner;

final class DailyLogCleanerTest extends TestCase
{
    /** @var array<int, string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeTestDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_it_removes_all_logs_inside_expired_date_directories(): void
    {
        $root = $this->temporaryDirectory();
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $expired = $today->modify('-3 days')->format('Y-m-d');
        $oldestRetained = $today->modify('-2 days')->format('Y-m-d');

        $this->put($root.'/'.$expired.'/foundation/exception.log', 'foundation');
        $this->put($root.'/'.$expired.'/payment/payment.log', 'payment');
        $this->put($root.'/'.$expired.'/laravel.log', 'laravel');
        $this->put($root.'/'.$oldestRetained.'/foundation/exception.log', 'keep');
        $this->put($root.'/archive/readme.txt', 'keep');
        $this->put($root.'/laravel.log', 'keep');

        $deleted = (new DailyLogCleaner([
            'days' => 3,
            'path' => $root,
        ]))->cleanup();

        self::assertSame(1, $deleted);
        self::assertDirectoryDoesNotExist($root.'/'.$expired);
        self::assertFileExists($root.'/'.$oldestRetained.'/foundation/exception.log');
        self::assertFileExists($root.'/archive/readme.txt');
        self::assertFileExists($root.'/laravel.log');
    }

    public function test_zero_days_disables_cleanup(): void
    {
        $root = $this->temporaryDirectory();
        $date = (new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai')))
            ->modify('-100 days')
            ->format('Y-m-d');
        $this->put($root.'/'.$date.'/app.log', 'keep');

        $deleted = (new DailyLogCleaner([
            'days' => 0,
            'path' => $root,
        ]))->cleanup();

        self::assertSame(0, $deleted);
        self::assertFileExists($root.'/'.$date.'/app.log');
    }

    public function test_it_ignores_invalid_dates_and_date_symlinks(): void
    {
        $root = $this->temporaryDirectory();
        $invalid = '2026-02-30';
        $this->put($root.'/'.$invalid.'/app.log', 'keep');

        $target = $this->temporaryDirectory();
        $this->put($target.'/important.log', 'keep');
        $linkDate = '2000-01-01';
        $symlinkCreated = function_exists('symlink') && @symlink($target, $root.'/'.$linkDate);

        (new DailyLogCleaner([
            'days' => 1,
            'path' => $root,
        ]))->cleanup();

        self::assertFileExists($root.'/'.$invalid.'/app.log');
        self::assertFileExists($target.'/important.log');
        if ($symlinkCreated) {
            self::assertTrue(is_link($root.'/'.$linkDate));
        }
    }

    public function test_it_runs_once_per_day_but_reruns_when_retention_changes(): void
    {
        $root = $this->temporaryDirectory();
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Shanghai'));
        $firstDate = $today->modify('-5 days')->format('Y-m-d');
        $secondDate = $today->modify('-2 days')->format('Y-m-d');
        $this->put($root.'/'.$firstDate.'/first.log', 'delete');

        $cleaner = new DailyLogCleaner(['days' => 3, 'path' => $root]);
        self::assertSame(1, $cleaner->cleanup());

        $this->put($root.'/'.$firstDate.'/created-after-cleanup.log', 'keep-until-next-run');
        self::assertSame(0, $cleaner->cleanup());
        self::assertFileExists($root.'/'.$firstDate.'/created-after-cleanup.log');

        $this->put($root.'/'.$secondDate.'/second.log', 'delete-after-setting-change');
        self::assertSame(2, (new DailyLogCleaner(['days' => 2, 'path' => $root]))->cleanup());
        self::assertDirectoryDoesNotExist($root.'/'.$firstDate);
        self::assertDirectoryDoesNotExist($root.'/'.$secondDate);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/foundation-log-cleaner-'.bin2hex(random_bytes(8));
        mkdir($directory, 0777, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function put(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    private function removeTestDirectory(string $directory): void
    {
        if (!str_starts_with($directory, sys_get_temp_dir().'/foundation-log-cleaner-')) {
            return;
        }
        if (is_link($directory)) {
            unlink($directory);

            return;
        }
        if (!is_dir($directory)) {
            return;
        }

        foreach ((array) scandir($directory) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->removeTestDirectory($path);
            }
        }
        @rmdir($directory);
    }
}
