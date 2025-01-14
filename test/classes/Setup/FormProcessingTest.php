<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Setup;

use PhpMyAdmin\Config\FormDisplay;
use PhpMyAdmin\Exceptions\ExitException;
use PhpMyAdmin\Setup\FormProcessing;
use PhpMyAdmin\Tests\AbstractNetworkTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function ob_get_clean;
use function ob_start;

#[CoversClass(FormProcessing::class)]
class FormProcessingTest extends AbstractNetworkTestCase
{
    /**
     * Prepares environment for the test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        parent::setLanguage();

        $GLOBALS['server'] = 1;
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        $GLOBALS['cfg']['ServerDefault'] = 1;
    }

    /**
     * Test for process_formset()
     */
    public function testProcessFormSet(): void
    {
        $this->mockResponse(
            [['status: 303 See Other'], ['Location: ../setup/index.php?route=%2Fsetup&lang=en'], 303],
        );

        // case 1
        $formDisplay = $this->getMockBuilder(FormDisplay::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process', 'getDisplay'])
            ->getMock();

        $formDisplay->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn(false);

        $formDisplay->expects($this->once())
            ->method('getDisplay');

        FormProcessing::process($formDisplay);

        // case 2
        $formDisplay = $this->getMockBuilder(FormDisplay::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process', 'hasErrors', 'displayErrors'])
            ->getMock();

        $formDisplay->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn(true);

        $formDisplay->expects($this->once())
            ->method('hasErrors')
            ->with()
            ->willReturn(true);

        ob_start();
        FormProcessing::process($formDisplay);
        $result = ob_get_clean();

        $this->assertIsString($result);

        $this->assertStringContainsString('<div class="error">', $result);

        $this->assertStringContainsString('mode=revert', $result);

        $this->assertStringContainsString('<a class="btn" href="../setup/index.php?route=/setup&', $result);

        $this->assertStringContainsString('mode=edit', $result);

        // case 3
        $formDisplay = $this->getMockBuilder(FormDisplay::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['process', 'hasErrors'])
            ->getMock();

        $formDisplay->expects($this->once())
            ->method('process')
            ->with(false)
            ->willReturn(true);

        $formDisplay->expects($this->once())
            ->method('hasErrors')
            ->with()
            ->willReturn(false);

        $this->expectException(ExitException::class);
        FormProcessing::process($formDisplay);
    }
}
