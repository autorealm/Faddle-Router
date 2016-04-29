<?php namespace Faddle\Router;

use Exception;
//use Faddle\App as App;
//use Faddle\Http\Request as Request;
//use Faddle\Http\Response as Response;

/**
 * 路由器类
 */
class Router {
	public $routes = array(); //路由数组
	public $blueprints = array(); //蓝图
	protected $domain = false; //路由器域名
	protected $base_path = ''; //路由器基本路径
	protected $middlewares = array(); //路由器中间件数组
	protected $params = array(); //匹配的参数
	protected $callback = null; //匹配的回调
	protected $uses = ''; //匹配的命名空间
	protected $matched_name; //匹配的方法名称
	private static $app;
	private static $query_path = '';
	
	protected static $match_types = array(
			'(\d+)' => array('{:i}', '{:int}', '{:id}', '{:num}'),
			'([0-9A-Za-z\.\-\_]+)'  => array('{:a}', '{:str}', '{:value}', '{:field}'),
			'([0-9A-Fa-f]++)'  => array('{:h}', '{:hex}'),
			'(\w+\d++)' => array('{:v}', '{:xid}', '{:sid}'),
			'([^/]+)' => array('{:any}', '{}'),
			'(.*?)' => array('{:all}')
		);

	/**
	 * 路由器构造函数
	 * @return Router
	 */
	public function __construct($base_path= '', $domain=null) {
		if ($base_path) $this->base_path = trim($base_path);
		if ($domain) $this->domain = $domain;
		if (empty(self::$query_path)) {
			$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
			self::$query_path =  ltrim($uri, $this->base_path);
		}
	}

	private function __clone() {
		//防止外部克隆对象
	}

	/**
	 * 分配路由
	 */
	public function map(array $routes) {
		foreach ($routes as $path => $callback) {
			if (is_string($path))
				$this->route($path, $callback);
			else
				$this->set($callback);
		}
		return $this;
	}
	
	public function __call($method, $args) {
		if (in_array($method, array('get','post','put','delete', 'gets', 'puts'))) {
			$method = strtoupper($method);
			if ($method == 'GETS') $method = ['GET', 'POST'];
			elseif ($method == 'PUTS') $method = ['PUT', 'POST'];
			else $method = [$method];
			if (count($args) < 2) {
				trigger_error(sprintf('参数错误(count eq 2)，当前：%s', count($args)), E_USER_WARNING);
				return false;
			}
			$pattern = $args[0];
			$callback = $args[1];
			if (is_array($callback)) {
				$callback['method'] = $method;
			} else {
				$callback = array(
					'controller' => $callback,
					'method' => $method
				);
			}
			$route = new Route($pattern, $callback);
			$this->set($route);
			return $route;
		}
		
		return $this;
	}
	
	public function __invoke() {
		$this->execute();
	}

	/**
	 * 设置路由或路由集
	 * @param Route|Routes $route 路由
	 */
	public function set($route) {
		if ($route instanceof Route) {
			$this->routes[$route->getRegex()] = $route;
		} elseif ($route instanceof Routes) {
			foreach ($route as $r) {
				$this->routes[$r->getRegex()] = $r;
			}
		}
		return $this;
	}
	
	/**
	 * 设置路由
	 * @param string $pattern 路径，匹配路由规则的表达式
	 * @param Closure|array $callback 回调信息
	 * @param string|array $method 请求方法
	 * @return Router
	 */
	public function route(string $pattern, $callback, $method=null) {
		if (! is_string($pattern)) return $this;
		$pattern = str_replace(array('//', '(', ')'), array('/', '\(', '\)'), $pattern); //替换正则字符
		foreach (static::$match_types as $to => $from) {
			$pattern = str_replace($from, $to, $pattern);
		}
		
		if (! is_null($method)) {
			if (!is_array($method)) $method = (array) $method;
		} else {
			$method = ['POST', 'GET'];
		}
		if (! is_array($callback)) {
			$callback = array(
				'as' => null,
				'controller' => $callback,
				'middleware' => [],
				'method' => $method
			);
		} else {
			//$config = $callback;
		}
		
		$this->routes[$pattern] = $callback;
		return $this;
	}
	
	/**
	 * 组合路由器到路由蓝点
	 * @param string $pattern
	 * @param Router $router
	 */
	public function group($pattern, Router $router=self) {
		if ($router instanceof Router) {
			$this->blueprints[$pattern] = $router;
		}
		return $this;
	}
	
	/**
	 * 开始路由功能
	 * @param Faddle $app
	 */
	public function execute($app=null) {
		if ($app) self::$app = $app;
		if (! self::$app instanceof Faddle\Faddle) {
			trigger_error(sprintf('应用类型错误：%s', gettype($app)), E_USER_WARNING);
			//self::$app = App::instance();
		}
		//在本路由开始之前，通知应用触发本事件。
		self::before_route($this);
		$matched = $this->match();
		if ($matched > 1) {
			//已匹配并转发，未被本路由执行。
			return;
		}
		if ($matched == 1) {
			$this->dispatch();
		}
		//已回应请求，路由完成。
		self::completed();
		
	}

	protected function match() {
		//$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
		$uri = self::$query_path;
		if (($strpos = strpos($uri, '?')) !== false) {
			$uri = substr($uri, 0, $strpos);
		}
		$uri = rtrim($uri, '/');
		if (stripos($uri, '/') !== 0) $uri = '/' . $uri;
		foreach ($this->blueprints as $pattern => $router) { //找到路由蓝点中的匹配根路径的路由
			$pattern = '/' . trim($pattern, '/');
			if (stripos($uri, $pattern) === 0) {
				self::$query_path = substr(self::$query_path, strlen($pattern));
				$router->execute();
				return 2;
			}
		}
		$check = $this->checkdomain();
		if (! $check) { //域名不匹配本路由则404
			self::notfound();
			return 0;
		}
		$request_method = $_SERVER['REQUEST_METHOD'];
		foreach ($this->routes as $pattern => $callback) {
			$pattern = '/^(' . str_replace('/', '\/', $pattern) . ')(\?[^#]*)?(#.*)?$/';
			if (preg_match($pattern, $uri, $params)) {
				$middleware = [];
				if (is_array($callback)) {
					$method = array_key_exists('method', $callback) ? $callback['method'] : 'GET';
					$uses = array_key_exists('use', $callback) ? $callback['use'] : '';
					$name = array_key_exists('as', $callback) ? $callback['as'] : null;
					$middleware = array_key_exists('middleware', $callback) ? $callback['middleware'] : [];
					$callback = array_key_exists('controller', $callback) ? $callback['controller'] : null;
					if (! in_array($request_method, (array) $method)) {
						self::badrequest();
						return 0;
					}
				} elseif ($callback instanceof Route) {
					$route = $callback;
					$uses = $route->uses;
					$name = $route->name;
					$middleware = $route->middlewares;
					if (! $route->matchMethod()) {
						self::badrequest();
						return 0;
					}
				}
				
				unset($params[0]);
				array_shift($params);
				$params = array_map('rawurldecode', $params);
				$this->params = $params;
				$this->middlewares = array_merge($this->middlewares, (array)$middleware);
				$this->uses = $uses;
				$this->matched_name = $name;
				$this->callback = $callback;
				return 1;
			}
		}
		self::notfound();
		return 0;
	}

	protected function dispatch() {
		$data = (array) self::parse();
		$data['args'] = $this->params;
		//Request::data($data); //设置本次请求的参数
		
		self::obtain($this); //已匹配
		
		//调用中间件
		foreach ($this->middlewares as $k => $mw) {
			if ($mw == null) continue;
			if (! is_int($k)) {
				if ($k !== $this->matched_name || ! preg_match($k, $data['path']) {
					continue;
				}
			}
			if (! is_object($mw)) {
				$mw = $this->uses . '\\' . $mw;
				if (class_exists($mw)) $mw = new $mw();
			}
			if (method_exists($mw, 'handle')) {
				$mw->handle(self::$app);
			} else if (is_callable($mw)) {
				call_user_func($mw);
			} else {
				//call_user_func_array($mw, $this);
				trigger_error(sprintf('中间件被忽略：%s@%s', $k, $mv), E_USER_WARNING);
			}
		}
		
		$result = false;
		if (empty($this->callback)) {
			//
		} else if ($this->callback instanceof Route) {
			$route = $this->callback;
			$callback = $route->callback();
			if (!empty($route->params())) //合并路由参数
				$this->params = array_merge($route->params(), $this->params);
			try {
				if (is_callable($callback)) { //分配函数参数
					$params = $route->arrangeFuncArgs($callback, $this->params);
				} elseif (method_exists($callback[0], $callback[1])) {
					$params = $route->arrangeMethodArgs($callback[0], $callback[1], $this->params);
				}
				if ($params === $this->params) { //未匹配函数参数个数
					$result = call_user_func($callback, ($this->params)); //仅返回一个数组参数
				} else {
					$result = call_user_func_array($callback, array_values($params));
				}
			} catch (Exception $e) {
				$type = get_class($e);
				$msg = $e->getMessage();
				$route->onError($msg, $type, $e);
			}
			$route->onAfter();
		} else if ($this->callback instanceof \Closure) {
			$result = call_user_func_array($this->callback, array_values($this->params));
		} else if (is_array($this->callback)) {
			$result = call_user_func_array($this->callback, array_values($this->params));
		} else {
			$calls = explode('@', (string)$this->callback);
			if (count($calls) <= 1) {
				if ($this->params[0]) {
					$calls[1] = $this->params[0];
					unset($this->params[0]);
				} else {
					$calls[1] = 'call';
				}
			}
			$callback = $calls[1];
			try {
				$calls[0] = $this->uses . '\\' . $calls[0];
				if (class_exists($calls[0])) $controller = new $calls[0]();
			} catch (Exception $e) {
				//print $e->getMessage();
			}
			if ($controller and method_exists($controller, $callback)) {
				$result = call_user_func_array(array($controller, $callback), array_values($this->params));
			} else {
				trigger_error(sprintf('控制器调用出错：%s@%s', $calls[0], $calls[1]), E_USER_WARNING);
				$result = false;
			}
		}
		
		if ($result === false) self::error();
		else if (! empty($result)) {
			self::present($result);//将回应
			//Response::instance()->display($result);
		}
		
		return ($result === false) ? false : true;
	}

	/**
	 * 检查路由是否匹配指定的域名
	 *
	 * @param string $domain Set Domain
	 * @return array|boolean arguments
	 */
	private function checkdomain($domain) {
		if (!isset($domain)) $domain = $this->domain;
		if (!empty($domain)) {
			$server = $_SERVER["SERVER_NAME"];
			$domain = (string)$domain;
			if (preg_match($domain, $server, $arguments)) {
				return $arguments;
			}
			return false;
		}
		return true;
	}

	/**
	 * 设置并返回路由中间件
	 * @param string $middleware
	 * @return mixed
	 */
	public function middleware($middleware) {
		if (isset($middleware) and ! in_array($middleware, $this->middlewares)) {
			$this->middlewares[] = $middleware;
			//array_unshift($this->middlewares, $middleware);
		}
		return $this->middlewares;
	}
	
	/**
	 * 设置路由中间件
	 * @param string $pattern
	 * @param mixed $middleware
	 * @return Router
	 */
	public function attach($pattern, $middleware) {
		if (isset($middleware)) {
			$this->middlewares[$pattern] = $middleware;
		}
		return $this;
	}

	/**
	 * 取得某路由回调名称的对应URI或者回调本体。
	 * @param string $name 回调名称，就是回调本体的[as]参数。
	 * @param string $back 是否返回本体
	 * @param string $prefix 一般不用设置
	 * @return string|array|boolean
	 */
	public function line($name, $back=false, $prefix='') {
		
		foreach ($this->routes as $pattern => $callback) {
			$pattern = '/' . trim($pattern, '/');
			$as = array();
			if (is_array($callback)) {
				$as = array_key_exists('as', $callback) ? $callback['as'] : null;
			} elseif ($callback instanceof Route) {
				$as = $route->uses();
			}
			if (in_array($name, (array) $as)) {
				if ($back) return $callback;
				return $prefix . $pattern;
			}
		}
		
		foreach ($this->blueprints as $pattern => $router) {
			$pattern = '/' . trim($pattern, '/');
			$_prefix = $prefix . $pattern;
			$ret = $router->line($name, $back, $_prefix);
			if ($ret) return $ret;
		}
		
		return false;
	}
	
	/**
	 * 解析URL地址
	 * @param string $url
	 * @return multitype:string mixed multitype:unknown
	 */
	public static function parse($url=null) {
		$request_uri = $_SERVER['REQUEST_URI'];
		$query_string = $_SERVER['QUERY_STRING'];
		if (!isset($url) or $url == null)
			$url = $request_uri;
		$url_query = parse_url($url);
		$path = $url_query['path'];
		$query = (isset($url_query['query']) ? ''.$url_query['query'] : '');
		$fragment = (isset($url_query['fragment']) ? ''.($url_query['fragment']) : '');
		$params = array();
		
		$arr = (!empty($query)) ? explode('&', $query) : array();
		if (count($arr) > 0) {
			foreach ($arr as $a) {
				$tmp = explode('=', $a);
				if (count($tmp) == 2) {
					$params[$tmp[0]] = $tmp[1];
				}
			}
		}
		
		return array (
			'path' => $path,
			'params' => $params,
			'fragment' => $fragment
		);
	}

	/**
	 * 获取带指定参数匹配路径
	 *
	 * @param array $args    参数数组
	 * @return string
	 */
	public static function path($url, array $args=array()) {
		// replace route url with given parameters
		if ($args && preg_match_all("/:(\w+)/", $url, $param_keys)) {
			// grab array with matches
			$param_keys = $param_keys[1];
			// loop trough parameter names, store matching value in $params array
			foreach ($param_keys as $key) {
				if (isset($args[$key])) {
					$url = preg_replace("/:(\w+)/", $args[$key], $url, 1);
				}
			}
		}
		return $url;
	}

	//=================================== Events ===================================//
	
	public static function obtain($who) {
		 //通知应用请求已匹配本路由
		if (self::$app != null) self::$app->onObtain($who);
	}
	
	public static function present($content) {
		//通知应用将要回应的文本内容。
		if (self::$app != null) self::$app->onPresent($result); 
	}
	
	public static function before_route($who) {
		//将本路由作为参数，通知应用本路由将开始。
		if (self::$app != null) self::$app->onBeforeRoute($who);
	}
	
	public static function completed() {
		if (self::$app != null) self::$app->onCompleted();
	}
	
	public static function notfound() {
		@header($_SERVER['SERVER_PROTOCOL'].' 404 Not Found');
		@header("status: 404 Not Found");
		if (self::$app != null) self::$app->onNotfound();
	}
	
	public static function badrequest() {
		@header($_SERVER['SERVER_PROTOCOL'].' 400 Bad Request');
		@header("status: 400 Bad Request");
		if (self::$app != null) self::$app->onBadrequest();
	}
	
	public static function error() {
		@header($_SERVER['SERVER_PROTOCOL'].' 500 Internal Server Error');
		@header("status: 500 Internal Server Error");
		$last_error = error_get_last();
		if (self::$app != null) self::$app->onError($last_error);
	}
	
	public static function redirect($path) {
		@header('Location: '.$path, true, 302);
		
	}

}
