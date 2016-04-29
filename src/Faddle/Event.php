<?PHP namespace Faddle;

class Event {

	private $listeners = array();
	protected $events = array();
	
	public function __construct() {
		
	}
	
	public static function build($event=array(), $listener=null) {
		$self =  new self();
		$self->set($event, $listener);
		return $self;
	}
	
	/**
	 * 注册某事件监听器
	 * @param string $event 事件名称
	 * @param mixed $callback 回调函数或数组参数
	 * @return Event
	 */
	public function on($event, $callback) {
		$event = strtolower($event);
		if (in_array($event, ($this->events))) {
			if (!isset($this->listeners[$event])) {
				$this->listeners[$event] = array();
			}
			$this->listeners[$event][] = $callback;
			//return true;
		} else {
			//return false;
		}
		return $this;
	}
	
	/**
	 * 注册一次性的某事件监听器
	 * @param string $event 事件名称
	 * @param mixed $callback 回调函数或数组参数
	 * @return boolean|Event
	 */
	public function once($event, $callback) {
		$event = strtolower($event);
		if (in_array($event, ($this->events))) {
			$this->listeners[$event][] = array($callback, array('times' => 1));
			
		} else {
			return false;
		}
		
		return $this;
	}
	
	/**
	 * 取消某事件的监听器
	 * @param string $event 事件名称
	 * @param mixed $callback 回调函数或数组参数
	 * @return Event
	 */
	public function off($event, $callback=null) {
		$event = strtolower($event);
		if (!empty($this->listeners[$event])) {
			if (is_null($callback)) {
				$this->listeners[$event] = array();
			} else if (($key = array_search($callback, $this->listeners[$event])) !== false) {
				unset($this->listeners[$event][$key]);
			}
		}
		return $this;
	}
	
	/**
	 * 触发指定事件
	 * @param string $event 事件名称
	 * @param mixed $params 数组参数
	 * @return mixed
	 */
	public function fire($event, $params=null) {
		return $this->trigger($event, $params);
	}
	
	public function trigger($event, $params=null) {
		$event = strtolower($event);
		if ($params !== null) {
			$params = array_slice(func_get_args(), 1);
		} else {
			$params = array();
		}
		if (empty($this->listeners[$event])) return false;
		foreach ($this->listeners[$event] as $callback) {
			if (is_array($callback)) {
				$extras = $callback[1];
				$callback = $callback[0];
				if ($extras and is_array($extras)) {
					$times = & $extras['times'];
					$times = intval($times);
				}
			}
			if (isset($times)) {
				if ($times === 0 or $times < 0) {
					unset($callback);
					return true;
				} else $times--;
			}
			if ($callback instanceof \Closure) {
				$return = call_user_func_array($callback, $params);
			} else {
				$callback = (string) $callback; 
				$calls = explode('@', $callback);
				if (count($calls) <= 1) {
					if (function_exists($calls[0])) {
						$return = call_user_func_array($calls[0], $params);
					} else {
						$return = false;
					}
				} else {
					$method = $calls[1];
					if (class_exists($calls[0])) $callback = new $calls[0]();
					else $callback = false;
					if ($callback and method_exists($callback, $method)) {
						$return = call_user_func_array(array($callback, $method), $params);
					} else {
						$return = false;
					}
				}
			}
		}
		return $return;
	}
	
	/**
	 * 设置事件名称及默认方法
	 * 
	 * @param mixed $key Key
	 * @param mixed $value Value
	 */
	public function set($key, $value=null) {
		if (is_array($key)) {
			foreach ($key as $event) {
				$event = strtolower((string)$event);
				if (! $this->has($event))
					$this->events[] = $event;
				if (! is_null($value) and ! $this->has($event, $value))
					$this->listeners[$event][] = $value;
			}
		} else {
			$key = strtolower($key);
			$this->events[] = $key;
			if (! is_null($value) and ! $this->has($key, $value))
				$this->listeners[$key][] = $value;
		}
		
	}

	/**
	 * 检查事件是否存在
	 * 
	 * @param string $key  事件名称
	 * @param mixed $value 事件回调
	 * @return bool
	 */
	public function has($key, $value=null) {
		if (! is_null($value) and isset($this->listeners[$key])) {
			return (array_search($value, $this->listeners[$key])) !== false;
		}
		return (array_search($key, $this->events)) !== false;
	}

	/**
	 * 清空事件或清除某个事件
	 *
	 * @param string $key Key
	 */
	public function clear($key=null) {
		if (is_null($key)) {
			$this->events = array();
			$this->listeners = array();
		} else {
			$key = strtolower($key);
			if (($idx = array_search($key, $this->events)) !== false) {
				unset($this->events[$idx]);
				unset($this->listeners[$key]);
			}
		}
	}

}
