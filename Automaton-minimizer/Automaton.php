<?php

use Nette\Utils\Strings;


class Automaton extends Nette\Object
{
	/** @var array Q */
	protected $states;

	/** @var array T */
	protected $alphabet;

	/** @var array I */
	protected $initials;

	/** @var array F */
	protected $finals;

	/** @var bool */
	protected $normalized = FALSE;


	const NFA = 'NFA',
		DFA = 'DFA',
		EPS = '\\eps',
		INIT_ST = '>',
		FINAL_ST = '<',
		NULL_POINTER = '-',
		STATES_SEP = '|';



	/******************************** automaton loading ********************************/



	/**
	 * @param  string
	 * @return Automaton
	 */
	public static function fromFile($file)
	{
		$path = realpath($file);
		if (!$path) {
			throw new Exception("File '$file' not found.");
		}

		$handle = fopen('safe://' . $path, 'r');
		if (!$handle) {
			throw new Exception("File '$file' not readable.");
		}

		$a = new static();
		$a->states = array();

		$line = 1;
		$headingLoaded = FALSE;

		while (!feof($handle)) {

			$parts = Strings::trim( fgets($handle) );
			if (!strlen($parts)) { // skip empty lines
				$line++;
				continue;
			}

			$parts = Strings::split( $parts, '#[\s]+#' );

			if (!$headingLoaded) {

				// automaton type
				$type = array_shift($parts);
				if ($type !== static::NFA && $type !== static::DFA) {
					throw new Exception("Unexpected '$type' in '$file:$line', expected '" . static::NFA . "' or '" . static::DFA . "'.");
				}

				// automaton alphabet
				$a->alphabet = $parts;
				if ( count( array_unique( $a->alphabet ) ) !== count( $a->alphabet ) ) {
					throw new Exception("Duplicate letters found in the alphabet in '$file:$line'.");
				}

				$headingLoaded = TRUE;
				$line++;
				continue;

			}

			// fetch next automaton state
			$id = array_shift($parts);
			$init = $final = FALSE;

			$options = preg_quote(static::INIT_ST, '#') . '|' . preg_quote(static::FINAL_ST, '#');
			if ($m = Strings::match($id, "#^(?:($options)($options)?)#")) {
				array_shift($m);

				foreach ($m as $identifier) {
					if ($identifier === static::INIT_ST) {
						$init = TRUE;
						$id = substr($id, strlen($identifier));

					} elseif ($identifier === static::FINAL_ST) {
						$final = TRUE;
						$id = substr($id, strlen($identifier));
					}
				}
			}

			if (!strlen($id)) {
				throw new Exception("Identifier of state not specified in '$file:$line'.");
			}

			if (!isset($states[$id])) {
				$states[$id] = new State($id);

			} elseif (count($states[$id]->transitions)) {
				throw new Exception("Redefinition of state '$id' in '$file:$line'.");
			}

			$transitions = array_combine($a->alphabet, $parts);
			if ($transitions === FALSE) {
				throw new Exception("Transition count doesn't match the letter count in '$file:$line'.");
			}

			foreach ($transitions as & $targets) {
				if ($targets === static::NULL_POINTER) {
					$targets = array();

				} else {
					$targets = explode(static::STATES_SEP, $targets);
					sort($targets);
					$targets = array_values( array_unique( $targets ) );

					foreach ($targets as $letter => & $s) {
						if (!isset($states[$s])) {
							$s = new State($s);
							$states[$s->id] = $s;

						} else {
							$s = $states[$s];
						}
					}
				}
			}

			$states[$id]->setTransitions( $transitions )
				->setInitial( $init )
				->setFinal( $final );

			$line++;

		}

		$a->updateStates( $states );

		if (!$a->isDeterministic() && $type === static::DFA) {
			trigger_error("Automaton marked as deterministic detected as non-deterministic in '$file'.", E_USER_WARNING);
		}

		$a->validate();
		return $a;
	}



	/******************************** transformations ********************************/



	/**
	 * @return Automaton provides fluent interface
	 */
	public function removeEpsilon()
	{
		if (($epsKey = array_search(static::EPS, $this->alphabet, TRUE)) === FALSE) {
			return $this; // or throw new Exception("Epsilon not found in the alphabet.") ?
		}

		foreach ($this->states as $state) {
			$state->removeEpsilon();
		}

		unset($this->alphabet[$epsKey]);

		$this->updateStates();
		$this->validate();

		return $this;
	}



	/**
	 * @return Automaton provides fluent interface
	 */
	public function determinize()
	{
		$this->removeEpsilon();

		if ($this->isDeterministic()) {
			return $this;
		}

		$this->determinizeStates($this->initials, $newStates);
		$this->updateStates( $newStates );

		return $this;
	}



	/**
	 * @return Automaton provides fluent interface
	 */
	public function minimize()
	{
		$this->determinize();
		$this->removeUnreachableStates();

		$newStates = array(
			array(),
			array(),
		);

		$newTransitions = array();

		// initial new states iteration
		foreach ($this->states as $id => $state) {
			$newStates[1][$id] = $state->final ? '1' : '2';
		}

		while ($newStates[0] !== $newStates[1]) {
			$newStates[0] = $newStates[1];
			$newStates[1] = array();

			// new transitions loading
			foreach ($this->states as $id => $state) {
				foreach ($state->transitions as $letter => $target) {
					$newTransitions[$id][$letter] = $newStates[0][ $target[0]->id ];
				}
			}

			// new states loading
			foreach ($this->states as $id => $state) {
				// test combination existence
				$found = FALSE;
				foreach ($this->states as $i => $s) {
					if ($id === $i) break; // search only until the actual state

					if ($newTransitions[$id] === $newTransitions[$i]
							&& $this->states[$id]->final === $this->states[$i]->final) {
						$found = $i;
						break;
					}
				}

				$newStates[1][$id] = $found === FALSE ? ( count($newStates[1]) ? (string) (max($newStates[1]) + 1) : '1' ) : (string) $newStates[1][$found];
			}
		}

		$states = array();
		foreach ($newStates[1] as $oldID => $id) {
			if (!isset($states[$id])) {
				$states[$id] = new State($id);
			}

			$states[$id]->setInitial( $this->states[$oldID]->initial)
					->setFinal( $this->states[$oldID]->final );

			foreach ($newTransitions[$oldID] as $letter => & $target) {
				if (!isset($states[$target])) {
					$tmp = $states[$target] = new State($target);
					$target = array($tmp);

				} else {
					$target = array($states[$target]);
				}
			}

			$states[$id]->setTransitions( $newTransitions[ $oldID ] );
		}

		$this->updateStates( $states );
		return $this;
	}



	/**
	 * @return Automaton provides fluent interface
	 */
	public function normalize()
	{
		if ($this->normalized) {
			return $this;
		}

		$this->minimize();

		sort($this->alphabet);
		foreach ($this->states as $state) {
			$state->normalize();
		}

		$this->normalized = TRUE;
		return $this;
	}



	/******************************** automaton handling ********************************/



	/**
	 * @return Automaton provides fluent interface
	 */
	protected function removeAllStates()
	{
		foreach ($this->states as $id => $s) {
			$this->removeState($id);
		}

		return $this;
	}



	/**
	 * @param  string
	 * @return Automaton provides fluent interface
	 */
	protected function removeState($id)
	{
		$id = (string) $id;

		if (!isset($this->states[$id])) {
			throw new Exception("Unable to delete state '$id' - state doesn't exist.");
		}

		unset($this->states[$id]);
		unset($this->initials[$id]);
		unset($this->finals[$id]);

		foreach ($this->states as $state) {
			$state->removeStateById($id);
		}

		return $this;
	}



	/******************************** getters & setters ********************************/



	/**
	 * @return bool
	 */
	public function isDeterministic()
	{
		if ( count($this->initials) > 1 || in_array(self::EPS, $this->alphabet, TRUE) ) return FALSE;

		foreach ($this->states as $state) {
			if ($state->hasMultipleTransitions()) return FALSE;
		}

		return TRUE;
	}



	/******************************** outputs ********************************/



	/**
	 * @param  bool
	 * @return Automaton provides fluent interface
	 */
	public function _print($forProgtest = FALSE)
	{
		$space = $forProgtest
			? function ($value = '') {
				return '  ';
			}
			: function ($value = '') {
				return strlen($value) < 8 ? "\t\t\t" : (strlen($value) < 16 ? "\t\t" : "\t");
			};

		$deterministic = $this->isDeterministic();

		// heading
		echo ($deterministic ? static::DFA : static::NFA)
			. $space();

		foreach ($this->alphabet as $letter) {
			echo $letter . $space();
		}

		echo "\n";

		if (!$forProgtest) {
			echo str_repeat('-', count($this->alphabet) * 30) . "\n";
		}

		// states
		foreach ($this->states as $id => $state) {
			$state->_print($space, $forProgtest);
		}

		echo "\n";
		return $this;
	}



	/**
	 * @param  string
	 * @return Automaton provides fluent interface
	 */
	public function save($file)
	{
		ob_start();
		$this->_print(TRUE);
		file_put_contents('safe://' . $file, Strings::normalize( ob_get_clean() ) . "\n" );
		return $this;
	}



	/******************************** helpers ********************************/



	/**
	 * @param  array|NULL
	 * @return Automaton provides fluent interface
	 */
	private function updateStates(array $newStates = NULL)
	{
		if ($newStates !== NULL) {
			// delete old states first
			$this->removeAllStates();

			$this->states = $newStates;
		}

		$this->initials = $this->finals = array();
		uasort($this->states, 'State::compare');

		foreach ($this->states as $id => $state) {
			if ($state->initial) {
				$this->initials[$id] = $state;
			}

			if ($state->final) {
				$this->finals[$id] = $state;
			}
		}

		return $this;
	}



	/**
	 * @return Automaton provides fluent interface
	 */
	public function validate()
	{
		if (!count($this->initials) || !count($this->finals)) {
			throw new Exception("At least one initial and one final state required.");
		}

		foreach ($this->states as $state) {
			if (!count($state->transitions)) {
				throw new Exception("Definition of state '$state' not found.");
			}

			if ($state->alphabet !== $this->alphabet) {
				throw new Exception("Transitions of state '$state' don't match the alphabet.");
			}

			foreach ($state->transitions as $targets) {
				foreach ($targets as $p) {
					if (!isset($this->states[$p->id])) {
						throw new Exception("State '$p' pointed by '$state' not found.");
					}
				}
			}
		}

		return $this;
	}



	/**
	 * @return Automaton provides fluent interface
	 */
	public function removeUnreachableStates()
	{
		$this->scanReachable( $this->initials, $reachable );
		foreach (array_diff($this->states, $reachable) as $state) {
			$this->removeState($state->id);
		}

		return $this;
	}



	/**
	 * @param  array
	 * @param  array|NULL
	 * @return void
	 */
	private function scanReachable(array $states, & $reachable = NULL)
	{
		if ($reachable === NULL) {
			$reachable = array();
		}

		foreach ($states as $state) {
			if (!in_array($state, $reachable, TRUE)) {
				$reachable[] = $state;

				foreach ($state->transitions as $letter => $targets) {
					$this->scanReachable($targets, $reachable);
				}
			}
		}
	}



	/**
	 * @param  array
	 * @param  array|NULL
	 * @return void
	 */
	private function determinizeStates(array $states, & $newStates = NULL)
	{
		static $list = array();
		if ($newStates === NULL) {
			$newStates = array();
		}

		$id = $this->createId($states);

		if (!isset($newStates[$id])) {
			$newStates[$id] = new State($id);
		}

		if (!count($newStates[$id]->transitions) && !in_array($id, $list, TRUE)) {
			$list[] = $id;
			$init = TRUE;
			$final = FALSE;
			$transitions = array();

			foreach ($this->alphabet as $letter) {
				$union = array();
				foreach ($states as $state) {
					if ($init && !$state->initial) { // each state has to be initial for the new state to be initial as well
						$init = FALSE;
					}

					if (!$final && $state->final) {
						$final = TRUE;
					}

					$union = array_merge($union, $state->transitions[$letter]);
				}

				usort($union, 'State::compare');
				$union = array_unique($union);

				$newID = $this->createId($union);
				if (!isset($newStates[$newID])) {
					$newStates[$newID] = new State($newID);
				}

				$transitions[$letter] = array($newStates[$newID]);

				if ($newID !== $id) {
					$this->determinizeStates( $union, $newStates );
				}
			}

			$newStates[$id]
					->setInitial(count($states) && $init)
					->setFinal($final)
					->setTransitions($transitions);
		}
	}



	/**
	 * @param  array
	 * @return string
	 */
	private function createId(array $states)
	{
		return '{' . implode(',', $states) . '}';
	}



	/**
	 * @param  string
	 * @return bool
	 */
	public function checkString($s)
	{
		$this->normalize();

		$chars = preg_split('##u', (string) $s, -1, PREG_SPLIT_NO_EMPTY);

		$state = reset($this->initials);
		foreach ($chars as $char) {
			if (!isset($state->transitions[$char]) || !count($state->transitions[$char])) return FALSE;
			$state = $state->transitions[$char][0];
		}

		return $state->final;
	}
}