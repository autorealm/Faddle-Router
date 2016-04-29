<?php

define('FADDLE_PATH', dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR. 'src');
require FADDLE_PATH . '/Faddle/autoload.php';

use Faddle\Event;
use Faddle\Router\Route;
use Faddle\Router\Routes;
use Faddle\Router\Router as Router;

class App extends Faddle\Faddle {
	private static $_instance;
	public $router = null;
	public $event;

	public function __construct() {
		self::$_instance = $this;
		if (!isset($this->event)) {
			$this->event = Event::build(['start', 'before', 'obtain', 'present', 
				'completed', 'error', 'notfound', 'badrequest', 'next', 'end'],
				function() {
					//
				});
		}
		$this->router = new Router();
		
	}
	
	public function __destruct() {
		$this->onEnd();
	}

	/**
	 * 获取应用实例
	 * @return Novious
	 */
	public static function instance() {
		if (! self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}
	
	public static function getInstance() {
		return static::instance();
	}
	
	/**
	 * 监听应用事件
	 */
	public function on($event, $callback) {
		$this->event->on($event, $callback);
	}
	
	public function emit($event, $args) {
		$this->event->trigger($event, $args);
	}
	
	public function __invoke() {
		$this->onStart();
		$this->router->execute($this);
	}
	
}

$app = new App();
$route = Route::generate(['GET'], '/{name}/{:str}', function($name, $value) {
				
				return "Name:" . $name . "\r\n" . "Value:" . $value;
			})->params(array('name'=>'item'));

$app->router->get('/', function($args) {
    return "Welcome to Faddle!";
});

$app->router->get('/hello/{:value}', function($args) {
    return "Hello, " . $args;
});

$app->router->set($route);

$app->run();

