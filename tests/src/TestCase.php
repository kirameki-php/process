<?php declare(strict_types=1);

namespace Tests\Kirameki\Process;

use Kirameki\Core\Testing\TestCase as BaseTestCase;
use function dirname;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return string
     */
    protected function getScriptsDir(): string
    {
        return dirname(__DIR__) . '/scripts';
    }
}
