<?php namespace Faddle;

use BadMethodCallException;
use ReflectionFunctionAbstract;

abstract class Faddle {

	protected $objects;
	protected $services = array();

	public function __construct() {
		$this->objects = new \SplObjectStorage();
	}

	public function __destruct() {
		
	}

	
	public function __call($method, $args) {
		if (preg_match('/^on[A-Z_]{1}.+/', $method)) {
			$event_name = lcfirst(substr($method, 2));
			if (method_exists($this, 'emit')) {
				array_unshift($args, $event_name);
				return call_user_func_array(array($this, 'emit'), $args);
			}
			return true; //忽略以'on'开头的事件触发方法
		}
		if (isset($this->services[$method]) and is_callable($this->services[$method])) {
			$return = call_user_func($this->services[$method]);
			//继续判断返回值是否可回调
			if (is_callable($return)) {
				return call_user_func_array($return, $args);
			} elseif ($return instanceof ReflectionFunctionAbstract) {
				$reflection = $return;
				$args = $this->getArguments(
					$args,
					$reflection->getParameters(),
					$reflection->getNumberOfRequiredParameters(),
					$reflection->getNumberOfParameters()
				);
				return $reflection->invokeArgs($args);
			}
			return $return;
		}
		throw new BadMethodCallException('Unknown method: '. $method .'()');
	}
	
	public static function __callStatic($func, $args) {
		$feature = get_called_class();
		
		
	}

	/**
	 * 扩展服务
	 *
	 * @param string $name
	 * @param callable $callable
	 * @return callable
	 */
	public function extend($name, $callable) {
		if (!is_object($this->services[$name]) || !method_exists($this->services[$name], '__invoke')) {
			throw new \InvalidArgumentException(sprintf('Identifier "%s" does not contain an object definition.', $name));
		}
		
		if (!is_object($callable) || !method_exists($callable, '__invoke')) {
			throw new \InvalidArgumentException('Extension service definition is not a Closure or invokable object.');
		}
		
		$factory = $this->services[$name];
		
		$extended = function ($c) use ($callable, $factory) {
			return $callable($factory($c), $c);
		};
		
		$this->services[$name] = $extended;
		
		return $extended;
	}

	/**
	 * 共享服务
	 *
	 * @param callable $closure
	 * @return callable
	 */
	public function share($closure) {
		if (!method_exists($closure, '__invoke')) {
			throw new \InvalidArgumentException('Service definition is not a Closure or invokable object.');
		}
		if ($this->objects->contains($closure)) {
			$this->objects->detach($closure);
		}
		$this->objects->attach($closure);
		/* $this->objects->rewind();
		while($this->objects->valid()) {
			var_dump($this->objects->current());
			$this->objects->next();
		}*/
		return $closure;
	}

	/**
	 * 注册服务
	 *
	 * @param string $name       服务名称
	 * @param callable $closure  服务回调函数
	 * @return mixed
	 */
	public function register($name, $closure) {
		if (isset($this->services[$name])) {
			return false;
		}
		$this->services[$name] = function () use ($closure) {
			static $instance;
			if (null === $instance) {
				//$instance = new ReflectionFunction($closure);
				$instance = self::genCallableReflection($closure);
			}
			return $instance;
		};
		return $this;
	}

	/**
	 * 绑定一个服务类
	 *
	 * @access public
	 * @param  string   $name    服务名称
	 * @param  mixed    $class   一个类或者对象
	 * @param  string   $method  方法名称
	 * @return Server
	 */
	public function bind($name, $class, $method = '') {
		if ($method === '') {
			$method = $name;
		}
		$this->services[$name] = function () use ($class, $method) {
			static $instance;
			if (null === $instance) {
				if (method_exists($class, $method)) {
					$instance = new ReflectionMethod($class, $method); //array($class, $method);
				} else {
					//throw new BadFunctionCallException('Unable to find the procedure.');
				}
			}
			return $instance;
		};
		return $this;
	}

	/**
	 * Get procedure arguments
	 *
	 * @access public
	 * @param  array    $request_params       Incoming arguments
	 * @param  array    $method_params        Procedure arguments
	 * @param  integer  $nb_required_params   Number of required parameters
	 * @param  integer  $nb_max_params        Maximum number of parameters
	 * @return array
	 */
	public function getArguments(array $request_params, array $method_params, $nb_required_params, $nb_max_params) {
		$nb_params = count($request_params);
		if ($nb_params < $nb_required_params) {
			throw new InvalidArgumentException('Wrong number of arguments');
		}
		if ($nb_params > $nb_max_params) {
			throw new InvalidArgumentException('Too many arguments');
		}
		//true if we have positional parametes
		if (array_keys($request_params) === range(0, count($request_params) - 1)) {
			return $request_params;
		}
		$params = array(); //Get named arguments
		foreach ($method_params as $p) {
			$name = $p->getName();
			if (isset($request_params[$name])) {
				$params[$name] = $request_params[$name];
			}
			else if ($p->isDefaultValueAvailable()) {
				$params[$name] = $p->getDefaultValue();
			}
			else {
				throw new InvalidArgumentException('Missing argument: '.$name);
			}
		}
		
		return $params;
	}

	/**
	 * 
	 * @param callable $callable
	 * @return \ReflectionFunctionAbstract
	 */
	public static function genCallableReflection($callable) {
		// Closure
		if ($callable instanceof \Closure) {
			return new \ReflectionFunction($callable);
		}
		// Array callable
		if (is_array($callable)) {
			list($class, $method) = $callable;
			return new \ReflectionMethod($class, $method);
		}
		// Callable object (i.e. implementing __invoke())
		if (is_object($callable) && method_exists($callable, '__invoke')) {
			return new \ReflectionMethod($callable, '__invoke');
		}
		// Callable class (i.e. implementing __invoke())
		if (is_string($callable) && class_exists($callable) && method_exists($callable, '__invoke')) {
			return new \ReflectionMethod($callable, '__invoke');
		}
		// Standard function
		if (is_string($callable) && function_exists($callable)) {
			return new \ReflectionFunction($callable);
		}
		
		return false;
	}

	public static function getReflectionParameters(ReflectionFunctionAbstract $reflection,
		array $providedParameters, array $resolvedParameters, $flag=0) {
		
		$parameters = $reflection->getParameters();
		// Skip parameters already resolved
		if (! empty($resolvedParameters)) {
			$parameters = array_diff_key($parameters, $resolvedParameters);
		}
		foreach ($parameters as $index => $parameter) {
			if (($flag == 0 || $flag == 1) && array_key_exists($parameter->name, $providedParameters)) {
				$resolvedParameters[$index] = $providedParameters[$parameter->name];
			}
			if (($flag == 0 || $flag == 2) && $parameter->isOptional()) {
				try {
					$resolvedParameters[$index] = $parameter->getDefaultValue();
				} catch (ReflectionException $e) {
					// Can't get default values from PHP internal classes and functions
				}
			}
			if (($flag == 0 || $flag == 3) && is_int($index)) {
				$resolvedParameters[$index] = $value;
			}
		}
		$diff = array_diff_key($parameters, $resolvedParameters);
		if (empty($diff)) {
			// all parameters are resolved
		}
		// Sort by array key because call_user_func_array ignores numeric keys
		//ksort($resolvedParameters);
		
		return $resolvedParameters;
	}

}


/**
 * Faddle Service Provider Interface
 */
interface ServiceProviderInterface {

	public function register(Faddle $app);

}
