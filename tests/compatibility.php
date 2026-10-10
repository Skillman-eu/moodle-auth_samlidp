<?php
// Standalone regression checks: php -n tests/compatibility.php.
namespace SimpleSAML\Error {
    class Exception extends \Exception {}
    class BadRequest extends Exception {}
}
namespace SimpleSAML {
    class Configuration {
        public static function getInstance() { return new self(); }
        public function getValue($key) { return $key === 'secretsalt' ? 'test-salt' : '/'; }
    }
    class Module {
        public static function getModuleURL($path, $params) {
            return 'https://idp.example/module.php/' . $path . '?' . http_build_query($params);
        }
    }
}
namespace SimpleSAML\Auth {
    abstract class Source {
        protected $authId;
        public static $source;
        public function __construct($info, $config) { $this->authId = $info['AuthId']; }
        abstract public function authenticate(array &$state): void;
        public function logout(array &$state): void {}
        public static function getById($id) { return self::$source; }
    }
    class State {
        public static function saveState($state, $stage) { return 'test-state'; }
        public static function loadState($id, $stage) { return ['moodle:AuthID' => 'test-source']; }
    }
}
namespace SimpleSAML\Utils {
    class HTTP {
        public static $redirect;
        public function redirectUntrustedURL($url, $params = []) {
            self::$redirect = [$url, $params];
            throw new \RuntimeException('redirect');
        }
    }
}
namespace core_privacy\local\metadata {
    interface provider {}
    class collection {
        public $fields;
        public function add_external_location_link($name, $fields, $description) { $this->fields = $fields; }
    }
}
namespace core_privacy\local\request\plugin {
    interface provider {}
}
namespace {
    set_error_handler(function($severity, $message, $file, $line) {
        if (error_reporting() & $severity) { throw new \ErrorException($message, 0, $severity, $file, $line); }
    });
    error_reporting(E_ALL);
    require __DIR__ . '/../moodle/src/Auth/Source/External.php';
    function check($condition, $message) {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    function expect($class, $call, $message) {
        try { $call(); } catch (\Throwable $error) {
            check($error instanceof $class, $message . ': ' . get_class($error));
            check(str_contains($error->getMessage(), $message), $error->getMessage());
            return;
        }
        throw new \RuntimeException('Expected exception: ' . $message);
    }
    function profile_user_record(int $id, bool $only = true): \stdClass {
        $GLOBALS['profilecalls']++;
        return (object)['department' => 'Teaching'];
    }
    $root = sys_get_temp_dir() . '/samlidp-test-' . bin2hex(random_bytes(6));
    mkdir($root . '/user/profile', 0777, true);
    file_put_contents($root . '/config.php', '<?php');
    file_put_contents($root . '/user/profile/lib.php', '<?php');
    try {
        $config = ['cookie_name' => 'TestCookie', 'moodle_coderoot' => $root,
            'login_url' => 'https://moodle.example/login/index.php?lang=en',
            'logout_url' => 'https://moodle.example/auth/samlidp/logout.php?lang=en'];
        $source = new \SimpleSAML\Module\moodle\Auth\Source\External(['AuthId' => 'test-source'], $config);
        $method = new \ReflectionMethod($source, 'getUser');
        $method->setAccessible(true);
        $_REQUEST = [];
        expect(\SimpleSAML\Error\BadRequest::class, [$source, 'resume'], 'State');
        $_REQUEST = ['State' => []];
        expect(\SimpleSAML\Error\BadRequest::class, [$source, 'resume'], 'State');
        $_REQUEST = ['State' => 'test-state'];
        expect(\SimpleSAML\Error\Exception::class, [$source, 'resume'], 'test-source');
        $_COOKIE = ['TestCookie' => ['bad']];
        expect(\SimpleSAML\Error\BadRequest::class, function() use ($method, $source) {
            $method->invoke($source);
        }, 'Invalid authentication cookie');
        $_COOKIE = ['TestCookie' => 'wrong:42'];
        expect(\SimpleSAML\Error\Exception::class, function() use ($method, $source) {
            $method->invoke($source);
        }, 'Cookie hash invalid');
        $DB = new class {
            public $user = false;
            public function get_record($table, $conditions) { return $this->user; }
            public function get_records($table, $conditions) { return [(object)['shortname' => 'department']]; }
        };
        define('MOODLE_INTERNAL', true);
        require __DIR__ . '/../classes/privacy/provider.php';
        $metadata = \auth_samlidp\privacy\provider::get_metadata(new \core_privacy\local\metadata\collection());
        check($metadata->fields['profile_field_department'] === 'privacy:metadata:profilefield',
            'Privacy metadata must include named custom profile fields');
        $profilecalls = 0;
        $_COOKIE = ['TestCookie' => hash_hmac('sha1', 'test-salt42', 'test-salt') . ':42'];
        $badconfig = $config;
        $badconfig['moodle_coderoot'] = $root . '/missing';
        $badsource = new \SimpleSAML\Module\moodle\Auth\Source\External(['AuthId' => 'test-source'], $badconfig);
        expect(\SimpleSAML\Error\Exception::class, function() use ($method, $badsource) {
            $method->invoke($badsource);
        }, 'cannot require()');
        check($method->invoke($source) === null && $profilecalls === 0, 'Missing user must skip profile lookup');
        foreach (['deleted', 'suspended'] as $flag) {
            $DB->user = (object)['id' => 42, $flag => 1];
            check($method->invoke($source) === null && $profilecalls === 0, 'Disabled user must be rejected');
        }
        $DB->user = (object)['id' => 42, 'username' => 'teacher', 'auth' => 'manual',
            'password' => 'private', 'secret' => 'private'];
        $attributes = $method->invoke($source);
        check($attributes['username'] === ['teacher'] && $attributes['profile_field_department'] === ['Teaching'],
            'Named user and profile attributes must be preserved');
        check(!isset($attributes['auth']) && !isset($attributes['password']) && !isset($attributes['secret']),
            'Credentials must be excluded');
        $_COOKIE = [];
        $state = [];
        expect(\RuntimeException::class, function() use ($source, &$state) { $source->authenticate($state); }, 'redirect');
        [$url, $params] = \SimpleSAML\Utils\HTTP::$redirect;
        check($url === $config['login_url'] && str_contains($params['ReturnTo'], '?State=test-state'),
            'Login must pass the complete return URL once to the URL encoder');
        $state = ['ReturnTo' => 'https://sp.example/logout?one=1&two=2'];
        expect(\RuntimeException::class, function() use ($source, &$state) { $source->logout($state); }, 'redirect');
        check(\SimpleSAML\Utils\HTTP::$redirect === [$config['logout_url'], $state], 'Logout must pass return URL to encoder');
    } finally {
        unlink($root . '/user/profile/lib.php');
        unlink($root . '/config.php');
        rmdir($root . '/user/profile');
        rmdir($root . '/user');
        rmdir($root);
    }
    echo "Compatibility regression checks passed on PHP " . PHP_VERSION . "\n";
}
