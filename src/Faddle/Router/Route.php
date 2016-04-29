<?php namespace Faddle\Router;

use Exception;
use SplQueue;
use SplStack;

/**
 * ·����
 */
class Route {

	/**
	 * ·��ƥ���·����ʽ
	 * @var string
	 */
	private $pattern;

	/**
	 * ·�ɵ� HTTP ���󷽷�
	 * @var string[]
	 */
	private $method = array('GET', 'POST', 'PUT', 'DELETE');

	/**
	 * ·�ɵĻص�Ŀ�꣬��Ϊ��������
	 * @var mixed
	 */
	private $callback;

	/**
	 * ·�ɵ��м��
	 * @var string
	 */
	public $middlewares = array();

	/**
	 * ·��ʹ�õ������ռ�
	 * @var string
	 */
	public $uses;

	/**
	 * ·�ɵ�����
	 * @var string
	 */
	public $name = null;

	/**
	 * ·���Զ���Ĺ�����
	 * @var array
	 */
	private $filters = array();

	/**
	 * ·��֮���Ӧ֮ǰ�Ļص��ж�
	 * @type SplQueue
	 */
	protected $after_callbacks;

	/**
	 * ·�ɷ�������ʱ�Ļص�ջ
	 * @type SplStack
	 */
	protected $error_callbacks;
	
	/**
	 * ·�ɵ�ǰ���õ�ƥ�����
	 * @var array
	 */
	private $params = array();

	/**
	 * ·��ƥ���������ʽ
	 */
	const ROUTE_COMPILE_REGEX = '`(\\\?(?:/|\.|))(?:\{([^:\}]*)(?::([^:\}]*))?\})(\?|)`';
	
	/**
	 * ·��δ����������������ʽ
	 */
	const ROUTE_ESCAPE_REGEX = '`(?<=^|\})[^\}\{\?]+?(?=\{|$)`';
	
	protected static $match_types = array(
		'INT'  => '[0-9]++',
		'HEX'  => '[0-9A-Fa-f]++',
		'STR'  => '[0-9A-Za-z-_]++',
		'*'  => '.+?',
		'**' => '.++',
		''   => '[^/]+?'
	);

	/**
	 * ·�ɹ��캯��
	 */
	public function __construct($pattern, array $config) {
		$this->pattern = (string)$pattern;
		$this->method = isset($config['method']) ? (array)$config['method'] : array('GET', 'POST');
		$this->name = isset($config['as']) ? (string)$config['as'] : null;
		$this->callback = isset($config['controller']) ? $config['controller'] : null;
		$this->uses = array_key_exists('use', $config) ? (string)$config['use'] : '';
		if (is_string($this->callback)) {
			try {
				$action = explode('@', $this->callback);
				$instance = new $action[0];
				$this->callback = array($instance, $action[1]);
			} catch (Exception $e) {
				//pass
			}
		}
		
		$this->after_callbacks = new SplQueue();
		$this->error_callbacks = new SplStack();
	}

	/**
	 * ����һ��·�ɶ���
	 *
	 * @param callable $callback    �ص�Ŀ��
	 * @return Route
	 */
	public static function generate($method, $path, $callback, $name=null, $namespace=null) {
		$path = (string)$path;
		
		$config = array(
				'as' => $name,
				'use' => $namespace,
				'controller' => $callback,
				'middleware' => array(),
				'method' => (array)$method
			);
		return new Route($path, $config);
	}

	public function getConfig() {
		return array(
				'as' => $this->name,
				'use' => $this->uses,
				'controller' => $this->callback,
				'middleware' => $this->middlewares,
				'method' => $this->method
			);
	}

	/**
	 * ��鵱ǰ�����Ƿ�ƥ�䱾·��
	 * 
	 * @return boolean
	 */
	public function matchMethod() {
		$request_method = $_SERVER['REQUEST_METHOD'];
		$method = array_map('strtoupper', (array)$this->method);
		if (in_array($request_method, $method))
			return true;
		else
			return false;
	}

	public function __call($method, $args=array()) {
		if (preg_match('/^get[A-Z_]{1}.+/', $method)) {
			$prop_name = lcfirst(substr($method, 3));
			return $this->$prop_name;
		} else if (preg_match('/^set[A-Z_]{1}.+/', $method)) {
			$prop_name = lcfirst(substr($method, 3));
			if (property_exists($this, $prop_name)) {
				$prop_type = gettype($this->$prop_name);
				$args_type = gettype($args[0]);
				if (($prop_type === $args_type) or empty($this->$prop_name) or empty($args[0])) {
					$this->$prop_name = $args[0];
				} else {
					return false;
				}
			} else {
				return false;
			}
			return true;
		}
		if (isset($method_name)) {
			
		}
		return $this;
	}

	public function uses($namespace=null) {
		if (isset($namespace)) {
			$this->uses = (string)$namespace;
			return $this;
		}
		return $this->uses;
	}

	public function name($name=null) {
		if (isset($name)) {
			$this->name = (string)$name;
			return $this;
		}
		return $this->name;
	}

	public function middlewares($middlewares=null) {
		if (isset($middlewares)) {
			$this->middlewares = (array)$middlewares;
			return $this;
		}
		return $this->middlewares;
	}

	public function pattern($pattern=null) {
		if (isset($pattern)) {
			//$pattern = strtr($pattern, self::$match_types);
			$this->pattern = (string)$pattern;
			return $this;
		}
		return $this->pattern;
	}

	public function params($params=null) {
		if (isset($params)) {
			$this->params = (array)$params;
			return $this;
		}
		return $this->params;
	}

	public function filters(array $filters=null) {
		if (isset($filters)) {
			$this->filters = (array)$filters;
			return $this;
		}
		return $this->filters;
	}

	public function callback($callback=null) {
		if (isset($callback)) {
			if (is_callable($callback)) {
				$this->callback = $callback;
			} elseif (is_string($callback)) {
				$action = explode('@', $config['controller']);
				if (class_exists($action[0])) {
					$instance = new $action[0];
					if (method_exists($instance, $action[1]))
						$this->callback = array($instance, $action[1]);
					else
						$this->onError(sprintf('���������������ڣ�%s@%s', $action[0], $action[1]));
				} else {
					$this->onError(sprintf('�������಻���ڣ�%s', $action[0]));
				}
			} elseif (is_array($callback)) {
				$this->onError('���������ͳ���');
			}
			return $this;
		}
		return $this->callback;
	}

	/**
	 * Magic "__invoke" method
	 *
	 * Allows the ability to arbitrarily call this instance like a function
	 *
	 * @param mixed $args Generic arguments, magically accepted
	 * @return mixed
	 */
	public function __invoke($args=null) {
		$args = func_get_args();
		return call_user_func_array($this->callback, $args);
	}

	/**
	 * ���ɸ�·��ָ��������URI��ַ
	 */
	public function markUri(array $args, array $params=null) {
		//todo
		return null;
	}

	/**
	 * ����·����Ӧ����
	 */
	public function lookup(array $params) {
		$match_types = self::$match_types;
		$this->pattern = preg_replace_callback(
			static::ROUTE_COMPILE_REGEX,
			function ($matchs) use ($params, $match_types) {
				list($block, $pre, $param, $type, $optional) = $matchs;
				$type = strtoupper($type);
				if (isset($match_types[$type])) {
					$type = $match_types[$type];
				}
				if (isset($params[$param])) {
					return $pre. $params[$param];
				} elseif ($optional) {
					return '';
				}
				return $block;
			},
			$this->pattern
		);
		return $this;
	}

	public function getRegex() {
		return $this->convertToRegex($this->pattern);
	}

	/**
	 * ת��·��·��Ϊ������̬������ȡ����
	 *
	 * @param string $route ·��·��
	 * @return string Pattern ������̬
	 */
	private function convertToRegex($route) {
		$route = str_replace(array('//', '(', ')'), array('/', '\(', '\)'), $route);
		$match_types = self::$match_types;
		
		return '' . preg_replace_callback("@\{(?:([^:\}]+)|)(?::([\w-%]+)|)?\}@", function($matchs) use($match_types) {
			$name = $matchs[1];
			$type = count($matchs) > 2 ? $matchs[2] : null;
			$pattern = "[^/\?#]+";
			
			if (!empty($type)) {
				$type = strtoupper($type);
				if (isset($match_types[$type])) {
					$pattern = $match_types[$type];
				}
			}
			if (strlen($name) > 0 and $name[strlen($name) - 1] == '?') {
				$name = substr($name, 0, strlen($name) - 1);
				$end = '?';
			} else {
				$end = '';
			}
			if (!empty($name) && isset($this->filters[$name])) {
				$pattern = $this->filters[$name];
			}
			if (empty($name)) return '(' . $pattern . ')';
			return '(?<' . $name . '>' . $pattern . ')' . $end;
		}, $route) . '';
	}

	/**
	 * Arrange arguments for the given function
	 *
	 * @param callable $function
	 * @param array    $arguments
	 * @return array
	 */
	public function arrangeFuncArgs($function, $arguments) {
		$ref = new \ReflectionFunction($function);
		$params = $ref->getParameters();
		$unmatched = 0;
		$args = array_map(
			function (\ReflectionParameter $param) use ($arguments, $params, &$unmatched) {
				if (isset($arguments[$param->getName()])) {
					return $arguments[$param->getName()];
				}
				if ($param->isOptional()) {
					return $param->getDefaultValue();
				}
				if (count($params) > 1) {
					$idx = array_search($param, $params);
					if (isset($arguments[$idx])) {
						return $arguments[$idx];
					}
				}
				$unmatched++;
				return null;
			},
			$params
		);
		if ($unmatched) $args = $arguments;
		return $args;
	}

	/**
	 * Arrange arguments for the given method
	 *
	 * @param object   $class
	 * @param callable $method
	 * @param array    $arguments
	 * @return array
	 */
	public function arrangeMethodArgs($class, $method, $arguments) {
		$ref = new \ReflectionMethod($class, $method);
		$params = $ref->getParameters();
		$unmatched = 0;
		$args =  array_map(
			function (\ReflectionParameter $param) use ($arguments, $params, &$unmatched) {
				if (isset($arguments[$param->getName()])) {
					return $arguments[$param->getName()];
				}
				if ($param->isOptional()) {
					return $param->getDefaultValue();
				}
				if (count($params) > 1) {
					$idx = array_search($param, $params);
					if (isset($arguments[$idx])) {
						return $arguments[$idx];
					}
				}
				$unmatched++;
				return null;
			},
			$params
		);
		if ($unmatched) $args = $arguments;
		return $args;
	}

	/**
	 * ���һ���ص��ڷַ���·�����֮��ͻ�Ӧ֮ǰִ��
	 *
	 * @param callable $callback ·����֮��Ҫִ�еĻص�����
	 * @return void
	 */
	public function after($callback) {
		if (isset($callback))
			$this->after_callbacks->enqueue($callback);
		return $this;
	}

	/**
	 * ·�����֮�󴥷��ص�
	 *
	 * @return void
	 */
	public function onAfter() {
		try {
			foreach ($this->after_callbacks as $callback) {
				if (is_callable($callback)) {
					if (is_string($callback)) {
						$callback($this);
					} else {
						call_user_func($callback, $this);
					}
				}
			}
		} catch (Exception $err) {
			$type = get_class($err);
			$msg = $err->getMessage();
			$code = $err->getCode();
			$this->onError($msg, $type, $err);
		}
	}

	public function error($callback) {
		if (isset($callback))
			$this->error_callbacks->push($callback);
		return $this;
	}

	/**
	 * ·�ɷ������󴥷��ص�
	 *
	 * @return void
	 */
	public function onError($msg, $type=null, $err=null) {
		if (!$this->error_callbacks->isEmpty()) {
			foreach ($this->error_callbacks as $callback) {
				if (is_callable($callback)) {
					if (is_string($callback)) {
						$callback($this, $msg, $type, $err);
						return;
					} else {
						call_user_func($callback, $this, $msg, $type, $err);
						return;
					}
				} else {
					
				}
			}
		} else {
			throw new Exception($msg);
		}
		
	}

}