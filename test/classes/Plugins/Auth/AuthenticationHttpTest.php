<?php

declare(strict_types=1);

namespace PhpMyAdmin\Tests\Plugins\Auth;

use PhpMyAdmin\DatabaseInterface;
use PhpMyAdmin\Exceptions\ExitException;
use PhpMyAdmin\Header;
use PhpMyAdmin\Plugins\Auth\AuthenticationHttp;
use PhpMyAdmin\ResponseRenderer;
use PhpMyAdmin\Tests\AbstractNetworkTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Throwable;

use function base64_encode;
use function ob_get_clean;
use function ob_start;

#[CoversClass(AuthenticationHttp::class)]
class AuthenticationHttpTest extends AbstractNetworkTestCase
{
    protected AuthenticationHttp $object;

    /**
     * Configures global environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        parent::setGlobalConfig();

        parent::setTheme();

        $GLOBALS['dbi'] = $this->createDatabaseInterface();
        $GLOBALS['cfg']['Servers'] = [];
        $GLOBALS['server'] = 0;
        $GLOBALS['db'] = 'db';
        $GLOBALS['table'] = 'table';
        $GLOBALS['lang'] = 'en';
        $GLOBALS['text_dir'] = 'ltr';
        $GLOBALS['token_provided'] = true;
        $GLOBALS['token_mismatch'] = false;
        $this->object = new AuthenticationHttp();
    }

    /**
     * tearDown for test cases
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        unset($this->object);
    }

    public function doMockResponse(int $setMinimal, int $bodyId, int $setTitle, mixed ...$headers): void
    {
        $mockHeader = $this->getMockBuilder(Header::class)
            ->disableOriginalConstructor()
            ->onlyMethods(
                ['setBodyId', 'setTitle', 'disableMenuAndConsole'],
            )
            ->getMock();

        $mockHeader->expects($this->exactly($bodyId))
            ->method('setBodyId')
            ->with('loginform');

        $mockHeader->expects($this->exactly($setTitle))
            ->method('setTitle')
            ->with('Access denied!');

        $mockHeader->expects($this->exactly($setTitle))
            ->method('disableMenuAndConsole')
            ->with();

        // set mocked headers and footers
        $mockResponse = $this->mockResponse($headers);

        $mockResponse->expects($this->exactly($setMinimal))
            ->method('setMinimalFooter')
            ->with();

        $mockResponse->expects($this->exactly($setTitle))
            ->method('getHeader')
            ->with()
            ->willReturn($mockHeader);

        if (! empty($_REQUEST['old_usr'])) {
            $this->object->logOut();
        } else {
            $this->expectException(ExitException::class);
            $this->object->showLoginForm();
        }
    }

    public function testAuthLogoutUrl(): void
    {
        $_REQUEST['old_usr'] = '1';
        $GLOBALS['cfg']['Server']['LogoutURL'] = 'https://example.com/logout';

        $this->doMockResponse(
            0,
            0,
            0,
            ['Location: https://example.com/logout'],
        );
    }

    public function testAuthVerbose(): void
    {
        $_REQUEST['old_usr'] = '';
        $GLOBALS['cfg']['Server']['verbose'] = 'verboseMessagê';

        $this->doMockResponse(
            1,
            1,
            1,
            ['WWW-Authenticate: Basic realm="phpMyAdmin verboseMessag"'],
            ['status: 401 Unauthorized'],
            401,
        );
    }

    public function testAuthHost(): void
    {
        $GLOBALS['cfg']['Server']['verbose'] = '';
        $GLOBALS['cfg']['Server']['host'] = 'hòst';

        $this->doMockResponse(
            1,
            1,
            1,
            ['WWW-Authenticate: Basic realm="phpMyAdmin hst"'],
            ['status: 401 Unauthorized'],
            401,
        );
    }

    public function testAuthRealm(): void
    {
        $GLOBALS['cfg']['Server']['host'] = '';
        $GLOBALS['cfg']['Server']['auth_http_realm'] = 'rêäealmmessage';

        $this->doMockResponse(
            1,
            1,
            1,
            ['WWW-Authenticate: Basic realm="realmmessage"'],
            ['status: 401 Unauthorized'],
            401,
        );
    }

    /**
     * @param string      $user           test username
     * @param string      $pass           test password
     * @param string      $userIndex      index to test username against
     * @param string      $passIndex      index to test username against
     * @param string|bool $expectedReturn expected return value from test
     * @param string      $expectedUser   expected username to be set
     * @param string|bool $expectedPass   expected password to be set
     * @param string|bool $oldUsr         value for $_REQUEST['old_usr']
     */
    #[DataProvider('readCredentialsProvider')]
    public function testAuthCheck(
        string $user,
        string $pass,
        string $userIndex,
        string $passIndex,
        string|bool $expectedReturn,
        string $expectedUser,
        string|bool $expectedPass,
        string|bool $oldUsr = '',
    ): void {
        $_SERVER[$userIndex] = $user;
        $_SERVER[$passIndex] = $pass;

        $_REQUEST['old_usr'] = $oldUsr;

        $this->assertEquals(
            $expectedReturn,
            $this->object->readCredentials(),
        );

        $this->assertEquals($expectedUser, $this->object->user);

        $this->assertEquals($expectedPass, $this->object->password);

        unset($_SERVER[$userIndex]);
        unset($_SERVER[$passIndex]);
    }

    /**
     * @return array<array{
     *     0: string, 1: string, 2: string, 3: string, 4: string|bool, 5: string, 6: string|bool, 7?: string|bool
     * }>
     */
    public static function readCredentialsProvider(): array
    {
        return [
            ['Basic ' . base64_encode('foo:bar'), 'pswd', 'PHP_AUTH_USER', 'PHP_AUTH_PW', false, '', 'bar', 'foo'],
            [
                'Basic ' . base64_encode('foobar'),
                'pswd',
                'REMOTE_USER',
                'REMOTE_PASSWORD',
                true,
                'Basic Zm9vYmFy',
                'pswd',
            ],
            ['Basic ' . base64_encode('foobar:'), 'pswd', 'AUTH_USER', 'AUTH_PASSWORD', true, 'foobar', false],
            [
                'Basic ' . base64_encode(':foobar'),
                'pswd',
                'HTTP_AUTHORIZATION',
                'AUTH_PASSWORD',
                true,
                'Basic OmZvb2Jhcg==',
                'pswd',
            ],
            ['BasicTest', 'pswd', 'Authorization', 'AUTH_PASSWORD', true, 'BasicTest', 'pswd'],
        ];
    }

    public function testAuthSetUser(): void
    {
        // case 1

        $this->object->user = 'testUser';
        $this->object->password = 'testPass';
        $GLOBALS['server'] = 2;
        $GLOBALS['cfg']['Server']['user'] = 'testUser';

        $this->assertTrue(
            $this->object->storeCredentials(),
        );

        $this->assertEquals('testUser', $GLOBALS['cfg']['Server']['user']);

        $this->assertEquals('testPass', $GLOBALS['cfg']['Server']['password']);

        $this->assertArrayNotHasKey('PHP_AUTH_PW', $_SERVER);

        $this->assertEquals(2, $GLOBALS['server']);

        // case 2
        $this->object->user = 'testUser';
        $this->object->password = 'testPass';
        $GLOBALS['cfg']['Servers'][1] = ['host' => 'a', 'user' => 'testUser', 'foo' => 'bar'];

        $GLOBALS['cfg']['Server'] = ['host' => 'a', 'user' => 'user2'];

        $this->assertTrue(
            $this->object->storeCredentials(),
        );

        $this->assertEquals(
            ['user' => 'testUser', 'password' => 'testPass', 'host' => 'a'],
            $GLOBALS['cfg']['Server'],
        );

        $this->assertEquals(2, $GLOBALS['server']);

        // case 3
        $GLOBALS['server'] = 3;
        $this->object->user = 'testUser';
        $this->object->password = 'testPass';
        $GLOBALS['cfg']['Servers'][1] = ['host' => 'a', 'user' => 'testUsers', 'foo' => 'bar'];

        $GLOBALS['cfg']['Server'] = ['host' => 'a', 'user' => 'user2'];

        $this->assertTrue(
            $this->object->storeCredentials(),
        );

        $this->assertEquals(
            ['user' => 'testUser', 'password' => 'testPass', 'host' => 'a'],
            $GLOBALS['cfg']['Server'],
        );

        $this->assertEquals(3, $GLOBALS['server']);
    }

    #[Group('medium')]
    #[RunInSeparateProcess]
    public function testAuthFails(): void
    {
        $GLOBALS['cfg']['Server']['host'] = '';
        $_REQUEST = [];
        ResponseRenderer::getInstance()->setAjax(false);

        $dbi = $this->getMockBuilder(DatabaseInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $dbi->expects($this->exactly(3))
            ->method('getError')
            ->willReturn('error 123', 'error 321', '');

        $GLOBALS['dbi'] = $dbi;
        $GLOBALS['errno'] = 31;

        ob_start();
        try {
            $this->object->showFailure('');
        } catch (Throwable $throwable) {
        }

        $result = ob_get_clean();

        $this->assertInstanceOf(ExitException::class, $throwable);

        $this->assertIsString($result);

        $this->assertStringContainsString('<p>error 123</p>', $result);

        $this->object = $this->getMockBuilder(AuthenticationHttp::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['authForm'])
            ->getMock();

        $this->object->expects($this->exactly(2))
            ->method('authForm')
            ->willThrowException(new ExitException());
        // case 2
        $GLOBALS['cfg']['Server']['host'] = 'host';
        $GLOBALS['errno'] = 1045;

        try {
            $this->object->showFailure('');
        } catch (ExitException) {
        }

        // case 3
        $GLOBALS['errno'] = 1043;
        $this->expectException(ExitException::class);
        $this->object->showFailure('');
    }
}
