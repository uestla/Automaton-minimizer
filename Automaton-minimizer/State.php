<?php

/**
 * @property-read string $id
 * @property bool $initial
 * @property bool $final
 * @property array $transitions
 */
class State extends Nette\Object
{
	/** @var string */
	protected $id;

	/** @var bool */
	protected $initial = FALSE;

	/** @var bool */
	protected $final = FALSE;

	/** @var array */
	protected $transitions = array();



	/**
	 * @param  string
	 */
	public function __construct($id)
	{
		$this->id = (string) $id;
	}



	/**
	 * @return string
	 */
	public function __toString()
	{
		return $this->id;
	}



	/**
	 * @param  mixed
	 * @param  bool
	 * @return void
	 */
	public function _print($space, $forProgtest = FALSE)
	{
		if ($this->initial)
			if ($this->final)
				echo ($prefix = Automaton::INIT_ST . Automaton::FINAL_ST);
			else
				echo ($prefix = ' ' . Automaton::INIT_ST);
		elseif ($this->final)
			echo ($prefix = ' ' . Automaton::FINAL_ST);
		else
			echo ($prefix = '  ');

		echo $this->id . $space( $prefix . $this->id );

		// transitions
		foreach ($this->transitions as $targets) {
			echo ($value = (count($targets)
					? implode( Automaton::STATES_SEP, $targets )
					: Automaton::NULL_POINTER ) )
				. $space( $value );
		}

		echo "\n";
	}



	/**
	 * @return string
	 */
	public function getId()
	{
		return $this->id;
	}



	/**
	 * @param  string
	 * @return State provides fluent interface
	 */
	public function setID($id)
	{
		$this->id = (string) $id;
		return $this;
	}



	/**
	 * @return bool
	 */
	public function getInitial()
	{
		return $this->initial;
	}



	/**
	 * @param  bool
	 * @return State provides fluent interface
	 */
	public function setInitial($i = TRUE)
	{
		$this->initial = $i;
		return $this;
	}



	/**
	 * @return bool
	 */
	public function getFinal()
	{
		return $this->final;
	}



	/**
	 * @param  bool
	 * @return State provides fluent interface
	 */
	public function setFinal($f = TRUE)
	{
		$this->final = $f;
		return $this;
	}



	/**
	 * @return array
	 */
	public function getTransitions()
	{
		return $this->transitions;
	}



	/**
	 * @param  array
	 * @return State provides fluent interface
	 */
	public function setTransitions(array $t)
	{
		$this->transitions = $t;
		return $this;
	}



	/**
	 * @return array
	 */
	public function getAlphabet()
	{
		return array_keys($this->transitions);
	}



	/**
	 * @return State provides fluent interface
	 */
	public function removeEpsilon()
	{
		$epsKey = Automaton::EPS;
		$this->getEpsilonUnion($this, $union);

		if (count($union)) {
			$transitions = array();

			foreach ($union as $state) {
				if (!$this->final && $state->final) {
					$this->final = TRUE;
				}

				foreach ($this->getAlphabet() as $letter) {
					if ($letter === $epsKey) continue;

					if (!isset($transitions[$letter])) {
						$transitions[$letter] = array();
					}

					$transitions[$letter] = array_merge($transitions[$letter], $state->transitions[$letter]);
					usort($transitions[$letter], __CLASS__ . '::compare');
					$transitions[$letter] = array_unique( $transitions[$letter] );
				}
			}

			$this->transitions = $transitions;

		} else {
			unset($this->transitions[ $epsKey ]);
		}

		return $this;
	}



	/**
	 * @param  State
	 * @param  array|NULL
	 * @return void
	 */
	private function getEpsilonUnion($state, & $union = NULL)
	{
		$epsKey = Automaton::EPS;
		if ($union === NULL) {
			$union = array();
		}

		if (isset($state->transitions[$epsKey]) && count($state->transitions[$epsKey])) {
			if (!in_array($state, $union, TRUE)) {
				$union[] = $state;
			}

			foreach ($state->transitions[$epsKey] as $s) {
				if (!in_array($s, $union, TRUE) ) {
					$union[] = $s;
					$this->getEpsilonUnion($s, $union);
				}
			}
		}
	}



	/**
	 * @return State provides fluent interface
	 */
	public function normalize()
	{
		ksort($this->transitions, SORT_STRING);
		return $this;
	}



	/**
	 * Blind state = state which points only to itself or nowhere and is not final
	 *
	 * @return bool
	 */
	public function isBlind()
	{
		if ($this->final) return FALSE;

		foreach ($this->transitions as $targets) {
			if (count($targets) > 1 || (count($targets) === 1 && $targets[0] !== $this))
				return FALSE;
		}

		return TRUE;
	}



	/**
	 * Tests if the state is deterministic, which is TRUE when:
	 *	- it doesn't have {epsilon} character in the alphabet
	 *	- or when it points for every letter just into one and only other state
	 *
	 * @return bool
	 */
	public function isDeterministic()
	{
		foreach ($this->transitions as $letter => $targets) {
			if ($letter === Automaton::EPS || count($targets) !== 1) return FALSE;
		}

		return TRUE;
	}



	/**
	 * @return State provides fluent interface
	 */
	public function removeStateById($id)
	{
		foreach ($this->transitions as $letter => $targets) {
			foreach ($targets as $key => $state) {
				if ($state->id === $id && $state->id !== $this->id) {
					unset($targets[$key]);
				}
			}

			$this->transitions[$letter] = array_values($targets);
		}

		return $this;
	}



	/**
	 * @return State provides fluent interface
	 */
	public function removeTransition($letter, State $state)
	{
		if (($key = array_search($state, (array) $this->transitions[$letter], TRUE)) === FALSE) {
			throw new Exception("State '$state' not found in '$letter' transition of '$this' state.");
		}

		unset($this->transitions[$letter][$key]);
		return $this;
	}



	/**
	 * @return int
	 */
	public static function compare(State $s1, State $s2)
	{
		if (is_numeric($s1->id) && is_numeric($s2->id)) {
			return (double) $s1->id - (double) $s2->id;

		} else {
			$cmp = strcmp($s1->id, $s2->id);

			if ($cmp && ($diff = strlen($s1->id) - strlen($s2->id) > 0)) {
				return $diff;
			}

			return $cmp;
		}
	}
}