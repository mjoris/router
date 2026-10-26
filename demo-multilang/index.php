<?php

// In case one is using PHP's built-in server: serve existing files as-is
$filename = __DIR__ . preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI']);
if (php_sapi_name() === 'cli-server' && is_file($filename)) {
    return false;
}

// Include the Router class
// @note: it's recommended to just use the composer autoloader (require 'vendor/autoload.php') instead
require_once __DIR__ . '/../src/Router.php';

/**
 * A Multilingual Router
 */
class MultilangRouter extends \Mjoris\Router\Router
{
    private string $defaultLanguage;

    /**
     * @param string[] $allowedLanguages
     */
    public function __construct(private array $allowedLanguages, string $defaultLanguage)
    {
        $this->defaultLanguage = in_array($defaultLanguage, $allowedLanguages) ? $defaultLanguage : $allowedLanguages[0];

        // Visiting the root? Redirect to the default language index
        $this->match('GET|POST|PUT|DELETE|HEAD', '/', function () {
            header('location: /' . $this->defaultLanguage);
            exit();
        });

        // Create a before handler to make sure the language checks out when visiting anything but the root.
        // If the language doesn't check out, redirect to the default language index
        $this->before('GET|POST|PUT|DELETE|HEAD', '/([a-z0-9_-]+)(/.*)?', function ($language, $slug = null) {
            // The given language does not appear in the array of allowed languages
            if (!in_array($language, $this->allowedLanguages)) {
                header('location: /' . $this->defaultLanguage);
                exit();
            }
        });
    }
}

// Create a Router
$router = new MultilangRouter(
    ['en', 'nl', 'fr'], //= allowed languages
    'nl', // = default language
);

$router->get('/([a-z0-9_-]+)', function ($language) {
    exit('This is the ' . htmlentities($language) . ' index');
});

$router->get('/([a-z0-9_-]+)/([a-z0-9_-]+)', function ($language, $slug) {
    exit('This is the ' . htmlentities($language) . ' version of ' . htmlentities($slug));
});

$router->get('/([a-z0-9_-]+)/(.*)', function ($language, $slug) {
    exit('This is the ' . htmlentities($language) . ' version of ' . htmlentities($slug) . ' (multiple segments allowed)');
});

// Thunderbirds are go!
$router->run();
