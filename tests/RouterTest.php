<?php

declare(strict_types=1);

namespace Mjoris\Router\Tests;

use Mjoris\Router\Router;
use Mjoris\Router\Tests\Fixtures\TestController;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        // Pretend the entry script is /index.php, because the router uses SCRIPT_NAME to guess the subfolder it runs in
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        unset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }

    /**
     * Fake a request to $uri, run the router and return everything it echoed.
     */
    private function request(Router $router, string $uri, string $method = 'GET', ?callable $callback = null): string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = $method;

        ob_start();
        try {
            $router->run($callback);
        } finally {
            $output = ob_get_clean();
        }

        return $output;
    }

    public function testInit(): void
    {
        $this->assertInstanceOf(Router::class, new Router());
    }

    public function testUri(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/sub/folder/index.php';
        $_SERVER['REQUEST_URI'] = '/sub/folder/about/whatever';

        $this->assertSame('/about/whatever', (new Router())->getCurrentUri());
    }

    public function testUriIgnoresQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/about?foo=bar';

        $this->assertSame('/about', (new Router())->getCurrentUri());
    }

    public function testBasePathOverride(): void
    {
        $router = new Router();
        $router->get('/about', function () {
            echo 'about';
        });

        $_SERVER['SCRIPT_NAME'] = '/public/index.php';
        $router->setBasePath('/');

        $this->assertSame('/', $router->getBasePath());
        $this->assertSame('about', $this->request($router, '/about'));
    }

    public function testBasePathThatContainsEmoji(): void
    {
        $router = new Router();
        $router->get('/about', function () {
            echo 'about';
        });

        $_SERVER['SCRIPT_NAME'] = '/sub/folder/💩/index.php';

        $this->assertSame('about', $this->request($router, '/sub/folder/%F0%9F%92%A9/about'));
    }

    public function testStaticRoute(): void
    {
        $router = new Router();
        $router->match('GET', '/about', function () {
            echo 'about';
        });

        $this->assertSame('about', $this->request($router, '/about'));
    }

    public function testStaticRouteUsingShorthand(): void
    {
        $router = new Router();
        $router->get('/about', function () {
            echo 'about';
        });

        $this->assertSame('about', $this->request($router, '/about'));
    }

    public function testRunReturnsWhetherARouteWasHandled(): void
    {
        $router = new Router();
        $router->get('/', function () {});

        $_SERVER['REQUEST_URI'] = '/';
        $this->assertTrue($router->run());

        $_SERVER['REQUEST_URI'] = '/nope';
        $this->assertFalse($router->run());
    }

    public function testRequestMethods(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'get';
        });
        $router->post('/', function () {
            echo 'post';
        });
        $router->put('/', function () {
            echo 'put';
        });
        $router->patch('/', function () {
            echo 'patch';
        });
        $router->delete('/', function () {
            echo 'delete';
        });
        $router->options('/', function () {
            echo 'options';
        });

        $this->assertSame('get', $this->request($router, '/', 'GET'));
        $this->assertSame('post', $this->request($router, '/', 'POST'));
        $this->assertSame('put', $this->request($router, '/', 'PUT'));
        $this->assertSame('patch', $this->request($router, '/', 'PATCH'));
        $this->assertSame('delete', $this->request($router, '/', 'DELETE'));
        $this->assertSame('options', $this->request($router, '/', 'OPTIONS'));

        // HEAD is handled as GET, but without any output
        $this->assertSame('', $this->request($router, '/', 'HEAD'));
    }

    public function testShorthandAll(): void
    {
        $router = new Router();
        $router->all('/', function () {
            echo 'all';
        });

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH'] as $method) {
            $this->assertSame('all', $this->request($router, '/', $method));
        }
        $this->assertSame('', $this->request($router, '/', 'HEAD'));
    }

    public function testDynamicRoute(): void
    {
        $router = new Router();
        $router->get('/hello/(\w+)', function ($name) {
            echo 'Hello ' . $name;
        });

        $this->assertSame('Hello bramus', $this->request($router, '/hello/bramus'));
    }

    public function testDynamicRouteWithMultiple(): void
    {
        $router = new Router();
        $router->get('/hello/(\w+)/(\w+)', function ($name, $lastname) {
            echo 'Hello ' . $name . ' ' . $lastname;
        });

        $this->assertSame('Hello bramus sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function testDynamicRouteWithTypedParameter(): void
    {
        $router = new Router();
        $router->get('/movies/(\d+)', function (int $id) {
            echo 'movie ' . ($id + 1);
        });

        $this->assertSame('movie 42', $this->request($router, '/movies/41'));
    }

    public function testCurlyBracesRoutes(): void
    {
        $router = new Router();
        $router->get('/hello/{name}/{lastname}', function ($name, $lastname) {
            echo 'Hello ' . $name . ' ' . $lastname;
        });

        $this->assertSame('Hello bramus sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function testCurlyBracesRoutesWithNonAZCharsInPlaceholderNames(): void
    {
        $router = new Router();
        $router->get('/hello/{arg1}/{arg2}', function ($arg1, $arg2) {
            echo 'Hello ' . $arg1 . ' ' . $arg2;
        });

        $this->assertSame('Hello bramus sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function testCurlyBracesRoutesWithCyrillicCharactersInPlaceholderNames(): void
    {
        $router = new Router();
        $router->get('/hello/{това}/{това}', function ($arg1, $arg2) {
            echo 'Hello ' . $arg1 . ' ' . $arg2;
        });

        $this->assertSame('Hello bramus sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function testCurlyBracesRoutesWithEmojiInPlaceholderNames(): void
    {
        $router = new Router();
        $router->get('/hello/{😂}/{😅}', function ($arg1, $arg2) {
            echo 'Hello ' . $arg1 . ' ' . $arg2;
        });

        $this->assertSame('Hello bramus sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function testCurlyBracesWithCyrillicCharacters(): void
    {
        $router = new Router();
        $router->get('/bg/{arg}', function ($arg) {
            echo 'BG: ' . $arg;
        });

        $this->assertSame('BG: това', $this->request($router, '/bg/това'));
    }

    public function testCurlyBracesWithMultipleCyrillicCharacters(): void
    {
        $router = new Router();
        $router->get('/bg/{arg}/{arg}', function ($arg1, $arg2) {
            echo 'BG: ' . $arg1 . ' - ' . $arg2;
        });

        $this->assertSame('BG: това - слъг', $this->request($router, '/bg/това/слъг'));
    }

    public function testCurlyBracesWithEmoji(): void
    {
        $router = new Router();
        $router->get('/emoji/{emoji}', function ($emoji) {
            echo 'Emoji: ' . $emoji;
        });

        $this->assertSame('Emoji: 💩', $this->request($router, '/emoji/%F0%9F%92%A9'));
    }

    public function testCurlyBracesWithEmojiCombinedWithBasePathThatContainsEmoji(): void
    {
        $router = new Router();
        $router->get('/emoji/{emoji}', function ($emoji) {
            echo 'Emoji: ' . $emoji;
        });

        $_SERVER['SCRIPT_NAME'] = '/sub/folder/💩/index.php';

        $this->assertSame('Emoji: 🤯', $this->request($router, '/sub/folder/%F0%9F%92%A9/emoji/%F0%9F%A4%AF'));
    }

    public function testDynamicRouteWithOptionalSubpatterns(): void
    {
        $router = new Router();
        $router->get('/hello(/\w+)?', function ($name = null) {
            echo 'Hello ' . ($name ?: 'stranger');
        });

        $this->assertSame('Hello stranger', $this->request($router, '/hello'));
        $this->assertSame('Hello bramus', $this->request($router, '/hello/bramus'));
    }

    public function testDynamicRouteWithMultipleSubpatterns(): void
    {
        $router = new Router();
        $router->get('/(.*)/page([0-9]+)', function ($place, $page) {
            echo 'Hello ' . $place . ' page : ' . $page;
        });

        $this->assertSame('Hello hello/bramus page : 3', $this->request($router, '/hello/bramus/page3'));
    }

    public function testDynamicRouteWithOptionalNestedSubpatterns(): void
    {
        $router = new Router();
        $router->get('/blog(/\d{4}(/\d{2}(/\d{2}(/[a-z0-9_-]+)?)?)?)?', function ($year = null, $month = null, $day = null, $slug = null) {
            if ($year === null) {
                echo 'Blog overview';
            } elseif ($month === null) {
                echo 'Blog year overview (' . $year . ')';
            } elseif ($day === null) {
                echo 'Blog month overview (' . $year . '-' . $month . ')';
            } elseif ($slug === null) {
                echo 'Blog day overview (' . $year . '-' . $month . '-' . $day . ')';
            } else {
                echo 'Blogpost ' . htmlentities($slug) . ' detail (' . $year . '-' . $month . '-' . $day . ')';
            }
        });

        $this->assertSame('Blog overview', $this->request($router, '/blog'));
        $this->assertSame('Blog year overview (1983)', $this->request($router, '/blog/1983'));
        $this->assertSame('Blog month overview (1983-12)', $this->request($router, '/blog/1983/12'));
        $this->assertSame('Blog day overview (1983-12-26)', $this->request($router, '/blog/1983/12/26'));
        $this->assertSame('Blogpost bramus detail (1983-12-26)', $this->request($router, '/blog/1983/12/26/bramus'));
    }

    public function testDynamicRouteWithNestedOptionalSubpatterns(): void
    {
        $router = new Router();
        $router->get('/hello(/\w+(/\w+)?)?', function ($name1 = null, $name2 = null) {
            echo 'Hello ' . ($name1 ?: 'stranger') . ' ' . ($name2 ?: 'stranger');
        });

        $this->assertSame('Hello bramus stranger', $this->request($router, '/hello/bramus'));
        $this->assertSame('Hello bramus bramus', $this->request($router, '/hello/bramus/bramus'));
    }

    public function testDynamicRouteWithWildcard(): void
    {
        $router = new Router();
        $router->get('(.*)', function ($name) {
            echo 'Hello ' . $name;
        });

        $this->assertSame('Hello hello/bramus', $this->request($router, '/hello/bramus'));
    }

    public function testDynamicRouteWithPartialWildcard(): void
    {
        $router = new Router();
        $router->get('/hello/(.*)', function ($name) {
            echo 'Hello ' . $name;
        });

        $this->assertSame('Hello bramus/sumarb', $this->request($router, '/hello/bramus/sumarb'));
    }

    public function test404(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'home';
        });
        $router->set404(function () {
            echo 'route not found';
        });
        $router->set404('/api(/.*)?', function () {
            echo 'api route not found';
        });

        $this->assertSame('home', $this->request($router, '/'));
        $this->assertSame('route not found', $this->request($router, '/foo'));
        $this->assertSame('api route not found', $this->request($router, '/api/getUser'));
    }

    public function test404WithoutHandler(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'home';
        });

        $this->assertSame('', $this->request($router, '/foo'));
    }

    public function test404WithClassAtMethod(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'home';
        });
        $router->set404(TestController::class . '@notFound');

        $this->assertSame('home', $this->request($router, '/'));
        $this->assertSame('route not found', $this->request($router, '/foo'));
    }

    public function test404WithManualTrigger(): void
    {
        $router = new Router();
        $router->get('/', function () use ($router) {
            $router->trigger404();
        });
        $router->set404(function () {
            echo 'route not found';
        });

        $this->assertSame('route not found', $this->request($router, '/'));
    }

    public function testBeforeRouterMiddleware(): void
    {
        $router = new Router();
        $router->before('GET|POST', '/.*', function () {
            echo 'before ';
        });
        $router->get('/', function () {
            echo 'root';
        });
        $router->get('/about', function () {
            echo 'about';
        });
        $router->get('/contact', function () {
            echo 'contact';
        });
        $router->post('/post', function () {
            echo 'post';
        });

        $this->assertSame('before root', $this->request($router, '/'));
        $this->assertSame('before about', $this->request($router, '/about'));
        $this->assertSame('before contact', $this->request($router, '/contact'));
        $this->assertSame('before post', $this->request($router, '/post', 'POST'));
    }

    public function testBeforeRouterMiddlewareForAllMethods(): void
    {
        $router = new Router();
        $router->before('*', '/.*', function () {
            echo 'before ';
        });
        $router->all('/', function () {
            echo 'root';
        });

        $this->assertSame('before root', $this->request($router, '/', 'GET'));
        $this->assertSame('before root', $this->request($router, '/', 'DELETE'));
    }

    public function testAfterRouterMiddleware(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'home';
        });

        $output = $this->request($router, '/', 'GET', function () {
            echo 'finished';
        });

        $this->assertSame('homefinished', $output);
    }

    public function testBasicController(): void
    {
        $router = new Router();
        $router->get('/show/(.*)', TestController::class . '@show');

        $this->assertSame('foo', $this->request($router, '/show/foo'));
    }

    public function testControllerWithTypedParameter(): void
    {
        $router = new Router();
        $router->get('/number/(\d+)', TestController::class . '@showNumber');

        $this->assertSame('number 7', $this->request($router, '/number/7'));
    }

    public function testDefaultNamespace(): void
    {
        $router = new Router();
        $router->setNamespace('\Mjoris\Router\Tests\Fixtures');
        $router->get('/show/(.*)', 'TestController@show');

        $this->assertSame('\Mjoris\Router\Tests\Fixtures', $router->getNamespace());
        $this->assertSame('foo', $this->request($router, '/show/foo'));
    }

    public function testControllerMethodReturningFalse(): void
    {
        $router = new Router();
        $router->get('/false', TestController::class . '@returnFalse');
        $router->get('/static-false', TestController::class . '@staticReturnFalse');

        $this->assertSame('returnFalse', $this->request($router, '/false'));
        $this->assertSame('staticReturnFalse', $this->request($router, '/static-false'));
    }

    public function testUnknownControllerThrowsError(): void
    {
        $router = new Router();
        $router->get('/', 'DoesNotExistController@show');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Controller class 'DoesNotExistController' not found");

        $this->request($router, '/');
    }

    public function testUnknownControllerMethodThrowsError(): void
    {
        $router = new Router();
        $router->get('/', TestController::class . '@doesNotExist');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Method 'doesNotExist' not found in controller class '" . TestController::class . "'");

        $this->request($router, '/');
    }

    public function testPrivateControllerMethodThrowsError(): void
    {
        $router = new Router();
        $router->get('/', TestController::class . '@secret');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('must be public');

        $this->request($router, '/');
    }

    public function testHandlerThatIsNoCallableAndNoControllerThrowsError(): void
    {
        $router = new Router();
        $router->get('/', 'not_a_function');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage("Route handler 'not_a_function' is not callable");

        $this->request($router, '/');
    }

    public function testUnknown404ControllerThrowsError(): void
    {
        $router = new Router();
        $router->get('/', function () {});
        $router->set404('DoesNotExistController@notFound');

        $this->expectException(\Error::class);

        $this->request($router, '/foo');
    }

    public function testSubfolders(): void
    {
        $router = new Router();
        $router->get('/', function () {
            echo 'home';
        });

        $_SERVER['SCRIPT_NAME'] = '/about/index.php';

        $this->assertSame('home', $this->request($router, '/about/'));
    }

    public function testSubrouteMounting(): void
    {
        $router = new Router();
        $router->mount('/movies', function () use ($router) {
            $router->get('/', function () {
                echo 'overview';
            });
            $router->get('/(\d+)', function ($id) {
                echo htmlentities($id);
            });
        });

        $this->assertSame('overview', $this->request($router, '/movies'));
        $this->assertSame('1', $this->request($router, '/movies/1'));
    }

    public function testHttpMethodOverride(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';

        $this->assertSame('PUT', (new Router())->getRequestMethod());
    }

    public function testRequestHeadersFromServerVariables(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'nl-BE';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $headers = (new Router())->getRequestHeaders();

        $this->assertSame('nl-BE', $headers['Accept-Language']);
        $this->assertSame('application/json', $headers['Content-Type']);

        unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['CONTENT_TYPE']);
    }

    public function testWarningWhenRunIsForgotten(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $router = new Router();
            $router->get('/', function () {});
            unset($router); // destroys the router, just like PHP does at the end of the script
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('did you forget to call $router->run()', $warnings[0]);
    }

    public function testNoWarningWhenRunWasCalled(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;

            return true;
        }, E_USER_WARNING);

        try {
            $router = new Router();
            $router->get('/', function () {});
            $this->request($router, '/');
            unset($router);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings);
    }
}
