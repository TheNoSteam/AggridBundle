<?php
declare(strict_types=1);
/*
 * This file is part of the Stinger Soft AgGrid package.
 *
 * (c) Oliver Kotte <oliver.kotte@stinger-soft.net>
 * (c) Florian Meyer <florian.meyer@stinger-soft.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace StingerSoft\AggridBundle\Helper;

use InvalidArgumentException;
use IteratorAggregate;
use OutOfBoundsException;
use ReflectionException;
use StingerSoft\AggridBundle\Column\Column;
use StingerSoft\AggridBundle\Column\ColumnGroupType;
use StingerSoft\AggridBundle\Column\ColumnInterface;
use StingerSoft\AggridBundle\Column\ColumnTypeInterface;
use StingerSoft\AggridBundle\Exception\InvalidArgumentTypeException;
use StingerSoft\AggridBundle\Grid\Grid;
use StingerSoft\AggridBundle\Service\DependencyInjectionExtensionInterface;
use StingerSoft\AggridBundle\View\ColumnView;
use Traversable;

class GridBuilder implements IteratorAggregate, GridBuilderInterface {

	/**
	 * @var ColumnInterface[] Array of all column settings
	 */
	protected $columns;

	/**
	 * @var ColumnInterface[] Array of all column settings
	 */
	protected $groupColumns;

	/**
	 * @var Grid the grid this builder is used for
	 */
	protected $grid;

	/**
	 * @var array the options for the grid
	 */
	protected $gridOptions;

	/**
	 * @var DependencyInjectionExtensionInterface
	 */
	protected $dependencyInjectionExtension;

	public function __construct(Grid $grid, DependencyInjectionExtensionInterface $dependencyInjectionExtension, array $gridOptions = []) {
		$this->grid = $grid;
		$this->gridOptions = $gridOptions;
		$this->dependencyInjectionExtension = $dependencyInjectionExtension;
		$this->columns = [];
		$this->groupColumns = [];
	}

	/**
	 * {@inheritdoc}
	 */
	public function add($column, ?string $type = null, array $options = []): GridBuilderInterface {
		$this->addColumn($column, $type, $options);
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function addColumn($column, ?string $type = null, array $options = []): ColumnInterface {
		if(!$column instanceof ColumnInterface) {
			$column = $this->createColumn($column, $type, $options);
			if($column->getColumnType() instanceof ColumnGroupType) {
				return $this->addGroupColumn($column, $type, $options);
			}
		}
		$this->columns[$column->getPath()] = $column;
		return $column;
	}

	/**
	 * {@inheritdoc}
	 */
	public function addGroup($column, ?string $type = null, array $options = []): GridBuilderInterface {
		$this->addGroupColumn($column, $type, $options);
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function addGroupColumn($column, ?string $type = null, array $options = []): ColumnInterface {
		if(!$column instanceof ColumnInterface) {
			$column = $this->createColumn($column, $type, $options);
		}
		$columnOptions = $column->getColumnOptions();
		/** @var ColumnInterface[] $children */
		$children = $columnOptions['children'] ?? [];
		foreach($children as $child) {
			$child->setParent($column);
		}
		$this->columns[$column->getPath()] = $column;
		return $column;
	}

	/**
	 * @param ColumnInterface|string $column
	 * @param string|null            $type
	 * @param array                  $options
	 * @return ColumnInterface
	 * @throws InvalidArgumentTypeException
	 */
	protected function createColumn($column, ?string $type = null, array $options = []): ColumnInterface {
		$typeInstance = null;
		try {
			$typeInstance = $this->getColumnTypeInstance($type);
			return new Column($column, $typeInstance, $this->dependencyInjectionExtension, $options, $this->gridOptions, $this->grid->getQueryBuilder());
		} catch(ReflectionException $re) {
			throw new InvalidArgumentTypeException('If the column parameter is no instance of the interface ' . ColumnInterface::class . ' you must specify a valid classname for the type to be used! ' . $type . ' given', null, $re);
		}
	}

	/**
	 * Returns whether a column with the given path exists (implements the \ArrayAccess interface).
	 *
	 * @param string $path The path of the column
	 * @return bool
	 */
	public function offsetExists($path): bool {
		return $this->has($path);
	}

	/**
	 * Returns the column with the given path (implements the \ArrayAccess interface).
	 *
	 * @param string $path The path of the column
	 * @return ColumnInterface The column
	 * @throws OutOfBoundsException If the named column does not exist.
	 */
	public function offsetGet($path): ColumnInterface {
		return $this->get($path);
	}

	/**
	 * Adds a column to the grid (implements the \ArrayAccess interface).
	 *
	 * @param string          $path   Ignored. The path of the column is used
	 * @param ColumnInterface $column The column to be added
	 * @throws InvalidArgumentTypeException
	 * @see self::add()
	 */
	public function offsetSet($path, $column): void {
		$this->add($column);
	}

	/**
	 * Removes the column with the given path from the grid (implements the \ArrayAccess interface).
	 *
	 * @param string $path The path of the column to remove
	 */
	public function offsetUnset($path): void {
		$this->remove($path);
	}

	/**
	 * Returns the iterator for the columns.
	 *
	 * @return Traversable|Column[]
	 */
	public function getIterator() {
		return $this->columns;
	}

	/**
	 * Returns the number of columns (implements the \Countable interface).
	 *
	 * @return int The number of columns
	 */
	public function count(): int {
		return count($this->columns);
	}

	/**
	 * @inheritdoc
	 */
	public function get(string $path): ColumnInterface {
		if(isset($this->columns[$path])) {
			return $this->columns[$path];
		}

		throw new OutOfBoundsException(sprintf('Column "%s" does not exist.', $path));
	}

	/**
	 * @inheritdoc
	 */
	public function has(string $path): bool {
		return isset($this->columns[$path]);
	}

	/**
	 * Removes a column from the grid.
	 *
	 * @param string $path The path of the column to remove
	 * @return $this
	 */
	public function remove(string $path): GridBuilderInterface {
		if(isset($this->columns[$path])) {
			unset($this->columns[$path]);
		}
		return $this;
	}

	/**
	 * Returns all columns in this grid.
	 *
	 * @return Column[]
	 */
	public function all(): array {
		return $this->columns;
	}

	public function getGroupColumns(): array {
		return $this->groupColumns;
	}

	/**
	 * Creates an instance of the given column type class.
	 *
	 * @param string $class
	 *            Classname of the column type
	 * @return ColumnTypeInterface
	 * @throws InvalidArgumentException
	 */
	private function getColumnTypeInstance($class): ColumnTypeInterface {
		if($class === null) {
			throw new InvalidArgumentException('Paramater class may not be null!');
		}
		return $this->dependencyInjectionExtension->resolveColumnType($class);
	}
}